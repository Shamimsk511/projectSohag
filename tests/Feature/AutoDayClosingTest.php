<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\DailyClosing;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutoDayClosingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 18:23:00'); // 00:23 on 13 Oct in Dhaka
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return list<string>
     */
    private function closedDates(): array
    {
        return DailyClosing::query()->orderBy('date')->get()
            ->map(fn (DailyClosing $closing): string => $closing->date->toDateString())->all();
    }

    public function test_closes_every_open_day_up_to_the_day_before_yesterday_so_offline_sync_still_lands(): void
    {
        Transaction::factory()->create(['transaction_date' => '2026-10-08']);
        DailyClosing::factory()->create(['date' => '2026-10-09']);

        $this->artisan('days:close')->assertSuccessful();

        // 12 Oct (yesterday) stays open: officers may still sync collections dated on it today.
        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'], $this->closedDates());
        $this->assertNull(DailyClosing::query()->where('date', '>=', '2026-10-10')->where('date', '<', '2026-10-11')->value('closed_by'));
        $this->assertSame(3, AuditLog::query()->where('action', 'day.closed')->whereNull('user_id')->count());

        $this->artisan('days:close')->assertSuccessful();
        $this->assertCount(4, $this->closedDates());
    }

    public function test_with_no_history_only_the_day_before_yesterday_is_closed(): void
    {
        $this->artisan('days:close')->assertSuccessful();

        $this->assertSame(['2026-10-11'], $this->closedDates());
    }

    public function test_does_nothing_when_automatic_closing_is_off(): void
    {
        app(SettingService::class)->setAutoCloseDays(false, User::factory()->withRole(Role::Admin)->create());

        $this->artisan('days:close')->assertSuccessful();

        $this->assertSame([], $this->closedDates());
    }

    public function test_system_closings_show_on_the_closing_page(): void
    {
        $this->artisan('days:close')->assertSuccessful();

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.daily-closings.index'))
            ->assertOk()
            ->assertSee(__('app.closings.system'))
            ->assertSee(__('app.closings.auto_on'));
    }
}
