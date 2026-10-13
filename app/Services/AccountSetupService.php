<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Support\CollectionPlanData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Open an account (loan, saving or FDR), hand the member to a field officer and start collecting in
 * one step, all or nothing. Each step stays with its own service, rules and audit row; this only
 * runs them together.
 */
class AccountSetupService
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly CollectionService $collections,
    ) {}

    /**
     * The officer is assigned before the plan is saved, so the first rows are generated for them.
     * Loan plans have no end date (rows stop once nothing is outstanding); saving and FDR plans may.
     *
     * @param  Closure(): Account  $openAccount  opens the account through AccountService
     */
    public function open(Closure $openAccount, ?User $officer, ?CollectionPlanData $plan, User $actor): Account
    {
        return DB::transaction(function () use ($openAccount, $officer, $plan, $actor): Account {
            $account = $openAccount();

            if ($officer !== null) {
                $this->assignments->assign($account->party, $officer, $actor);
            }

            if ($plan !== null) {
                $this->collections->savePlan($account, $plan->frequency, $plan->installment, $plan->weekdays, $plan->startsOn, $plan->endsOn, $actor);
            }

            return $account;
        });
    }
}
