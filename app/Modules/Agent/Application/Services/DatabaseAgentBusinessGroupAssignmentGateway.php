<?php

namespace App\Modules\Agent\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\BusinessGroupReferenceReader;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseAgentBusinessGroupAssignmentGateway implements AgentBusinessGroupAssignmentGateway
{
    public function __construct(
        private BusinessGroupReferenceReader $groups,
        private AuditRecorder $audit,
        private BusinessClock $clock,
    ) {}

    /** @return array<int, array{id: int, code: string, name: string, status: string}> */
    public function agents(): array
    {
        return Agent::query()
            ->orderByDesc('cooperation_status')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'cooperation_status'])
            ->map(fn (Agent $agent): array => [
                'id' => (int) $agent->id,
                'code' => (string) $agent->code,
                'name' => (string) $agent->name,
                'status' => (string) $agent->cooperation_status,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function assignments(): array
    {
        $groups = collect($this->groups->businessGroups())->keyBy('id');

        return AgentBusinessGroupAssignment::query()
            ->with('agent')
            ->orderByDesc('effective_from')
            ->orderBy('id')
            ->get()
            ->map(fn (AgentBusinessGroupAssignment $assignment): array => [
                'id' => (int) $assignment->id,
                'agent_id' => (int) $assignment->agent_id,
                'agent_code' => (string) ($assignment->agent->code ?? ''),
                'agent_name' => (string) ($assignment->agent->name ?? ''),
                'business_group_id' => (int) $assignment->business_group_id,
                'group_code' => (string) ($groups[(int) $assignment->business_group_id]['code'] ?? ''),
                'group_name' => (string) ($groups[(int) $assignment->business_group_id]['name'] ?? ''),
                'effective_from' => $assignment->effective_from->format('Y-m-d'),
                'effective_until' => $assignment->effective_until?->format('Y-m-d'),
                'reason' => (string) $assignment->reason,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function unassignedAgents(?string $onDate = null): array
    {
        $date = $this->parseDate($onDate ?? $this->clock->now()->toDateString())->toDateString();

        return Agent::query()
            ->where('cooperation_status', 'active')
            ->whereNotExists(function ($query) use ($date): void {
                $query->selectRaw('1')
                    ->from('agent_business_group_assignments')
                    ->whereColumn('agent_business_group_assignments.agent_id', 'agents.id')
                    ->whereDate('effective_from', '<=', $date)
                    ->where(function ($range) use ($date): void {
                        $range->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
                    });
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Agent $agent): array => [
                'id' => (int) $agent->id,
                'code' => (string) $agent->code,
                'name' => (string) $agent->name,
            ])
            ->all();
    }

    public function assign(
        int $agentId,
        int $businessGroupId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void {
        $from = $this->parseDate($effectiveFrom);
        $until = $effectiveUntil === null || trim($effectiveUntil) === '' ? null : $this->parseDate($effectiveUntil);
        $reason = trim($reason);
        if ($until !== null && $until->lt($from)) {
            throw new DomainException(__('agents.validation.business_group_date_order_invalid'));
        }
        if ($reason === '') {
            throw new DomainException(__('agents.validation.business_group_reason_required'));
        }

        try {
            DB::transaction(function () use ($agentId, $businessGroupId, $from, $until, $reason, $actorId, $ipAddress): void {
                if (! $this->groups->exists($businessGroupId)) {
                    throw new DomainException(__('agents.validation.business_group_inactive'));
                }
                $agent = Agent::query()->lockForUpdate()->findOrFail($agentId);
                $overlap = AgentBusinessGroupAssignment::query()
                    ->where('agent_id', $agent->id)
                    ->whereDate('effective_from', '<=', $until?->toDateString() ?? '9999-12-31')
                    ->where(function ($query) use ($from): void {
                        $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from->toDateString());
                    })
                    ->lockForUpdate()
                    ->exists();
                if ($overlap) {
                    throw new DomainException(__('agents.validation.business_group_assignment_overlap'));
                }

                $assignment = AgentBusinessGroupAssignment::query()->create([
                    'agent_id' => $agent->id,
                    'business_group_id' => $businessGroupId,
                    'effective_from' => $from->toDateString(),
                    'effective_until' => $until?->toDateString(),
                    'assigned_by' => $actorId,
                    'reason' => $reason,
                ]);
                $this->audit->record(
                    description: __('agents.audit.business_group_assignment_created'),
                    properties: [
                        'agent_id' => $agent->id,
                        'business_group_id' => $businessGroupId,
                        'effective_from' => $from->toDateString(),
                        'effective_until' => $until?->toDateString(),
                        'reason' => $reason,
                    ],
                    causerId: $actorId,
                    subject: $assignment,
                    logName: 'agent-business-groups',
                    event: 'assigned',
                    ipAddress: $ipAddress,
                );
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01'
                && str_contains($exception->getMessage(), 'agent_business_group_assignments_overlap_exclude')) {
                throw new DomainException(__('agents.validation.business_group_assignment_overlap'), previous: $exception);
            }

            throw $exception;
        }
    }

    public function endAssignment(int $assignmentId, string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void
    {
        $until = $this->parseDate($effectiveUntil);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('agents.validation.business_group_reason_required'));
        }

        DB::transaction(function () use ($assignmentId, $until, $reason, $actorId, $ipAddress): void {
            $assignment = AgentBusinessGroupAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if ($until->lt($assignment->effective_from)) {
                throw new DomainException(__('agents.validation.business_group_date_order_invalid'));
            }
            $assignment->update(['effective_until' => $until->toDateString()]);
            $this->audit->record(
                description: __('agents.audit.business_group_assignment_ended'),
                properties: ['assignment_id' => $assignment->id, 'effective_until' => $until->toDateString(), 'reason' => $reason],
                causerId: $actorId,
                subject: $assignment,
                logName: 'agent-business-groups',
                event: 'ended',
                ipAddress: $ipAddress,
            );
        });
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $value = trim($value);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException(__('agents.validation.business_group_date_invalid'));
        }

        return $date;
    }
}
