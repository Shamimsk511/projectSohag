<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsHubTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTS = [
        'admin.reports.daily-collection', 'admin.reports.daily-cash-book', 'admin.reports.cash-reconciliation',
        'admin.reports.monthly', 'admin.reports.position', 'admin.reports.outstanding', 'admin.reports.loan-register',
        'admin.reports.missed-partial', 'admin.reports.withdrawal-register', 'admin.reports.officer-performance',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_hub_links_every_report(): void
    {
        $response = $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee(__('app.reports.hub.title'));

        foreach (self::REPORTS as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }
    }

    public function test_field_officer_cannot_open_the_hub(): void
    {
        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->get(route('admin.reports.index'))
            ->assertForbidden();
    }

    public function test_sidebar_has_six_groups_and_reports_live_in_the_hub(): void
    {
        $html = $this->actingAs(User::factory()->withRole(Role::Admin)->create())
            ->get(route('admin.dashboard'))->assertOk()->getContent();
        $sidebar = $this->sidebar($html);

        $this->assertSame(6, preg_match_all('~<li\s+class="nav-item~', $this->topLevel($sidebar)));
        foreach (['app.dashboard', 'app.parties.title', 'app.menu.collections', 'app.menu.money', 'app.menu.reports', 'app.menu.settings'] as $key) {
            $this->assertStringContainsString(__($key), $sidebar);
        }
        $this->assertStringContainsString('href="'.route('admin.reports.index').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('admin.reports.daily-collection').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('admin.reports.loan-register').'"', $sidebar);
    }

    public function test_accountant_sees_only_permitted_menu_items(): void
    {
        $sidebar = $this->sidebar($this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('href="'.route('admin.reports.index').'"', $sidebar);
        $this->assertStringContainsString('href="'.route('admin.office-heads.index').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('admin.users.index').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('admin.opening-cash.index').'"', $sidebar);
    }

    private function sidebar(string $html): string
    {
        $start = strpos($html, 'id="adminlte-sidebar-menu"');
        $this->assertNotFalse($start);

        return substr($html, $start, (int) strpos($html, '</nav>', $start) - $start);
    }

    /**
     * The sidebar with every submenu list removed, so only top-level items remain.
     */
    private function topLevel(string $sidebar): string
    {
        return (string) preg_replace('~<ul class="nav nav-treeview">.*?</ul>~s', '', $sidebar);
    }
}
