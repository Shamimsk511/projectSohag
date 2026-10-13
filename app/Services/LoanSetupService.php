<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Support\CollectionPlanData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Give a loan, hand the member to a field officer and start collecting in one step, all or nothing.
 * Each step stays with its own service, rules and audit row; this only runs them together.
 */
class LoanSetupService
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly CollectionService $collections,
    ) {}

    /**
     * The officer is assigned before the plan is saved, so the first rows are generated for them.
     * Plan `ends_on` stays empty: loan rows stop once nothing is outstanding.
     *
     * @param  Closure(): Account  $openLoan  opens the account through AccountService (new or carried-in loan)
     */
    public function open(Closure $openLoan, ?User $officer, ?CollectionPlanData $plan, User $actor): Account
    {
        return DB::transaction(function () use ($openLoan, $officer, $plan, $actor): Account {
            $account = $openLoan();

            if ($officer !== null) {
                $this->assignments->assign($account->party, $officer, $actor);
            }

            if ($plan !== null) {
                $this->collections->savePlan($account, $plan->frequency, $plan->installment, $plan->weekdays, $plan->startsOn, null, $actor);
            }

            return $account;
        });
    }
}
