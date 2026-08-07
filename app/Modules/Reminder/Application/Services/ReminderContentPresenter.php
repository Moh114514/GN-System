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
        $systemKey = $template->system_key;
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

    /** @return array{title: string, suggestion: string|null} */
    public function reminder(Reminder $reminder): array
    {
        $content = is_array($reminder->localized_content) ? $reminder->localized_content : [];

        return [
            'title' => $this->contentField($content['title'] ?? null, (string) $reminder->title),
            'suggestion' => $reminder->suggestion === null
                ? null
                : $this->contentField($content['suggestion'] ?? null, (string) $reminder->suggestion),
        ];
    }

    public function applyToReminder(Reminder $reminder): Reminder
    {
        $content = $this->reminder($reminder);
        $reminder->setAttribute('title', $content['title']);
        $reminder->setAttribute('suggestion', $content['suggestion']);

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
        if (! is_array($definition)) {
            return $fallback;
        }
        $key = $definition['key'] ?? null;
        $parameters = $definition['parameters'] ?? [];
        if (! is_string($key) || ! Lang::has($key)) {
            return $fallback;
        }

        return (string) __($key, is_array($parameters) ? $parameters : []);
    }
}
