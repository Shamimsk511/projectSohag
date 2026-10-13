# FDR accounts design

Date: 2026-10-13. Roadmap: Phase 6.7 "FDR accounts".

## Goal
A member places money with the NGO (lump sum, installments, or several posts) and the NGO pays back an admin-agreed amount, possibly early. The system records deposits and payouts only. It never computes the agreed payback, a rate or a profit.

## Ledger
- `AccountType::Fdr` (prefix `FD`, numbers `FD-000001` via `SequenceService`). `accounts.account_type` is a string column: no migration.
- `contract_amount` = agreed payback. Optional at open (like saving agreed amount); set or changed only through `account_adjustments` with a reason.
- `investment_deposit`: in, cash, receipt, any number of posts, no cap.
- `investment_return`: out, cash, no receipt, allowed before maturity. Needs `contract_amount`; total returned (effective rows) must be <= `contract_amount`. Not capped by the deposited total.
- Both become recordable and are reversible. Reversing a deposit has no balance check (held may go negative by design). Reversing a return is always allowed.
- Computed figures only: deposited, agreed payback, paid back, remaining payback = (contract, or deposited when none is set) - returned, held = deposited - returned (no clamp).
- Close: remaining payback must be 0, else `limits.override` + reason (existing rule shape).
- Neutral wording: "agreed payback", never profit or rate.

## Collection (installment case)
- `AccountType::collectionType()` for Fdr = `investment_deposit`. Existing collection plan, schedule generator, field day list, collect-all, offline queue and sync work through it. Expected amount = installment (no outstanding cap on deposits, like saving).
- New `TransactionType::collectionTypes()` (loan_collection, saving_deposit, investment_deposit) replaces the hard-coded `[LoanCollection, SavingDeposit]` lists in `LedgerService` (collectionRows, cash in hand, receivedOn, ...), `CashService::handOverCollection`, `ReportService` and `TransactionType::issuesReceipt`.

## Touch points
- Enums: `AccountType::Fdr` (`prefix`, `entryTypes` = deposit + return, `collectionType`); `TransactionType` (`isRecordable`, `accountType`, `movesMoney`, labels).
- `TransactionService`: account type match, deposit/return rules above, schedule settle unchanged.
- `LedgerService`: `fdrDeposited/fdrReturned/fdrRemaining`, `remainingBalance` and `statement()` (deposit in, return out, running = held), `totalFdrHeld`.
- Reports: daily cash book gets FDR deposit and FDR return columns (cash flow); dashboard and monthly report get an FDR line. Loan and withdrawal registers unchanged.
- UI: account open form third type (agreed payback optional); account page summary (deposited / agreed payback / paid back / remaining), reused plan + entry forms; entry form labels per type; lang en + bn.
- `AccountService::openFdr` (mirrors `openSaving`).

## Tests
`TransactionServiceTest` (deposit, return cap, return without contract, reversals), scheduled field collection on an FDR, statement, close rule, cash book columns, `DomainInvariantsTest` rebuild includes FDR, `LangFilesTest`, `QueryCountTest` for touched pages. Update the old "investment unsupported" assertion.

## Non-goals
Computing payback, maturity dates and reminders, FDR opening balances (Excel import item), any switch for contract types.
