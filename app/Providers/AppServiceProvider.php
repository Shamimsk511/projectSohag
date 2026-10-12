<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
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

        Paginator::useBootstrapFive();

        // Items with app-translated labels; config/adminlte.php can only use the package's menu strings.
        Event::listen(BuildingMenu::class, function (BuildingMenu $event): void {
            $event->menu->add([
                'text' => __('app.parties.title'),
                'route' => 'admin.parties.index',
                'active' => ['admin/parties*'],
                'icon' => 'bi bi-person-vcard',
                'can' => [Permission::ViewAllParties->value, Permission::ManageParties->value],
            ], [
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
                'text' => __('app.office_entries.title'),
                'route' => 'admin.office-entries.index',
                'icon' => 'bi bi-cash-coin',
                'can' => [Permission::PostOfficeEntries->value, Permission::ViewAllReports->value],
            ], [
                'text' => __('app.users.title'),
                'route' => 'admin.users.index',
                'icon' => 'bi bi-people',
                'can' => Permission::ManageUsers->value,
            ]);
        });
    }
}
