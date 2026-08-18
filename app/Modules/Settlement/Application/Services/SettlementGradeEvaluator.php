<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Data\SettlementAgentData;
use App\Modules\Settlement\Infrastructure\Models\AgentGradeEvaluation;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use Carbon\CarbonImmutable;

final readonly class SettlementGradeEvaluator
{
    /**
     * Evaluate one period and replace only the suggestion belonging to that period.
     * Re-running a settlement updates the same evaluation row instead of counting twice.
     */
    public function evaluate(Settlement $settlement, SettlementAgentData $current, SettlementAgentData $recommended, int $commissionKrw): AgentGradeEvaluation
    {
        $direction = $this->direction($current, $recommended);
        $period = CarbonImmutable::parse($settlement->period_end)->toDateString();
        $failureCount = 0;
        if ($direction < 0) {
            $failureCount = 1;
            $previous = AgentGradeEvaluation::query()
                ->where('agent_id', $settlement->agent_id)
                ->whereDate('period', '<', $period)
                ->latest('period')
                ->get();
            foreach ($previous as $evaluation) {
                if ($evaluation->result !== 'downgrade_failure') {
                    break;
                }
                $failureCount++;
            }
        }

        $result = $direction > 0 ? 'upgrade' : ($direction < 0 ? 'downgrade_failure' : 'maintained');
        $evaluation = AgentGradeEvaluation::query()->updateOrCreate(
            ['agent_id' => $settlement->agent_id, 'period' => $period],
            [
                'settlement_id' => $settlement->id,
                'current_grade_id' => $current->currentGradeId,
                'evaluated_grade_id' => $recommended->currentGradeId,
                'result' => $result,
                'consecutive_failure_count' => $failureCount,
            ],
        );

        SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->delete();
        if ($direction > 0 || ($direction < 0 && $failureCount >= 2)) {
            SettlementGradeSuggestion::query()->create([
                'settlement_id' => $settlement->id,
                'agent_id' => $settlement->agent_id,
                'current_grade_id' => $current->currentGradeId,
                'recommended_grade_id' => $recommended->currentGradeId,
                'monthly_commission_krw' => $commissionKrw,
                'status' => 'pending',
            ]);
        }

        return $evaluation;
    }

    private function direction(SettlementAgentData $current, SettlementAgentData $recommended): int
    {
        if ($current->currentGradeThresholdKrw !== 0 || $recommended->currentGradeThresholdKrw !== 0) {
            return $recommended->currentGradeThresholdKrw <=> $current->currentGradeThresholdKrw;
        }

        return $recommended->currentGradeId <=> $current->currentGradeId;
    }
}
