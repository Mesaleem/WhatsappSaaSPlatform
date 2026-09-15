# Tiered Template Approval Workflow for 3-Tier Hierarchy — Implementation Summary

**Request:** update the Template Manager workflow to follow the Super-Admin → Agent → Client hierarchy: Sub-Client submissions route to their Parent Agent (`pending_agent_review`); direct Clients (no `agent_id`) route straight to Super Admin (`pending_admin_review`); an Agent's own approval forwards to a final Super-Admin review (`pending_meta_approval` for a `meta`-engine account, `pending` otherwise); Super Admin retains full visibility/override; Super Admin or an Agent authoring for their own account bypasses the review queues.

**[Disclosed naming correction]:** the request named `TemplateController.php`. The real, routed controller for this feature is `app/Http/Controllers/Api/MessageTemplateController.php` — that is the file edited below. A separate `TemplateController.php` was not created, since it would be dead code (nothing in `routes/api.php` would ever call it).

## Files changed

| File | Change |
|---|---|
| `backend-api/database/migrations/2026_09_15_100000_add_tiered_approval_statuses_to_message_templates_table.php` | New. Widens `message_templates.status` to add `pending_agent_review`, `pending_admin_review`, `pending_meta_approval` alongside the existing `pending`/`approved`/`rejected`. Additive only — no existing row's meaning changes. |
| `backend-api/app/Services/Templates/TemplateService.php` | New (the request's "TemplateService.php"). Pure status-routing + notification logic: `resolveCreationStatus()`, `routeAfterAgentApproval()`, `notifyPendingReview()`, `notifyRejected()`. |
| `backend-api/app/Http/Controllers/Api/MessageTemplateController.php` | `index()`/`store()`/`update()`/`approve()`/`reject()` now branch on Super Admin vs Agent (new `agentAccountOrNull()`/`assertAgentOwnsTemplate()` helpers, mirroring `AccountController::callerAgentScopeId()`). New `submitRequest()` — the Client self-submission entry point rules 1/2 require. `test()` and `destroy()` gained an explicit Super-Admin-only guard (see Security note below). `reject()` now accepts and stores an optional `rejection_reason` (the column existed, unused, since an earlier migration). |
| `backend-api/app/Models/MessageTemplate.php` | `STATUSES` constant widened to match the migration. `$with` eager-load widened to include `account.agent_id`/`account.account_type` — required for the new ownership/routing checks; without this they would silently see `null`. |
| `backend-api/database/seeders/RolePermissionSeeder.php` | `manage-templates` added to the `agent` role (was Super-Admin-only). **Requires re-running** `php artisan db:seed --class=RolePermissionSeeder` on any already-seeded database. |
| `backend-api/routes/api.php` | New tenant-scoped route `POST /api/alerts/message-templates/request` → `submitRequest()`, gated by the existing `permission:send-messages` tier (same group as the other `/alerts/*` routes). |
| `frontend-app/src/types/templates.ts` | `MessageTemplateStatus` widened; `rejection_reason` added to `MessageTemplate`; new `SubmitTemplateRequestPayload` type. |
| `frontend-app/src/services/templateService.ts` | `reject()` now takes an optional reason; new `submitRequest()` method (not yet called from any page — see Scope below). |
| `frontend-app/src/pages/admin/TemplateManagerPage.tsx` | Tab/status filter, badges, and status label extended for the three new statuses. Approve/Reject/Edit/Delete/Send-Template actions now branch by viewer (Super Admin vs Agent) to match the backend's own gates. Reject prompts for an optional reason. The "New Template" modal hides the Global option for an Agent viewer and includes the Agent's own account in the picker (their own account never appears in the paginated accounts list otherwise). |
| `frontend-app/src/components/layout/AppLayout.tsx` | **Necessary, not in the original file list.** The "Template Manager" nav item was `superAdminOnly: true` — an exclusive flag that would have hidden the page from an Agent regardless of the new permission grant. Changed to `permission: 'manage-templates'`, which both Super Admin (bypasses permission checks) and Agent (newly holds the permission) now satisfy. |

## Design decisions disclosed

- **An Agent's approval never sets `approved` directly.** The pre-existing Strict 1-Template-Per-Client & Testing Gate (`is_super_admin_tested`, a real WhatsApp test-fire) is a content-safety check orthogonal to the hierarchy, not a review step this feature was asked to let an Agent skip. "Approve directly based on gateway mode" is implemented as: `qr` engine → `pending` (the same terminal state a Super-Admin-authored template already starts at); `meta` engine → `pending_meta_approval` (a distinctly labeled variant, since this codebase has no live Meta WhatsApp Template Library API to actually call). Both funnel into the same, unmodified Super-Admin `approve()`/`reject()`.
- **`test()` (the real WhatsApp test-fire) and `destroy()` are explicitly excluded from the Agent grant**, even though they share the `manage-templates` permission an Agent now holds. `test()` always fires through *Super Admin's own* connected WhatsApp device (`Account::platformDevice()`) — an Agent test-firing would spend a send through someone else's number. `destroy()` was never listed in the spec's Agent actions (approve/reject/edit only).
- **No new client-facing "submit a template" page was built.** The request named exactly three files to update; building a full new UI surface for a plain Client Admin to call the new `submitRequest()` endpoint was out of that scope. The backend endpoint and a matching `templateService.submitRequest()` method exist and are ready to wire up, but nothing currently calls them. **This is the one gap that keeps Rules 1/2 from being reachable end-to-end today** — flag if you want that page built next.
- Visibility/notification for a pending review is implemented as (a) server-side query scoping (an Agent's `index()` call only ever returns their own account + Sub-Clients' templates) plus (b) a best-effort in-app notification (`InAppNotification`) to the responsible party on submission and on rejection. There was no pre-existing "auto-notify on event" pattern anywhere else in this codebase to match — this is a new, narrowly-scoped addition, not a new subsystem.

## Verification performed

- **No `php` binary was reachable in this environment** (confirmed, matches this repo's own recent commits disclosing the same limitation) — none of the PHP changes could be executed, linted, or tested here. A brace/paren/bracket-balance check passed on every modified/new PHP file, which is a weak signal only.
- **Frontend: verified for real.** `tsc -b --force --noEmit` (a full, cache-bypassed type check across the whole `frontend-app` project) passed with zero errors. `oxlint` (this project's configured linter) reported 0 new errors/warnings on every changed file — the 3 warnings it did report are pre-existing, on lines untouched by this change.
- **Not run:** `php artisan migrate` (this migration, plus the ~41 already pending from before this change) and `php artisan db:seed --class=RolePermissionSeeder`. Both are schema/data mutations on your local database — per the state-mutation protocol, that's your call to run, not run automatically here. Nothing in this change is destructive (the migration only widens an enum; the seeder only adds a permission grant), but please run and confirm before relying on this feature.

## To actually use this feature

```bash
cd backend-api
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
```
