<?php

namespace Tests\Feature;

use App\Enums\CashHandoverMode;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cash_handover_mode_defaults_to_manual(): void
    {
        $this->assertSame(CashHandoverMode::Manual, app(SettingService::class)->cashHandoverMode());
        $this->assertTrue(app(SettingService::class)->autoCloseDays());
    }

    public function test_admin_switches_the_cash_handover_mode_and_the_change_is_audited(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();

        $this->actingAs($admin)->put(route('admin.settings.update'), ['cash_handover_mode' => 'automatic', 'auto_close_days' => '0'])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('status');

        $this->assertSame(CashHandoverMode::Automatic, app(SettingService::class)->cashHandoverMode());
        $this->assertFalse(app(SettingService::class)->autoCloseDays());

        $this->actingAs($admin)->put(route('admin.settings.update'), ['cash_handover_mode' => 'manual', 'auto_close_days' => '0']);

        $this->assertSame(CashHandoverMode::Manual, app(SettingService::class)->cashHandoverMode());
        $this->assertSame(2, AuditLog::query()->where('action', 'setting.created')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'setting.updated')->count());
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();

        $this->actingAs($admin)->put(route('admin.settings.update'), ['cash_handover_mode' => 'sometimes', 'auto_close_days' => '1'])
            ->assertSessionHasErrors('cash_handover_mode');
    }

    public function test_money_pages_sit_in_a_money_menu_and_settings_in_a_settings_menu(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Admin)->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('nav-indent', false)
            ->assertSeeInOrder([__('app.menu.money'), __('app.reports.position.title'), __('app.cash.title'), __('app.office_entries.title'), __('app.closings.title')])
            ->assertSeeInOrder([__('app.menu.settings'), __('app.areas.title'), __('app.holidays.title'), __('app.office_heads.title'), __('app.users.title'), __('app.opening_cash.title'), __('app.settings.title')]);
    }

    public function test_accountant_sees_the_money_menu_and_only_office_heads_under_settings(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(__('app.menu.money'))
            ->assertSeeInOrder([__('app.menu.settings'), __('app.office_heads.title')])
            ->assertDontSee(__('app.users.title'))
            ->assertDontSee(__('app.settings.title'));
    }
}
