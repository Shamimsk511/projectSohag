<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use JeroenNoten\LaravelAdminLte\Events\BuildingMenu;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole(Role::Admin->value) ? true : null);

        // Lazy loading throws outside production, so an N+1 shows up in tests instead of on a slow phone.
        Model::preventLazyLoading(! $this->app->isProduction());

        Paginator::useBootstrapFive();

        // Items with app-translated labels; config/adminlte.php can only use the package's menu strings.
        Event::listen(BuildingMenu::class, function (BuildingMenu $event): void {
            $event->menu->add([
                'text' => __('app.dashboard'),
                'route' => 'admin.dashboard',
                'active' => ['admin'],
                'icon' => 'bi bi-speedometer2',
            ], [
                'text' => __('app.parties.title'),
                'route' => 'admin.parties.index',
                'active' => ['admin/parties*'],
                'icon' => 'bi bi-person-vcard',
                'can' => [Permission::ViewAllParties->value, Permission::ManageParties->value],
            ], [
                'text' => __('app.schedules.title'),
                'route' => 'admin.schedules.index',
                'active' => ['admin/schedules*'],
                'icon' => 'bi bi-calendar-check',
                'can' => [Permission::ManageSchedules->value, Permission::ViewAllReports->value],
            ], [
                'text' => __('app.office_entries.title'),
                'route' => 'admin.office-entries.index',
                'icon' => 'bi bi-cash-coin',
                'can' => [Permission::PostOfficeEntries->value, Permission::ViewAllReports->value],
            ], [
                'text' => __('app.menu.cash'),
                'icon' => 'bi bi-wallet2',
                'submenu' => [[
                    'text' => __('app.cash.title'),
                    'route' => 'admin.cash-handovers.index',
                    'icon' => 'bi bi-arrow-left-right',
                    'can' => [Permission::ReceiveCash->value, Permission::ViewAllReports->value],
                ], [
                    'text' => __('app.closings.title'),
                    'route' => 'admin.daily-closings.index',
                    'icon' => 'bi bi-lock',
                    'can' => [Permission::CloseDays->value, Permission::ViewAllReports->value],
                ], [
                    'text' => __('app.reports.cash_reconciliation.title'),
                    'route' => 'admin.reports.cash-reconciliation',
                    'active' => ['admin/reports/cash-reconciliation*'],
                    'icon' => 'bi bi-safe',
                    'can' => Permission::ViewAllReports->value,
                ]],
            ], [
                'text' => __('app.reports.daily_collection.title'),
                'route' => 'admin.reports.daily-collection',
                'active' => ['admin/reports/daily-collection*'],
                'icon' => 'bi bi-journal-text',
                'can' => Permission::ViewAllReports->value,
            ], [
                'text' => __('app.reports.officer_performance.title'),
                'route' => 'admin.reports.officer-performance',
                'active' => ['admin/reports/officer-performance*'],
                'icon' => 'bi bi-speedometer2',
                'can' => Permission::ViewAllReports->value,
            ], [
                'text' => __('app.reports.outstanding.title'),
                'route' => 'admin.reports.outstanding',
                'active' => ['admin/reports/outstanding*'],
                'icon' => 'bi bi-hourglass-split',
                'can' => Permission::ViewAllReports->value,
            ], [
                'text' => __('app.reports.missed_partial.title'),
                'route' => 'admin.reports.missed-partial',
                'active' => ['admin/reports/missed-partial*'],
                'icon' => 'bi bi-exclamation-triangle',
                'can' => Permission::ViewAllReports->value,
            ], [
                'text' => __('app.reports.monthly.title'),
                'route' => 'admin.reports.monthly',
                'active' => ['admin/reports/monthly*'],
                'icon' => 'bi bi-bar-chart-line',
                'can' => Permission::ViewAllReports->value,
            ], [
                'text' => __('app.menu.settings'),
                'icon' => 'bi bi-gear',
                'submenu' => [[
                    'text' => __('app.areas.title'),
                    'route' => 'admin.areas.index',
                    'active' => ['admin/areas*'],
                    'icon' => 'bi bi-geo-alt',
                    'can' => Permission::ManageParties->value,
                ], [
                    'text' => __('app.holidays.title'),
                    'route' => 'admin.holidays.index',
                    'icon' => 'bi bi-calendar-x',
                    'can' => Permission::ManageSchedules->value,
                ], [
                    'text' => __('app.users.title'),
                    'route' => 'admin.users.index',
                    'active' => ['admin/users*'],
                    'icon' => 'bi bi-people',
                    'can' => Permission::ManageUsers->value,
                ], [
                    'text' => __('app.settings.title'),
                    'route' => 'admin.settings.edit',
                    'icon' => 'bi bi-sliders',
                    'can' => Permission::ManageSettings->value,
                ]],
            ]);
        });
    }
}
