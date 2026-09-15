# BUILD: Fully Dynamic Categorized Route Master & Nested Permission Matrix UI

Date: 2026-09-15

## Disclosed corrections to the literal spec

1. **`/api/v1/dynamic-permissions-tree` -> `/api/admin/permissions-tree`.**
   `/api/v1/*` in this codebase is exclusively the external, `auth.apikey`-gated
   Developer API surface (SHA-256-hashed API keys, no Laravel session — see
   `routes/api.php`'s `V1` group docblock). A Sanctum-session-authenticated UI
   endpoint placed under `/v1` would be unreachable from the web app — the
   same class of mistake this codebase's own history already made and fixed
   once for `/api/v1/groups` -> `/api/groups`. The tree endpoint ships at
   `GET /api/admin/permissions-tree`, inside the existing
   `permission:manage-accounts` + `admin` prefix group `AccountController`
   already uses.

2. **No `ModulePermissionMatrix.tsx`/`CreateClientModal.tsx`/`EditClientModal.tsx`
   existed.** There is one modal for both create and edit —
   `CreateAccountModal.tsx` (`account: Account | null` prop; `null` = create).
   `ModulePermissionMatrix.tsx` is a new component, wired into that single
   modal in place of its old hardcoded "Core Common Features" block and two
   `<SuiteSection/>` calls.

3. **Disclosed scope addition — `notifications` / `developer_api`.** These are
   valid `Account::MODULES` slugs with real nav gates (`AppLayout.tsx`'s
   `requiresModule`) but were deliberately excluded from the old three UI
   category groups. `Account::effectiveModules()` now dynamically intersects
   `allowed_modules` against `SystemRoute::activePermissionKeys()` — leaving
   these two out of `system_routes` entirely would have silently disabled
   them for every account the moment the seeder ran. `RouteMasterSeeder`
   backfills them into a 4th "Other Platform Features" category instead, so
   no existing account loses access to anything; a Super Admin now also gets
   to manage them via Route Master, which they previously could not.

## Architecture: additive, not a replacement

Given zero test coverage and the number of existing call sites keyed off
`Account::MODULES` / `accounts.allowed_modules` (`effectiveModules()`,
`hasModuleEnabled()`, `EnsureModuleEnabledMiddleware`,
`AccountService::resolveDelegatedModules()`, `AuthContext::hasModule()`,
`AppLayout`'s `requiresModule`, `ProtectedRoute`'s `module` prop, and
`module.guard:<slug>` across `routes/api.php`), this ships as infrastructure
layered ON TOP of that existing engine rather than a rip-and-replace:

- `system_routes.permission_key` reuses the exact same string space as the
  existing `Account::MODULES` slugs (`'whatsapp_setup'`, etc.).
- `accounts.allowed_modules` remains the source of truth for which modules
  ONE account has; the Route Master adds a platform-wide dynamic kill switch
  on top via `system_routes.is_active` / `route_categories.is_active`.
- No existing enforcement call site needed to change.

## Backend changes

- **New migrations**: `route_categories` (`id`, `category_name`,
  `category_code` unique, `icon_name`, `sort_order`, `is_active`) and
  `system_routes` (`id`, `category_id`, `route_title`, `route_path`,
  `permission_key`, `is_agent_assignable`, `is_active`, `sort_order`). No DB
  FK/cascade (matches this project's existing no-FK-enforcement convention);
  the controller blocks deleting a category with existing routes instead.
- **New models**: `RouteCategory`, `SystemRoute`. `SystemRoute::
  activePermissionKeys(): ?array` returns every `permission_key` whose route
  AND parent category are both active, cached (`Cache::remember`, 1 hour) and
  busted on every save/delete of either model — mirrors `Account::
  findCached()`'s existing cache-and-invalidate convention exactly. Returns
  `null` (sentinel: "no restriction") when the table is empty/unseeded, so
  this feature can never itself lock every account out of every module.
- **`Account::effectiveModules()`** (the single existing chokepoint nearly
  everything reads from): now intersects both the account's own
  `allowed_modules` and its Agent's `allowed_modules` against
  `SystemRoute::activePermissionKeys()` before doing its existing hierarchy
  intersection — this is the literal "all backend permissions and middleware
  validate dynamically against `system_routes.is_active`" requirement,
  satisfied at the one chokepoint instead of touching every `module.guard:`
  call site individually.
- **`RouteMasterSeeder`** (called from `DatabaseSeeder`): backfills 4
  categories / 18 routes from the existing hardcoded module constants.
  Uses `firstOrCreate` (never `updateOrCreate`) — deliberately never
  overwrites a row a Super Admin has since edited via the new CRUD UI.
- **`RouteMasterController`**: `tree()` (Super Admin sees every active route;
  an Agent sees only `is_agent_assignable` routes whose `permission_key` is
  inside their OWN `effectiveModules()` — server-side enforcement of the
  same "Agent Module Delegation" concept the old UI only enforced
  client-side) + full category/route CRUD, each self-guarded to Super Admin
  via an inline `isSuperAdmin()` check (mirrors `MessageTemplateController::
  test()`/`destroy()`'s existing precedent for "same permission tier as the
  group, this one action is Super-Admin-only").
- **Routes** (`routes/api.php`, inside the existing `permission:manage-accounts`
  admin group): `GET /admin/permissions-tree`, `GET|POST /admin/route-categories`,
  `PUT|DELETE /admin/route-categories/{id}`, `GET|POST /admin/system-routes`,
  `PUT|DELETE /admin/system-routes/{id}`.

## Frontend changes

- **`types/routeMaster.ts`**, **`services/routeMasterService.ts`**: new,
  mirroring `accountService.ts`'s conventions.
- **`components/admin/ModulePermissionMatrix.tsx`** (new): fetches
  `GET /api/admin/permissions-tree` and renders one styled box per active
  category (header, lucide icon resolved dynamically by name, "Select all"
  master checkbox with the same derived-not-stored indeterminate logic the
  old `SuiteSection` used) with routes as a two-column checkbox grid.
- **`CreateAccountModal.tsx`**: the old "Core Common Features" block + two
  `<SuiteSection/>` calls are replaced with one `<ModulePermissionMatrix/>`.
  The dead `SuiteSection`/`IndeterminateCheckbox` functions and now-unused
  imports (`MessageSquare`, `Share2`, `ACCOUNT_MODULE_LABELS`, `useRef`) were
  removed. The "Quick Plan" preset buttons (`applyWhatsappPlan`/
  `applySocialPlan`/`applyFullEnterprisePlan`) were left untouched — out of
  scope, they still bulk-apply the hardcoded constant arrays, which remains
  a valid, independent operation on `allowed_modules`.
  **Disclosed UI change**: Core Common now also gets a "Select all"
  checkbox (previously it had none, being the un-gated shared baseline) —
  the spec explicitly asks every category box to have one.
- **`pages/admin/RouteMasterPage.tsx`** (new): Super-Admin CRUD console —
  two management tables (Categories, Route Items) with inline create/edit
  modals and Active/Inactive toggle switches. A category with existing
  routes cannot be deleted (button disabled with an explanatory tooltip);
  the same is enforced server-side.
- **`AppLayout.tsx`**: new `superAdminOnly` nav item, "Route Master".
- **`App.tsx`**: new route at `/admin/route-master`, guarded with
  `<ProtectedRoute role="super_admin">` — deliberately `role`, not
  `permission="manage-accounts"` (which an Agent also holds), so this
  console is reachable ONLY by a real Super Admin.

## Known limitation (disclosed, not fixed in this pass)

`accounts.allowed_modules` is still validated server-side against the fixed
`Account::MODULES` array (`AccountController::updatePermissions()`'s
`Rule::in(Account::MODULES)`). A brand-new route added via Route Master with
a `permission_key` that ISN'T one of the 18 existing slugs will render in
the checklist and its `is_active` flag will correctly gate
`effectiveModules()` platform-wide, but toggling it per-account will 422
until `Account::MODULES` is also extended. Extending that validation to
accept any `system_routes.permission_key` dynamically is a reasonable
follow-up but touches a different, unrelated validation rule — left out of
this pass per the Minimal Change Principle.

## Verification performed

- No `php` binary reachable in this environment (disclosed, pre-existing
  limitation). Weak substitute: brace/paren/bracket balance check across
  every new/modified PHP file — all balanced.
- `./node_modules/.bin/tsc -b --force --noEmit` — clean, zero errors, across
  the whole frontend.
- `./node_modules/.bin/oxlint` on every new/modified frontend file — 0
  errors. 2 pre-existing-pattern warnings, both already present elsewhere in
  this codebase unmodified (`react(set-state-in-effect)` on a
  `useEffect(() => { void load(); }, [load])` fetch-on-mount pattern —
  identical to `TemplateManagerPage.tsx:593`; `react(static-components)` on
  resolving a lucide icon by a runtime string name, an inherent property of
  that pattern, not a real defect — the resolved reference is always one of
  lucide-react's fixed, stable exports).

## Requires your explicit authorization before running

Per the State Mutation Protocol, I have not run any of the following:

```
php artisan migrate                                  # creates route_categories, system_routes
php artisan db:seed --class=RouteMasterSeeder         # backfills from the existing hardcoded modules
```

(The tiered-approval-workflow migration/seeder from the prior phase are
still pending the same authorization, if not already run.)
