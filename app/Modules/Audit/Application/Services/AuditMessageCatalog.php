<?php

namespace App\Modules\Audit\Application\Services;

use Illuminate\Support\Facades\Lang;

final class AuditMessageCatalog
{
    /** @var array<int, string> */
    private const TRANSLATION_KEYS = [
        'audit.messages.agent_created',
        'audit.messages.agent_updated',
        'audit.messages.agent_config_rolled_back',
        'audit.messages.agent_type_saved',
        'audit.messages.agent_type_status_changed',
        'audit.messages.agent_policy_saved',
        'audit.messages.agent_policy_status_changed',
        'audit.messages.agent_grade_saved',
        'audit.messages.agent_grade_status_changed',
        'audit.messages.internal_user_created',
        'audit.messages.internal_user_invited',
        'audit.messages.internal_user_role_changed',
        'audit.messages.internal_user_enabled',
        'audit.messages.internal_user_disabled',
        'audit.messages.admin_enabled',
        'audit.messages.admin_disabled',
        'audit.messages.admin_password_reset',
        'audit.messages.institution_saved',
        'audit.messages.institution_status_changed',
        'audit.messages.institution_deleted',
        'audit.messages.dictionary_item_saved',
        'audit.messages.dictionary_item_status_changed',
        'audit.messages.system_parameter_updated',
        'audit.messages.followup_created',
        'audit.messages.customer_created',
        'audit.messages.customer_updated',
        'audit.messages.customer_status_changed',
        'audit.messages.customer_status_config_updated',
        'audit.messages.customer_config_rolled_back',
        'audit.messages.source_saved',
        'audit.messages.source_status_changed',
        'audit.messages.historical_import_completed',
        'audit.messages.historical_import_rolled_back',
        'audit.messages.import_row_adjudicated',
        'audit.messages.reference_import_completed',
        'audit.messages.reminder_rule_saved',
        'audit.messages.reminder_rule_status_changed',
        'audit.messages.commission_override_saved',
        'audit.messages.commission_override_expired',
        'audit.messages.commission_rate_saved',
        'audit.messages.settlement_config_rolled_back',
        'audit.messages.commission_calculated',
        'audit.messages.settlement_period_saved',
        'audit.messages.uat_reset_failed',
        'orders.audit.created',
        'orders.audit.completed',
        'orders.audit.updated',
        'orders.audit.cancelled',
        'orders.audit.reopened',
        'orders.audit.rolled_back',
        'orders.audit.soft_deleted',
        'orders.audit.restored',
        'settlements.audit.rejected',
        'settlements.audit.approved',
        'settlements.audit.settled',
        'settlements.audit.status_corrected',
        'settlements.audit.generation_recovered',
        'settlements.audit.recovery_batch_created',
    ];

    /** @var array<string, string>|null */
    private ?array $legacyMap = null;

    public function keyFor(string $description): ?string
    {
        $this->legacyMap ??= $this->buildLegacyMap();

        return $this->legacyMap[$description] ?? null;
    }

    /** @return array<string, string> */
    private function buildLegacyMap(): array
    {
        $map = [
            'Super administrator enabled' => 'audit.messages.admin_enabled',
            'Super administrator disabled' => 'audit.messages.admin_disabled',
            'Super administrator password reset' => 'audit.messages.admin_password_reset',
        ];

        foreach (self::TRANSLATION_KEYS as $key) {
            $value = Lang::get($key, [], 'zh_CN');
            if (is_string($value)) {
                $map[$value] = $key;
            }
        }

        return $map;
    }
}
