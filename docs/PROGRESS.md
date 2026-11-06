# ZCMC Evaluation Form System - Progress

Project: Digital Evaluation Form ZCMC-F-PROC-29 (Rev. 0, 8.5 x 13 in)
Stack: native PHP 8, MySQL (PDO), Tailwind CSS v4 (standalone CLI), vanilla JS
Hosting: XAMPP first, then InfinityFree (only `config/config.php` changes)

## Step checklist (Guide Rev. 2, supplier-first)

- [x] 1 Foundation
- [x] 2 Accounts and roles
- [x] 3 Layout (Tailwind)
- [x] 4 Supplier form
- [x] 5 MMS intake
- [x] 6 Head Nurse distribution
- [x] 7 Evaluator form
- [ ] 8 Print view
- [ ] 9 Signing (evaluator, head)
- [ ] 10 Conforme and verification
- [ ] 11 MMS records
- [ ] 12 Admin
- [ ] 13 Test and security
- [ ] 14 InfinityFree
- [ ] 15 OTP (final)

## Step log

### Step 1 - Foundation
- **Files:** `config/` (config.php, config.sample.php, .htaccess), `app/bootstrap.php`, `app/services/otp.php`, `app/core/permissions.php` (empty stub), `app/domain/workflow.php` and `form-fields.php` (empty stubs), `.htaccess` in app/, config/, storage/, storage/uploads/, `resources/css/input.css`, `database/schema.sql`, `index.php`, `install.php`, `docs/PROGRESS.md`.
- **Decisions:** Database opens lazily through `db()`, so pages that do not need MySQL still load. `signatures` is insert-only by convention (no UPDATE/DELETE in code) with a unique key on evaluation, version and role. Session cookies are httponly, SameSite Lax, secure on https.
- **Known issues:** none.

### Step 2 - Accounts and roles
- **Files:** `app/bootstrap.php` (changed: loads auth.php). New: `app/core/auth.php`, `app/core/permissions.php` (filled in), `app/views/layout/auth-top.php`, `auth-bottom.php`, `auth/register.php`, `login.php`, `logout.php`, `change-password.php`, `pending.php`, `common/dashboard.php` (placeholder).
- **Decisions:** A pending head is stored as role `evaluator`, status `pending`, requested_role `head`. Lockout is 5 failures per 10 minutes per email, not per IP. Logout is a POST with a confirm card. Login refuses if `OTP_ENABLED` is on before Step 15. Evaluators also see `returned_evaluator` forms. The department pool means no evaluator assigned yet.
- **Known issues:** No rate limit on registration. No self-service password reset (admin resets it). The dashboard is temporary.
- **Database:** no changes.

### Step 3 - Layout (Tailwind)
- **Files:** `app/views/layout/head.php` (shared head), `header.php`, `sidebar.php`, `footer.php`, `auth-top.php` (changed: uses head.php + logo); `app/views/partials/status-badge.php`, `empty-state.php`, `page-header.php` (also `stat_card()`); `app/domain/definitions.php`; `app/services/notify.php`; `app/bootstrap.php` (loads definitions + notify); `common/notifications.php`; `common/dashboard.php` (real dashboards); `assets/js/ui.js`; `resources/css/input.css` (green brand palette from the ZCMC logo, `.nav-link`, `.stat-card`); compiled `assets/css/app.css`.
- **Decisions:** Pages call header.php / footer.php; sign-in pages keep auth-top / auth-bottom, both share head.php. Menu items whose page does not exist yet show "Soon". Sidebar is a function so it cannot overwrite page variables. Notification links are plain project paths; unsafe links are ignored. Flash messages use show_flash() from bootstrap.
- **Known issues:** Definition of Terms list is empty until Step 7. Role-request count badge comes in Step 12.
- **Database:** no changes. `database/dev-test-users.sql` is dev only.

### Step 4 - Supplier form
- **Files:** new `app/domain/form-fields.php` (owner map, field_owner, fields_for, filter_post), `workflow.php` (full transition table, transition(), can_transition()), `validation.php` (supplier part), `hashing.php` (supplier_hash), `supplier-forms.php` (supplier-only queries, save, submit), `app/services/audit.php` (audit() wraps audit_write()), `app/views/partials/form-helpers.php` (field_error, input_class moved here), `supplier/forms.php`, `form-edit.php`, `form-view.php`, `assets/js/form-items.js`. Changed: `bootstrap.php` (loads the new files), `permissions.php` (can_edit_supplier_part needs status active), `auth-top.php`, `header.php`.
- **Decisions:** Supplier queries list columns one by one, never SELECT *, so evaluator data is never read on supplier pages. Submit is two steps: validate, then a "Ready to submit?" card, then confirm_submit re-checks from the database in one transaction (stores supplier_hash, sets submitted_at, status_history, notifies mm_staff, audit). Drafts only reject bad dates or too-long text. A form someone else owns and a missing id both give 403. Cancel buttons come in Step 9.
- **Known issues:** Add/remove item needs JavaScript. Supplier cancel is not built yet (Step 9). Evaluation_Form.docx and supplier.png were not available in this session, so labels follow the guide text; compare with the photo.
- **Database:** no changes.

### Step 5 - MMS intake
- **Files:** new `app/domain/mms-forms.php`, `mms-suppliers.php`, `mms/inbox.php`, `review.php`, `suppliers.php`. Changed: `supplier/form-view.php`.
- **Decisions:** `mms_apply_action()` runs forward, return_supplier and cancel in one transaction (lock, transition(), returns row, notify, audit). MMS pages load their own domain files; bootstrap unchanged. Only return and cancel remarks show to the supplier. Checklist compares supplier_hash.
- **Known issues:** Head Nurse notification links to `headnurse/inbox.php` (Step 6). Inbox shows 200 forms at most. Cancel does not notify an assigned evaluator.
- **Database:** no changes.
- **Step 6 needs:** forms in `with_head_nurse` (set by `forward`), a `headnurse/inbox.php` page for the notification link, and `return_to_mms`, which sends a form back to `submitted` in the MMS inbox.

## Tailwind commands (Windows)

Open Command Prompt or PowerShell in the project folder, for example: