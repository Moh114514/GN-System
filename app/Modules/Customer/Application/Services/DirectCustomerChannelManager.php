<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Infrastructure\Models\DirectCustomerChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class DirectCustomerChannelManager
{
    public function __construct(private AccessContextResolver $access, private AuditRecorder $audit) {}

    /** @return list<array<string, mixed>> */
    public function channels(): array
    {
        $this->actorId();

        return DirectCustomerChannel::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DirectCustomerChannel $channel): array => $channel->toArray())->values()->all();
    }

    /** @return array<string, mixed> */
    public function channel(int $id): array
    {
        $this->actorId();

        return DirectCustomerChannel::query()->findOrFail($id)->toArray();
    }

    public function save(?int $id, string $code, string $name, int $sortOrder, ?string $ipAddress): void
    {
        $actorId = $this->actorId();
        DB::transaction(function () use ($id, $code, $name, $sortOrder, $ipAddress, $actorId): void {
            $channel = $id === null
                ? new DirectCustomerChannel(['is_active' => true])
                : DirectCustomerChannel::query()->lockForUpdate()->findOrFail($id);
            if ($channel->exists && $channel->code !== $code) {
                throw ValidationException::withMessages(['code' => __('config.direct_customer.channels.code_immutable')]);
            }
            Validator::make(
                ['code' => $code, 'name' => $name, 'sortOrder' => $sortOrder],
                [
                    'code' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/D', Rule::unique('direct_customer_channels', 'code')->ignore($id)],
                    'name' => ['required', 'string', 'max:255'],
                    'sortOrder' => ['required', 'integer', 'between:0,32767'],
                ],
            )->validate();
            $before = $channel->exists ? $channel->getAttributes() : null;
            $channel->fill(['code' => $code, 'name' => $name, 'sort_order' => $sortOrder])->save();
            $this->audit->record(
                description: __('config.direct_customer.channels.audit_saved'),
                properties: ['before' => $before, 'after' => $channel->getAttributes()],
                causerId: $actorId,
                subject: $channel,
                logName: 'direct-customer-channel',
                event: 'saved',
                ipAddress: $ipAddress,
                messageKey: 'config.direct_customer.channels.audit_saved',
            );
        });
    }

    public function toggle(int $id, ?string $ipAddress): void
    {
        $actorId = $this->actorId();
        DB::transaction(function () use ($id, $ipAddress, $actorId): void {
            $channel = DirectCustomerChannel::query()->lockForUpdate()->findOrFail($id);
            $before = $channel->getAttributes();
            $channel->update(['is_active' => ! $channel->is_active]);
            $this->audit->record(
                description: __('config.direct_customer.channels.audit_status_changed'),
                properties: ['before' => $before, 'after' => $channel->getAttributes()],
                causerId: $actorId,
                subject: $channel,
                logName: 'direct-customer-channel',
                event: 'status_changed',
                ipAddress: $ipAddress,
                messageKey: 'config.direct_customer.channels.audit_status_changed',
            );
        });
    }

    private function actorId(): int
    {
        $context = $this->access->current();
        abort_unless($context->isSuperAdmin() && $context->userId !== null, 403);

        return $context->userId;
    }
}
