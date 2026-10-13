# Manual WhatsApp share (design)

Date: 2026-10-13. Phase 7, sub-project 2 of 3 (after field PIN lock, before member portal).

## Goal
Staff and officers send payment reminders and receipts to members over WhatsApp with one tap, no API, no cost. The app records that the share was opened so two people do not nag the same member unknowingly.

## Decisions
- Link: `https://wa.me/8801XXXXXXXXX?text=<urlencoded>` from the party phone (`01XXXXXXXXX`, already normalised on save). One PHP helper `App\Support\WhatsApp::link(?string $phone, string $text): ?string` (null when the phone is not a valid BD mobile -> no button rendered).
- Reminder text `app.whatsapp.reminder` (en/bn, neutral words only): name, due date, account number, amount still due on that schedule row, `config('app.name')`. Example bn: "আসসালামু আলাইকুম রহিমা, ১২/১০/২০২৬ তারিখের কিস্তি LN-000012 হিসাবে ৳ ৫০০ বাকি আছে। — সৃজনশীল".
- Receipt text `app.whatsapp.receipt`: receipt no, date, amount, account, remaining after it (loan outstanding / saving balance; FDR held). Portal link appended by sub-project 3.
- Entry notice text (added 2026-10-13 on request): `app.whatsapp.disbursed` (amount given, date, account, contract amount = total to pay back, installment when a plan exists) and `app.whatsapp.withdrawn` (amount, date, account, saving balance left). Only `loan_disbursement` and `saving_withdrawal` rows, not reversed. Button in the success flash right after posting (incl. one-step loan open) and on account page recent-entries rows of those types. Office only; permission = can view the account.
- Buttons: field home unpaid rows (Remind), field receipt page, admin dashboard "Not paid yet" rows, missed/partial report rows, admin receipt page.
- Permissions: reminder = `CollectionSchedulePolicy::collect` (officer's own row) or `viewAny` schedules (office); receipt share = `TransactionPolicy::viewReceipt`; notice = can view the transaction's account. Checked on render and again on the log endpoint.
- Logging (option B): table `whatsapp_logs` (id, kind `reminder|receipt|notice`, party_id, schedule_id null, transaction_id null, phone, sent_by, created_at only); immutable model like `CashHandover`. Not a financial write: no audit row.
- The button is a plain `<a target="_blank">`. JS (field.js / admin.js) also posts `fetch(..., {keepalive: true})` with `X-CSRF-TOKEN` to `field.whatsapp.log` / `admin.whatsapp.log` (body: kind + schedule_id or transaction_id; server derives party and phone). Failure is ignored (best effort, no offline queue). No JS = link works, nothing logged.
- Badge: a row with a reminder logged today (Dhaka day) shows "WhatsApp opened HH:MM · name"; JS adds it right after the tap; field offline snapshot rows carry the label.
- One service writes logs: `WhatsAppLogService::record(kind, schedule|transaction, actor)`.

## Testing
- Unit: link building (formats, invalid/missing phone -> null, Bengali text encoded).
- Feature: log endpoint own row ok, other officer's row 403, receipt without permission 403, notice only for disbursement/withdrawal rows (others 422), invalid payload 422; badge shown after a log; buttons hidden without phone; PermissionMatrixTest lines for the new routes; field day list/dashboard stay in QueryCountTest (logs eager-loaded per page, not per row).

## Non-goals
WhatsApp Business API, automatic or scheduled sending, delivery/read status, bulk "remind all", SMS.
