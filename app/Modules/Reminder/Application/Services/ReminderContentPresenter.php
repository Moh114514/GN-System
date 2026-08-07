<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use Illuminate\Support\Facades\Lang;

final class ReminderContentPresenter
{
    /** @return array{name: string, title: string, suggestion: string|null} */
    public function template(ReminderTemplate $template): array
    {
        $systemKey = is_string($template->system_key) && $template->system_key !== ''
            ? $template->system_key
            : ($template->is_system ? $this->identifySystemTemplate($template) : null);
        if (! $template->is_system || ! is_string($systemKey) || $systemKey === '') {
            return [
                'name' => (string) $template->name,
                'title' => (string) $template->title,
                'suggestion' => $template->suggestion,
            ];
        }

        return [
            'name' => $this->systemTemplateField($systemKey, 'name', (string) $template->name),
            'title' => $this->systemTemplateField($systemKey, 'title', (string) $template->title),
            'suggestion' => $template->suggestion === null
                ? null
                : $this->systemTemplateField($systemKey, 'suggestion', (string) $template->suggestion),
        ];
    }

    /** @return array{title: string, suggestion: string|null, notes: string|null} */
    public function reminder(Reminder $reminder): array
    {
        $content = is_array($reminder->localized_content) ? $reminder->localized_content : [];

        return [
            'title' => $this->contentField($content['title'] ?? null, (string) $reminder->title),
            'suggestion' => $reminder->suggestion === null
                ? null
                : $this->contentField($content['suggestion'] ?? null, (string) $reminder->suggestion),
            'notes' => $reminder->notes === null
                ? null
                : $this->contentField($content['notes'] ?? null, (string) $reminder->notes),
        ];
    }

    public function applyToReminder(Reminder $reminder): Reminder
    {
        $content = $this->reminder($reminder);
        $reminder->setAttribute('title', $content['title']);
        $reminder->setAttribute('suggestion', $content['suggestion']);
        $reminder->setAttribute('notes', $content['notes']);

        return $reminder;
    }

    private function systemTemplateField(string $systemKey, string $field, string $stored): string
    {
        $key = "reminders.system_templates.{$systemKey}.{$field}";
        if (! Lang::has($key)) {
            return $stored;
        }

        $knownValues = array_filter([
            Lang::get($key, [], 'zh_CN'),
            Lang::get($key, [], 'ko_KR'),
        ], 'is_string');

        return in_array($stored, $knownValues, true) ? (string) __($key) : $stored;
    }

    private function contentField(mixed $definition, string $fallback): string
    {
        if (is_string($definition)) {
            return $definition;
        }
        if (! is_array($definition)) {
            return $fallback;
        }
        if (array_is_list($definition)) {
            $parts = array_map(fn (mixed $item): string => $this->contentField($item, ''), $definition);
            $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

            return $parts === [] ? $fallback : implode("\n", $parts);
        }
        $key = $definition['key'] ?? null;
        $parameters = $definition['parameters'] ?? [];
        if (! is_string($key) || ! Lang::has($key)) {
            return $fallback;
        }

        return (string) __($key, is_array($parameters) ? $parameters : []);
    }

    private function identifySystemTemplate(ReminderTemplate $template): ?string
    {
        foreach ($this->systemTemplateDefinitions() as $key => $definition) {
            foreach (['name', 'title', 'suggestion'] as $field) {
                $values = [
                    (string) Lang::get("reminders.system_templates.{$key}.{$field}", [], 'zh_CN'),
                    (string) Lang::get("reminders.system_templates.{$key}.{$field}", [], 'ko_KR'),
                ];
                if (in_array((string) $template->{$field}, $values, true)) {
                    return $key;
                }
            }
            if ($template->default_trigger_type === $definition['type']
                && $template->default_trigger_config == $definition['config']) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, array{type: string, config: array<string, mixed>}> */
    private function systemTemplateDefinitions(): array
    {
        return [
            'pre_visit_confirmation' => ['type' => 'date_offset', 'config' => ['field' => 'appointment_at', 'offset_days' => -3, 'time' => '09:00']],
            'arrival_reception' => ['type' => 'date_offset', 'config' => ['field' => 'appointment_at', 'offset_days' => -1, 'time' => '18:00']],
            'post_treatment_1' => ['type' => 'date_offset', 'config' => ['field' => 'completed_on', 'offset_days' => 1, 'time' => '09:00']],
            'post_treatment_7' => ['type' => 'date_offset', 'config' => ['field' => 'completed_on', 'offset_days' => 7, 'time' => '09:00']],
            'post_treatment_30' => ['type' => 'date_offset', 'config' => ['field' => 'completed_on', 'offset_days' => 30, 'time' => '09:00']],
            'post_treatment_90' => ['type' => 'date_offset', 'config' => ['field' => 'completed_on', 'offset_days' => 90, 'time' => '09:00']],
            'post_treatment_180' => ['type' => 'date_offset', 'config' => ['field' => 'completed_on', 'offset_days' => 180, 'time' => '09:00']],
            'birthday' => ['type' => 'date_offset', 'config' => ['field' => 'birth_date', 'offset_days' => 0, 'time' => '09:00']],
            'holiday' => ['type' => 'manual', 'config' => []],
            'existing_customer' => ['type' => 'fixed_cycle', 'config' => ['interval_days' => 90, 'time' => '09:00']],
            'dormant_customer' => ['type' => 'fixed_cycle', 'config' => ['interval_days' => 180, 'time' => '09:00']],
            'repurchase' => ['type' => 'fixed_cycle', 'config' => ['interval_days' => 180, 'time' => '09:00']],
        ];
    }
}
