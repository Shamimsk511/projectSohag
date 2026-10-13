<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Area;
use App\Models\CollectionSchedule;
use App\Models\Holiday;
use App\Models\OfficeHead;
use App\Models\PartyAssignment;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who reaches which route. Admin passes everything (Gate::before + role middleware), so the matrix
 * lists the other roles allowed per route. Route-model fixtures belong to ANOTHER field officer.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const MANAGER = Role::Manager;

    private const ACCOUNTANT = Role::Accountant;

    private const OFFICER = Role::FieldOfficer;

    /**
     * Route name => roles allowed besides admin.
     */
    private const MATRIX = [
        'admin.dashboard' => [self::MANAGER, self::ACCOUNTANT],
        'admin.dashboard.live' => [self::MANAGER, self::ACCOUNTANT],
        'admin.search' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.index' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.daily-collection' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.officer-performance' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.outstanding' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.missed-partial' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.cash-reconciliation' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.monthly' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.position' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.loan-register' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.withdrawal-register' => [self::MANAGER, self::ACCOUNTANT],
        'admin.reports.daily-cash-book' => [self::MANAGER, self::ACCOUNTANT],

        'admin.users.index' => [],
        'admin.users.create' => [],
        'admin.users.store' => [],
        'admin.users.edit' => [],
        'admin.users.update' => [],

        'admin.areas.index' => [self::MANAGER],
        'admin.areas.create' => [self::MANAGER],
        'admin.areas.store' => [self::MANAGER],
        'admin.areas.edit' => [self::MANAGER],
        'admin.areas.update' => [self::MANAGER],

        'admin.parties.index' => [self::MANAGER, self::ACCOUNTANT],
        'admin.parties.data' => [self::MANAGER, self::ACCOUNTANT],
        'admin.parties.show' => [self::MANAGER, self::ACCOUNTANT],
        'admin.parties.photo' => [self::MANAGER, self::ACCOUNTANT],
        'admin.parties.statement' => [self::MANAGER, self::ACCOUNTANT],
        'admin.parties.create' => [self::MANAGER],
        'admin.parties.store' => [self::MANAGER],
        'admin.parties.edit' => [self::MANAGER],
        'admin.parties.update' => [self::MANAGER],
        'admin.uploads.party-photo.store' => [self::MANAGER],
        'admin.uploads.party-photo.destroy' => [self::MANAGER],
        'admin.parties.assignment.store' => [self::MANAGER],
        'admin.parties.assignment.destroy' => [self::MANAGER],
        'admin.parties.portal.store' => [self::MANAGER],
        'admin.parties.portal.reset-pin' => [self::MANAGER],
        'admin.parties.portal.regenerate' => [self::MANAGER],
        'admin.parties.portal.card' => [self::MANAGER],

        'admin.parties.accounts.create' => [self::MANAGER],
        'admin.parties.accounts.store' => [self::MANAGER],
        'admin.loans.preview' => [self::MANAGER],
        'admin.accounts.show' => [self::MANAGER, self::ACCOUNTANT],
        'admin.accounts.statement' => [self::MANAGER, self::ACCOUNTANT],
        'admin.accounts.adjust' => [self::MANAGER],
        'admin.accounts.close' => [self::MANAGER],
        'admin.accounts.collection-plan.update' => [self::MANAGER],
        'admin.accounts.schedules.store' => [self::MANAGER],
        'admin.accounts.transactions.store' => [self::MANAGER, self::ACCOUNTANT],

        'admin.transactions.receipt' => [self::MANAGER, self::ACCOUNTANT],
        'admin.whatsapp.log' => [self::MANAGER, self::ACCOUNTANT],
        'admin.transactions.reverse' => [self::MANAGER],
        'admin.office-entries.index' => [self::MANAGER, self::ACCOUNTANT],
        'admin.office-entries.store' => [self::ACCOUNTANT],
        'admin.accounts.office-income.store' => [self::ACCOUNTANT],
        'admin.office-heads.index' => [self::ACCOUNTANT],
        'admin.office-heads.create' => [self::ACCOUNTANT],
        'admin.office-heads.store' => [self::ACCOUNTANT],
        'admin.office-heads.edit' => [self::ACCOUNTANT],
        'admin.office-heads.update' => [self::ACCOUNTANT],

        'admin.schedules.index' => [self::MANAGER, self::ACCOUNTANT],
        'admin.schedules.print' => [self::MANAGER, self::ACCOUNTANT],
        'admin.schedules.edit' => [self::MANAGER],
        'admin.schedules.update' => [self::MANAGER],
        'admin.schedules.cancel' => [self::MANAGER],
        'admin.schedules.reassign' => [self::MANAGER],
        'admin.holidays.index' => [self::MANAGER],
        'admin.holidays.store' => [self::MANAGER],
        'admin.holidays.destroy' => [self::MANAGER],

        'admin.cash-handovers.index' => [self::MANAGER, self::ACCOUNTANT],
        'admin.cash-handovers.store' => [self::MANAGER, self::ACCOUNTANT],
        'admin.daily-closings.index' => [self::MANAGER, self::ACCOUNTANT],
        'admin.daily-closings.store' => [self::MANAGER, self::ACCOUNTANT],
        'admin.settings.edit' => [],
        'admin.settings.update' => [],
        'admin.opening-cash.index' => [],
        'admin.opening-cash.store' => [],

        'field.home' => [self::OFFICER],
        'field.sync' => [self::OFFICER],
        'field.whatsapp.log' => [self::OFFICER],
        'field.api.collections.sync' => [self::OFFICER],
        // Fixtures are another officer's: an officer may only collect on and see receipts of their own rows.
        'field.collections.create' => [],
        'field.collections.store' => [],
        'field.receipts.show' => [],
        'field.parties.collect' => [],
        'field.parties.collections.store' => [],
        'field.parties.photo' => [],
    ];

    /**
     * Routes open to guests or to any signed-in user, not scoped by role.
     */
    private const OPEN = [
        'home', 'locale.switch', 'login', 'logout', 'field.offline', 'portal.link',
        'portal.start', 'portal.pin', 'portal.pin.check', 'portal.lock', 'portal.home', 'portal.pin.change', 'portal.pin.update',
        'portal.accounts.show', 'portal.receipts.show', 'portal.offline',
        'password.request', 'password.email', 'password.reset', 'password.update', 'password.confirm',
    ];

    public function test_every_named_route_has_a_decided_access_rule(): void
    {
        $undecided = collect(Router::getRoutes()->getRoutes())
            ->map(fn (Route $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => $name !== null && ! $this->isVendorRoute($name))
            ->reject(fn (string $name): bool => array_key_exists($name, self::MATRIX) || in_array($name, self::OPEN, true))
            ->values()
            ->all();

        $this->assertSame([], $undecided, 'Add new routes to PermissionMatrixTest::MATRIX (or OPEN).');
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        return array_combine(
            array_map(fn (Role $role): string => $role->value, Role::cases()),
            array_map(fn (Role $role): array => [$role], Role::cases()),
        );
    }

    #[DataProvider('roles')]
    public function test_each_role_reaches_exactly_its_routes(Role $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $mismatches = [];

        foreach ($this->requests() as $name => [$method, $uri]) {
            $allowed = $role === Role::Admin || in_array($role, self::MATRIX[$name], true);
            $status = $this->send($user, $method, $uri)->getStatusCode();
            $denied = $status === 403;
            if ($allowed === $denied || in_array($status, [401, 419], true)) {
                $mismatches[] = "{$method} {$name}: ".($allowed ? 'expected access' : 'expected 403')." got {$status}";
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $wrong = [];
        foreach ($this->requests() as $name => [$method, $uri]) {
            $response = $this->send(null, $method, $uri);
            $signIn = str_starts_with($uri, '/api/')
                ? $response->getStatusCode() === 401
                : $response->isRedirect(route('login'));
            if (! $signIn) {
                $wrong[] = "{$method} {$name}: {$response->getStatusCode()}";
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * One request per matrix route, route parameters bound to fixtures owned by another officer.
     *
     * @return array<string, array{string, string}>
     */
    private function requests(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');

        $otherOfficer = User::factory()->withRole(Role::FieldOfficer)->create();
        $account = Account::factory()->loan()->create();
        $party = $account->party;
        PartyAssignment::factory()->create(['party_id' => $party->id, 'field_officer_id' => $otherOfficer->id]);
        $schedule = CollectionSchedule::factory()->create(['account_id' => $account->id, 'field_officer_id' => $otherOfficer->id]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'type' => TransactionType::LoanCollection,
            'field_officer_id' => $otherOfficer->id,
            'schedule_id' => $schedule->id,
            'receipt_no' => 'R-202610-00001',
        ]);
        $bindings = [
            'account' => $account,
            'party' => $party,
            'area' => Area::factory()->create(),
            'office_head' => OfficeHead::factory()->create(),
            'schedule' => $schedule,
            'transaction' => $transaction,
            'holiday' => Holiday::factory()->create(),
            'user' => $otherOfficer,
        ];

        $requests = [];
        foreach (array_keys(self::MATRIX) as $name) {
            $route = Router::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} no longer exists.");
            $parameters = array_intersect_key($bindings, array_flip($route->parameterNames()));
            $requests[$name] = [$route->methods()[0], route($name, $parameters, false)];
        }

        return $requests;
    }

    private function send(?User $user, string $method, string $uri): TestResponse
    {
        if ($user) {
            $this->actingAs($user);
        }

        return str_starts_with($uri, '/api/') ? $this->json($method, $uri) : $this->call($method, $uri);
    }

    private function isVendorRoute(string $name): bool
    {
        return str_starts_with($name, 'boost.') || str_starts_with($name, 'adminlte.') || str_starts_with($name, 'storage.') || in_array($name, ['up', 'sanctum.csrf-cookie'], true);
    }
}
