# IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking

Date: 2026-09-15

## Requirement 1 — Super-Admin Access Guard: already implemented, verified, no changes made

`app/Providers/AuthServiceProvider.php` already registers:

```php
Gate::before(function (User $user, string $ability) {
    return $user->isSuperAdmin() ? true : null;
});
```

Verified (not assumed) against `vendor/spatie/laravel-permission/src/Middleware/
PermissionMiddleware.php`: it calls `$user->canAny($permissions)`, which goes
through Laravel's `Gate` — so this single `Gate::before` already
short-circuits every `permission:*` route in `routes/api.php`, including the
ones added in this phase, for a Super Admin, "regardless of database
permissions" exactly as the spec asks. `RolePermissionSeeder` additionally
grants `super_admin` the full `PERMISSIONS` catalog directly, so this is
doubly covered. Adding a second, route-local bypass (the literal
`if ($user->isSuperAdmin()) return $next($request);` snippet in the spec)
on top of an already-verified global one would be redundant, conflicting
complexity — left alone.

## Requirement 2 — Agent & Client Cascading Enforcement: already substantially implemented (previous phase), unchanged

- "Agents can only view/assign active routes enabled for them by
  Super-Admin": `RouteMasterController::tree()` (previous phase) already
  filters an Agent caller's routes to `is_agent_assignable` AND inside
  their own `effectiveModules()`.
- "Sub-Clients can only view/use routes delegated to them by their Parent
  Agent": `Account::effectiveModules()`'s existing hierarchy intersection
  (Super Admin → Agent → Client) already enforces this, now additionally
  capped by `SystemRoute::activePermissionKeys()` (previous phase).
- **Disclosed, pre-existing, NOT closed in this pass**: `EnsureModuleEnabledMiddleware`
  (`module.guard:<slug>`) is documented elsewhere in this codebase as
  "wired to only some route groups" — a real gap between what
  `effectiveModules()` computes and what server-side middleware actually
  checks on every route. This was a known limitation before this request
  and remains one; closing it for every route group in `routes/api.php`
  is a much larger, unrelated-refactoring-scale change than this request's
  literal scope, and is called out here rather than silently left
  unmentioned.

## Requirement 3 — Global Audit Logging Engine (`activity_logs`)

**Disclosed pre-existing naming collision found before building this**:
`login_audit_logs` / `LoginAuditLog` / `AuditLogController` / the existing
`/audit-logs` page already exist in this codebase — for login attempts
only (success/failed), with none of this requirement's fields. This is a
genuinely separate table for a separate concern; built as `activity_logs`
exactly as specified, surfaced under a distinctly labeled "Activity Logs"
nav item so the two are never confused.

**Disclosed design substitution — model trait, not controller trait**:
implemented `App\Traits\LogsActivity` as an Eloquent model trait (hooking
`created`/`updated`/`deleted` via Laravel's `boot{Trait}()` convention)
rather than manually adding a logging call inside every controller
action. Rationale: this project has zero test coverage and dozens of
controllers; threading a manual call through every CRUD method by hand is
the highest-regression-risk way to do this and is easy to forget on the
next new endpoint. A model trait captures every mutation this project's
controllers already perform via `$model->save()/update()/delete()`
automatically and permanently — this is also, not coincidentally, how
`spatie/laravel-activitylog`'s own `LogsActivity` trait works, which this
requirement's exact phrasing echoes. `old_values`/`new_values` are
diffed from `getChanges()`/`getOriginal()`; `action_type` is
`create`/`update`/`delete`, refined to `toggle` when the only substantive
field changed is `is_active`. Redaction: every field already listed in a
model's own `$hidden` array (this codebase already declares one on every
model that stores a secret — passwords, OAuth tokens, gateway/webhook
secrets) is stripped before anything is written to `activity_logs`, so no
extra per-model secret list had to be hand-maintained. Logging is skipped
entirely when running in console (so seeders/artisan commands never
pollute the trail) or when there's no authenticated actor.

**Applied to 23 models** (verified individually, not assumed): `Account`,
`RouteCategory`, `SystemRoute`, `MessageTemplate` (built in the previous
phase), plus `User`, `ChatbotRule`, `CommentAutomationRule`, `ContactGroup`,
`ContactGroupMember`, `Lead`, `MailSetting`, `NotificationBroadcast`,
`NotificationTemplate`, `OrganicPost`, `PaymentGatewaySetting`,
`QuotaRequest`, `SocialAccount`, `SocialProviderConfig`, `Subscription`,
`WebhookSubscription`, `WhatsAppFlow`, `AdCampaign`, `ApiKey`.

**Deliberately NOT applied** (disclosed, with reasons — every one is
system-generated telemetry or a per-message/per-event log, not an
Agent/Client management action, and several would be extremely
high-volume): `AdCampaignDailyMetric`, `ChatbotLog`, `CommentAutomationEvent`,
`InAppNotification`, `Invoice`, `LoginAuditLog` (itself an audit log),
`MailLog`, `MessageDispatchLog` (one row per message sent — would dwarf
the activity log with no audit value), `PaymentAlert` (inbound
webhook-triggered core product data), `WebhookDelivery`,
`WhatsAppFlowSession`, `WhatsAppSession` (runtime device/session state).

## Requirement 4 — Super-Admin Audit Trail UI

- New permission `view-activity-logs`, granted only to `super_admin`
  (separate from the existing `view-audit-logs`, which admin/user/agent
  also hold for login history) — matches the literal "Super-Admin Audit
  Trail UI" scope.
- `ActivityLogController::index()` (`GET /api/admin/activity-logs`,
  `permission:view-activity-logs`) filters by `agent_id`, `account_id`,
  `module_name`, `action_type`, `from`/`to` — the spec's Agent/Sub-Client/
  Date/Module filters, plus action-type as a bonus. `modules()` endpoint
  backs the Module filter dropdown with the full distinct list,
  independent of the current page.
- `ActivityLogsPage.tsx` at `/admin/audit-logs` exactly as specified
  (`ProtectedRoute permission="view-activity-logs"`), with cascading
  Agent → Sub-Client dropdowns (reusing the existing `accountService`),
  a Module dropdown, an action-type filter, a date range, and an
  expandable row showing the old/new value JSON diff.

## Verification performed

- No `php` binary reachable (disclosed, pre-existing limitation) — weak
  brace/paren/bracket balance check across every new/modified PHP file
  (migration, model, trait, controller, seeder, `routes/api.php`, and all
  23 models the trait was added to) — all balanced.
- `tsc -b --force --noEmit` — clean across the whole frontend, twice
  (once before, once after this phase's changes).
- `oxlint` on every new/modified frontend file — 0 errors. Pre-existing-
  pattern warnings only: the same `react(set-state-in-effect)` fetch-on-
  mount pattern already present unmodified in `TemplateManagerPage.tsx`
  and `AccountsPage.tsx`, plus one instance of the same pattern on a new
  Agent→Sub-Client cascade-reset effect (a standard, safe React pattern
  this codebase's own linter treats as a warning everywhere it's used,
  not an error).

## Requires your explicit authorization before running

```
php artisan migrate                                   # creates activity_logs (+ any not yet run from earlier phases)
php artisan db:seed --class=RolePermissionSeeder       # grants view-activity-logs to super_admin
```

Nothing in the database has changed.
