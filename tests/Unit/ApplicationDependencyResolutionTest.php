<?php

namespace Tests\Unit;

use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Config\Application\Services\DatabaseNotificationRecipientGateway;
use App\Modules\Reminder\Application\Services\ReminderNotifier;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use Tests\TestCase;

final class ApplicationDependencyResolutionTest extends TestCase
{
    public function test_queue_dependencies_can_be_resolved(): void
    {
        $this->assertInstanceOf(DatabaseNotificationRecipientGateway::class, app(NotificationRecipientGateway::class));
        $this->assertInstanceOf(SettlementGenerator::class, app(SettlementGenerator::class));
        $this->assertInstanceOf(ReminderNotifier::class, app(ReminderNotifier::class));
    }
}
