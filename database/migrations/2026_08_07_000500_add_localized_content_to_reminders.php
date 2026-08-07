<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->string('system_key', 64)->nullable();
        });
        Schema::table('reminders', function (Blueprint $table): void {
            $table->jsonb('localized_content')->nullable();
        });

        $templateKeys = [
            'pre_visit_confirmation' => '术前确认',
            'arrival_reception' => '到院接待',
            'post_treatment_1' => '术后 1 天',
            'post_treatment_7' => '术后 7 天',
            'post_treatment_30' => '术后 30 天',
            'post_treatment_90' => '术后 90 天',
            'post_treatment_180' => '术后 180 天',
            'birthday' => '生日问候',
            'holiday' => '节日关怀',
            'existing_customer' => '老客户回访',
            'dormant_customer' => '沉默唤醒',
            'repurchase' => '复购提醒',
        ];
        foreach ($templateKeys as $key => $name) {
            $templateId = DB::table('reminder_templates')
                ->where('is_system', true)
                ->where('name', $name)
                ->whereNull('system_key')
                ->orderBy('id')
                ->value('id');
            if ($templateId !== null) {
                DB::table('reminder_templates')->where('id', $templateId)->update(['system_key' => $key]);
            }
        }

        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->unique('system_key');
        });

        $appointmentContent = [
            '术前 3 天确认' => 'pre_visit_3_days',
            '到店前一天确认' => 'arrival_previous_day',
            '今日到店接待确认' => 'arrival_today',
        ];
        foreach ($appointmentContent as $title => $key) {
            DB::table('reminders')
                ->where('source_type', 'system')
                ->where('reminder_type', 'appointment')
                ->where('title', $title)
                ->whereNull('localized_content')
                ->update(['localized_content' => json_encode([
                    'title' => ['key' => "reminders.system_reminders.{$key}.title", 'parameters' => []],
                    'suggestion' => ['key' => "reminders.system_reminders.{$key}.suggestion", 'parameters' => []],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }

        DB::table('reminders')
            ->where('source_type', 'system')
            ->where('reminder_type', 'post_treatment')
            ->orderBy('id')
            ->eachById(function (object $reminder): void {
                if (preg_match('/^\x{672f}\x{540e}\x{7b2c} (\d+) \x{5929}\x{8ddf}\x{8fdb}$/u', (string) $reminder->title, $matches) !== 1) {
                    return;
                }
                $days = (int) $matches[1];
                if (! in_array($days, [1, 7, 30, 90, 180], true)) {
                    return;
                }
                $localizedContent = json_decode((string) $reminder->localized_content, true);
                $localizedContent = is_array($localizedContent) ? $localizedContent : [];
                $localizedContent['title'] ??= [
                    'key' => 'reminders.system_reminders.post_treatment.title',
                    'parameters' => ['days' => $days],
                ];
                $localizedContent['suggestion'] ??= [
                    'key' => "reminders.system_reminders.post_treatment.suggestions.{$days}",
                    'parameters' => [],
                ];
                if (preg_match('/^\x{9879}\x{76ee}\x{ff1a}([^\r\n]+)(?:\r?\n|$)/u', trim((string) $reminder->notes), $noteMatches) === 1) {
                    $localizedContent['notes'] ??= [[
                        'key' => 'reminders.system_reminders.post_treatment.project',
                        'parameters' => ['project' => trim($noteMatches[1])],
                    ]];
                }
                DB::table('reminders')->where('id', $reminder->id)->update([
                    'localized_content' => json_encode($localizedContent, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn('localized_content');
        });
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
        });
    }
};
