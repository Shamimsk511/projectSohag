<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ErrorMonitoringTest extends TestCase
{
    public function test_a_failing_scheduled_command_is_logged_as_critical(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertCount(4, $events);

        Log::shouldReceive('critical')->times(4)->with('Scheduled command failed', \Mockery::on(
            fn (array $context): bool => str_contains($context['command'], ':'),
        ));

        $events->each(fn (Event $event) => $event->finish(app(), 1));
    }

    public function test_successful_scheduled_command_logs_nothing(): void
    {
        Log::shouldReceive('critical')->never();

        collect(app(Schedule::class)->events())->each(fn (Event $event) => $event->finish(app(), 0));
    }

    public function test_slack_channel_alerts_on_errors_not_debug_noise(): void
    {
        $this->assertSame('error', config('logging.channels.slack.level'));
    }
}
