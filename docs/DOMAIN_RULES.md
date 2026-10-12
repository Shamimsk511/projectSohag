# Domain rules

## What the NGO does
Takes savings/deposits from members, gives loans, collects installments in the field. The old Excel had sheets for loans, loan takers, savings, savings withdrawers, daily transactions, daily report, monthly report and an old monthly report. Those sheets become reports over one ledger; they are not tables.

## The interest rule
- No interest fields, rates, calculators or "profit" columns, anywhere.
- An account has a `contract_amount` (what admin says is due) and the system tracks `received` and `outstanding`. Admin decides the number; the software does not derive it.
- Changing `contract_amount` after any collection exists requires a reason and goes into `account_adjustments` with old/new value, actor and timestamp. Silent edits are forbidden.
- Never label the extra-over-principal as interest or profit in UI or reports. Use neutral wording: contract amount, received, outstanding.
- If the NGO later wants named contract types (qard, murabaha, mudarabah, musharakah), that is a separate roadmap item designed with a qualified Shariah scholar. Do not pre-build a switch for it.

## Entities
- **Party**: one person, one profile (loan customer, saver, both, member, guarantor). Never split into loan_customers and saving_customers.
- **User**: staff login. Roles: admin, manager, accountant, field_officer.
- **PartyAssignment**: party <-> field officer with `assigned_from`, `assigned_until`. History is kept; one active assignment per party at a time.
- **Account**: belongs to a party. Types: saving, loan, investment, other. Has status (active, closed) and opening/closing dates.
- **CollectionSchedule**: expected collection for an account on a date, with expected_amount, assigned officer, status (pending, partially_paid, paid, missed, cancelled). Supports daily, weekly, monthly, custom dates, or none.
- **Transaction**: the single ledger row for money movement.
- **Reversal**: a transaction that cancels another by reference; the original stays.

## Transaction types
loan_disbursement, loan_collection, saving_deposit, saving_withdrawal, saving_opening_balance, saving_maturity_credit, investment_deposit, investment_return, office_income, office_expense, adjustment, reversal.

Saving accounts may carry an agreed amount (`contract_amount`, admin-set, changed only through `account_adjustments`). `saving_opening_balance` = amount saved before the system (first entry only). `saving_maturity_credit` = book credit bringing total saved up to the agreed amount, never beyond. Both are no-cash, no-receipt rows that add to the saving balance; the member then withdraws normally.

## Rules for money movement
1. Amount > 0 always; direction comes from type.
2. Saving withdrawal cannot exceed saving balance (computed from ledger).
3. Loan collection may be partial; it marks the matching schedule row partially_paid or paid. Over-collection beyond outstanding is rejected unless admin overrides with a reason.
4. Each collection by a field officer is attributed to that officer and to the schedule row it settles.
5. Reversals need a reason and manager/admin permission; they create a new row, never touch the original.
6. Closing an account requires outstanding = 0 (loan) or balance = 0 (saving), or admin override with reason.
7. Every write produces an audit log entry (who, what, before/after, when, IP/device).

## Visibility
- Field officer: own assigned parties, own schedules, own collections, own daily totals.
- Manager: all officers' operational data, no role/permission management.
- Accountant: all ledger and reports, can post office income/expense, no party assignment.
- Admin: everything.

## Reports (all computed from transactions)
Daily collection, daily disbursement, savings deposits/withdrawals, income, expenses, cash balance, officer-wise collection and rate, customer ledger/statement, account statement, outstanding balances, due today, missed, partial, area-wise, monthly summary.

## Cash handling
Field officers hold cash. A `cash_handover` record (officer -> office, amount, date, confirmed_by) closes the loop so officer-held cash is always visible. Daily closing locks that date's transactions for non-admins.