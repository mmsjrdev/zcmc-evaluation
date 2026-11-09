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
- [x] 8 Print view
- [x] 9 Signing (evaluator, head)
- [x] 10 Conforme and verification
- [x] 11 MMS records
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
- **Decisions:** Supplier queries list columns one by one, never SELECT *, so evaluator data is never read on supplier pages. Submit is two steps: validate, then a "Ready to submit?" card, then confirm_submit re-checks from the database in one transaction (stores supplier_hash, sets submitted_at, status_history, notifies mm_staff, audit). Drafts only reject bad dates or too-long text. A form someone else owns and a missing id both give 403. Cancel buttons come in Step 9 (supplier one still to add to `form-view.php`).
- **Known issues:** Add/remove item needs JavaScript. Supplier cancel is not built yet (Step 9). Evaluation_Form.docx and supplier.png were not available in this session, so labels follow the guide text; compare with the photo.
- **Database:** no changes.

### Step 5 - MMS intake
- **Files:** new `app/domain/mms-forms.php`, `mms-suppliers.php`, `mms/inbox.php`, `review.php`, `suppliers.php`. Changed: `supplier/form-view.php`.
- **Decisions:** `mms_apply_action()` runs forward, return_supplier and cancel in one transaction (lock, transition(), returns row, notify, audit). MMS pages load their own domain files; bootstrap unchanged. Only return and cancel remarks show to the supplier. Checklist compares supplier_hash.
- **Known issues:** Head Nurse notification links to `headnurse/inbox.php` (Step 6). Inbox shows 200 forms at most. Cancel does not notify an assigned evaluator.
- **Database:** no changes.
- **Step 6 needs:** forms in `with_head_nurse` (set by `forward`), a `headnurse/inbox.php` page for the notification link, and `return_to_mms`, which sends a form back to `submitted` in the MMS inbox.

### Step 7 - Evaluator form
- **Files:** new `evaluator/evaluate.php`, `common/evaluation-view.php`. Changed: `app/domain/evaluator-forms.php` (load/save evaluator part), `validation.php` (validate_evaluator_part), `definitions.php` (15 terms, quality_parameters, others_parameters), `permissions.php` (head may edit), `evaluator/assignments.php` (Open form link).
- **Decisions:** Posted items matched to stored items by position. Unticked B/C clears params and details; fields disabled until ticked. Suppliers get 403 on the view.
- **Known issues:** Terms keep the docx typos. New Tailwind classes need a rebuild.
- **Database:** no changes.

### Step 8 - Print view
- **Files:** new `common/print.php`, `app/domain/print-form.php`, `assets/css/print.css`, `assets/img/zcmc-letterhead.png` (cut from the docx).
- **Decisions:** Own print stylesheet, 8.5 x 13 in. Letterhead and footer repeat on every page (fixed boxes plus spacer rows). Supplier branch reads supplier columns only: no Findings, B, C or evaluator/head blocks; only the Conforme signature is loaded. Only current-version signatures print. DRAFT watermark until completed (CANCELLED if cancelled). Terms on the last page.
- **Known issues:** "Page X of Y" only in Chrome/Edge. Century Gothic is wider than Arial, so the Notes may spill to page 2. Notes keep the docx typo "nit". Print link not yet on `supplier/form-view.php`.
- **Database:** no changes.

### Step 9 - Signing
- **Files:** new `assets/js/signature-pad.js`, `app/domain/signing.php`, `content-hash.php`, `app/views/partials/evaluation-summary.php`, `common/sign.php`, `return.php`, `cancel.php`, `head/for-signature.php`. Changed: `permissions.php`, `common/evaluation-view.php` (Print, Sign, Return, Cancel, signature images), `print-form.php`.
- **Decisions:** All checks in one transaction (`signing_sign`). `form_content_hash()` hashes the CURRENT stored form, so only current-version signatures can be re-verified. Ref no from `series_counters`. Return raises version. Five wrong passwords in 10 minutes block signing. Supplier is also notified when the head signs.
- **Known issues:** Supplier Cancel and Print buttons not yet in `supplier/form-view.php`. Needs JavaScript. `flash('error')` type assumed.
- **Database:** no changes.

### Step 10 - Conforme and verification
- **Files:** new `supplier/conforme.php`, `mms/record.php` (minimal), `common/download.php`, `common/verify.php`, `app/domain/supplier-conforme.php`, `paper-conforme.php`. Changed: `common/print.php`, `assets/css/print.css` (footer verify URL, centered Conforme, CSS cache version), `supplier/form-view.php` (Conforme button), `config.sample.php`.
- **Decisions:** Conforme (device or paper) is refused unless evaluator and head signatures, `supplier_hash` and content hashes still match. Suppliers get 403 on download.php (scan holds evaluator sections). Verify checks the current version only; mismatches are audited once an hour.
- **Known issues:** Conforme wording is mine. Paper print shows Sections B and C. Tailwind rebuild needed.
- **Database:** no changes.

### Step 11 - MMS records
- **Files:** new `app/domain/verify.php` (verify_form()), `app/domain/mms-records.php` (filters, list queries, dashboard counts, record details, mms_mark_received()), `app/views/partials/signature-validity.php` (signature_validity_panel()), `app/views/partials/mms-ui.php` (status_pill(), mms_records_url()), `app/views/partials/mms-dashboard.php` (mms_dashboard_panel()), `mms/records.php`, `mms/export.php`. Changed: `common/verify.php` (now calls verify_form() and the shared panel), `mms/record.php` (full record).
- **Decisions:** verify_form() is the ONE signature check; common/verify.php and the panel on mms/record.php both use it (mismatch audit once per hour kept, in `verify_audit_once()` inside app/domain/verify.php). Mark as Received uses the existing workflow action `receive` (completed to received, mm_staff only) through mms_mark_received(): one transaction with a row lock, notifies the evaluator and the supplier, audit action `form_received`. `mms/record.php` POST has a hidden `action` field (`receive` or `paper`); the paper upload from Step 10 is unchanged. Return with remarks links to `common/return.php?id=N` (shown when can_return_evaluation() allows it). Supplier drafts are hidden from every MMS list. "Stuck" = no status change for more than 7 days (last status_history row, else evaluations.updated_at), and it skips completed, received and cancelled. The date range filters on `submitted_at`. LIKE searches escape \, % and _. Page size 20 and 7 days are constants `MMS_RECORDS_PAGE_SIZE` and `MMS_STUCK_DAYS` in mms-records.php. CSV export covers all pages of the current filter, UTF-8 with BOM, summary columns only, cells starting with = + - @ tab or CR get an apostrophe, audit action `records_export`. Own `status_pill()` is used instead of status_badge() (its signature was not known). The records list shows each form as one tappable row, with the extra filters in a "Filters" panel and active filters as removable chips. mm_staff and admin see all forms; admin gets no action buttons (the workflow table refuses).
- **Known issues:** `common/dashboard.php` still needs the MMS panel call in its mm_staff branch (require_once mms-records.php, mms-ui.php, mms-dashboard.php, then `mms_dashboard_panel();`); not confirmed done. Tailwind rebuild needed. The records page and verify page read the form on every open, so a mismatch is audited the first time it is seen (once per hour).
- **Database:** no changes (no new index was needed).
- **Step 12 needs:** `users` (role, status, requested_role, department_id, employee_id, position, must_change_password), `alternate_verifiers` (department_id, head_user_id, unique pair), `departments` and `procurement_modes` (both have `active`), `audit_log`. A pending head is stored as role `evaluator`, status `pending`, requested_role `head`. `verifier_department_ids()` in permissions.php reads `$user['verifier_department_ids']` (set by current_user()) or falls back to the alternate_verifiers table. The role-request count badge belongs in `app/views/layout/sidebar.php`. Admin may view signature records (record.php, verify.php) but must never create, change or delete a signature.

## Tailwind commands (Windows)

Open Command Prompt or PowerShell in the project folder, for example: