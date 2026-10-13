# Member portal (design)

Date: 2026-10-13. Phase 7, sub-project 3 of 3 (after field PIN lock and manual WhatsApp share).

## Goal
A member opens a personal link (printed QR or WhatsApp), unlocks with a PIN and sees their own accounts, progress, statements and receipts. Installable PWA. Read-only.

## Access and security (decided: office gives the first PIN)
- One link per member: `/m/{token}`, token = 40 random chars. Table `portal_accesses`: party_id unique, token_hash (sha256, lookup), token_encrypted (Laravel `encrypt`, so the office can reprint / re-share), pin_hash (bcrypt), must_change_pin bool, failed_attempts, locked_at null, issued_by, issued_at, last_login_at null, timestamps.
- Members are not `users`: own session key `portal_party_id` + middleware `portal`. Never the staff guard; portal session gives no access to `/admin` or `/field` and staff sessions give no portal access.
- Issue: server makes a 6-digit office PIN, shown once to staff (told or written on a separate slip, never next to the QR). First open: link -> office PIN -> must set own PIN (4-6 digits, Bengali digits normalised, not equal to the office PIN).
- Device memory: after a correct PIN an encrypted httpOnly cookie (1 year, Secure in prod, SameSite=Lax) stores party_id + the access row's token_hash; `/portal` with a valid cookie asks only for the PIN. Issuing a new link changes token_hash, so every remembered device stops working.
- Unlocked portal session ends after 15 minutes idle (timestamp in session).
- 5 wrong PINs -> `locked_at` set; only office "Reset PIN" unlocks. Token entry and PIN posts are rate limited per IP (`throttle`), unknown tokens return 404 with the same timing path.
- PWA: `public/portal.webmanifest` (scope and start_url `/portal`, name from APP_NAME), own service worker `public/portal-sw.js` caching only the shell (built assets, icons, offline page), never member HTML or figures. Offline -> offline page. Own Vite entry `resources/js/portal.js` (no jQuery/DataTables), own layout `layouts.portal` (field-like).

## Member screens (read-only, Bengali-first, neutral wording)
- Home: one progress card per account, same figures as the admin member page (`ReportService::memberAccounts`): remaining, received, installments paid of ~total, next due, last payment, saving balance/agreed, FDR held/payback. Top line "you owe / we hold for you".
- Account statement (`LedgerService::statement`) with date filter + print. Closed accounts included.
- Receipt view for the member's own receipt rows (reuses `receipts/show` content on the portal layout).
- Every query scoped by the session party_id; account/transaction from another party -> 404.

## Office tools
- "Member portal" card on `admin.parties.show`, permission `parties.manage` (manager, admin) via `PartyPolicy::managePortal`.
- Issue link (shows PIN once), Print card (QR SVG + name + party_code + app name; no PIN), Share on WhatsApp (`WhatsApp::link`; logged in `whatsapp_logs` with new kind `portal`, party_id only), Reset PIN (new office PIN, clears lock and failures, must_change_pin), New link (new token; old link and devices dead). Status shown: issued, PIN set by member, locked, last login.
- `PortalAccessService` issue/resetPin/regenerate (DB transaction + `AuditLogger`: portal.issued / portal.pin_reset / portal.regenerated; secrets never in audit before/after). Member PIN change and login update the row without audit (last_login_at only).
- Receipt WhatsApp text gains "see your account: <link>" when the member has portal access.

## QR
- Composer `bacon/bacon-qr-code` (approved 2026-10-13), SVG renderer server-side (no GD/Imagick), small `App\Support\QrCode::svg(string $url)` helper.

## Testing
- Feature: issue -> office PIN works once -> forced change; wrong PIN x5 locks; reset unlocks; regenerate kills old link and remembered cookie; idle timeout; device cookie path; member cannot see another party's account/statement/receipt (404); portal session cannot open /admin or /field; staff session cannot open portal pages; throttles; office tools permission (PermissionMatrixTest, portal member routes listed in `OPEN` (they use the portal session, not staff auth) with their own tests); audit rows have no secrets; no interest wording (DomainInvariantsTest already scans views/lang).
- Browser check: install, PIN flow on a phone-width viewport, print card.

## Non-goals
Member self-registration, editing profile, payments online, notifications, multiple members per login (household), SMS PIN delivery, biometrics.
