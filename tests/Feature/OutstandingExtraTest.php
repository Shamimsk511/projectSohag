<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Party;
use App\Models\User;
use App\Services\AccountService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutstandingExtraTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_and_outstanding_report_show_the_agreed_extra(): void
    {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->withRole(Role::Manager)->create();
        $loan = app(AccountService::class)->openLoan(
            Party::factory()->create(),
            Money::fromTaka('50000'),
            Money::fromTaka('8250'),
            PaymentMethod::Cash,
            today(config('app.display_timezone')),
            $manager,
        );

        $this->actingAs($manager)->get(route('admin.accounts.show', $loan))
            ->assertOk()->assertSeeText(__('app.accounts.agreed_extra'))->assertSeeText(Money::fromTaka('8250')->format());
        $this->actingAs($manager)->get(route('admin.reports.outstanding'))
            ->assertOk()->assertSeeText(Money::fromTaka('8250')->format());
    }
}
