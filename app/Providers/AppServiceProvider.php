<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
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

        // Sidebar brand in the visitor's language; config is read before the locale middleware runs.
        View::composer('adminlte::page', function (): void {
            $name = __('app.site.org_name');
            config(['adminlte.logo' => '<b>'.e($name).'</b>', 'adminlte.logo_img_alt' => $name]);
        });

        // Items with app-translated labels; config/adminlte.php can only use the package's menu strings.
        Event::listen(BuildingMenu::class, function (BuildingMenu $event): void {
            $reports = Permission::ViewAllReports->value;

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
                'text' => __('app.menu.collections'),
                'icon' => 'bi bi-calendar-check',
                'submenu' => [[
                    'text' => __('app.schedules.title'),
                    'route' => 'admin.schedules.index',
                    'active' => ['admin/schedules*'],
                    'icon' => 'bi bi-list-check',
                    'can' => [Permission::ManageSchedules->value, $reports],
                ], [
                    'text' => __('app.reports.missed_partial.title'),
                    'route' => 'admin.reports.missed-partial',
                    'active' => ['admin/reports/missed-partial*'],
                    'icon' => 'bi bi-exclamation-triangle',
                    'can' => $reports,
                ]],
            ], [
                'text' => __('app.menu.money'),
                'icon' => 'bi bi-wallet2',
                'submenu' => [[
                    'text' => __('app.reports.position.title'),
                    'route' => 'admin.reports.position',
                    'icon' => 'bi bi-pie-chart',
                    'can' => $reports,
                ], [
                    'text' => __('app.cash.title'),
                    'route' => 'admin.cash-handovers.index',
                    'icon' => 'bi bi-arrow-left-right',
                    'can' => [Permission::ReceiveCash->value, $reports],
                ], [
                    'text' => __('app.office_entries.title'),
                    'route' => 'admin.office-entries.index',
                    'icon' => 'bi bi-cash-coin',
                    'can' => [Permission::PostOfficeEntries->value, $reports],
                ], [
                    'text' => __('app.closings.title'),
                    'route' => 'admin.daily-closings.index',
                    'icon' => 'bi bi-lock',
                    'can' => [Permission::CloseDays->value, $reports],
                ]],
            ], [
                'text' => __('app.menu.reports'),
                'route' => 'admin.reports.index',
                'active' => ['admin/reports', 'admin/reports/daily-collection*', 'admin/reports/daily-cash-book*', 'admin/reports/cash-reconciliation*', 'admin/reports/monthly*', 'admin/reports/outstanding*', 'admin/reports/loan-register*', 'admin/reports/withdrawal-register*', 'admin/reports/officer-performance*'],
                'icon' => 'bi bi-bar-chart-line',
                'can' => $reports,
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
                    'text' => __('app.office_heads.title'),
                    'route' => 'admin.office-heads.index',
                    'active' => ['admin/office-heads*'],
                    'icon' => 'bi bi-tags',
                    'can' => Permission::PostOfficeEntries->value,
                ], [
                    'text' => __('app.users.title'),
                    'route' => 'admin.users.index',
                    'active' => ['admin/users*'],
                    'icon' => 'bi bi-people',
                    'can' => Permission::ManageUsers->value,
                ], [
                    'text' => __('app.opening_cash.title'),
                    'route' => 'admin.opening-cash.index',
                    'icon' => 'bi bi-box-arrow-in-right',
                    'can' => Permission::SetOpeningBalances->value,
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
