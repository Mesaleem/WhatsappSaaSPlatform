# Audit Report — "Module & Feature Access" Modal Refactor + Required-Field Asterisks

Scope: `backend-api/` + `frontend-app/` on `wa-saas-platform`. All findings are **[Fact]** (verified by reading the actual code before/after editing) unless tagged **[Inference]**/**[Hypothesis]**/**[Unknown]**.

---

## 0. Naming discrepancy (disclosed again)

**[Fact]** There is still no file named `ManageClientsPage.tsx` anywhere in the codebase (confirmed via `find`/`grep` before starting). The "Manage Clients" screen and its "Module & Feature Access" modal live in `frontend-app/src/components/admin/CreateAccountModal.tsx`, rendered from `AccountsPage.tsx` (route `/admin/accounts`, nav label "Manage Clients"). All work below was done in `CreateAccountModal.tsx`, since that is the actual, only implementation of the feature described.

---

## 1. Required-field asterisks

Added `<span className="text-red-500">*</span>` immediately after the label text (or `{required && <span className="text-red-500">*</span>}` for the parameterized `Field` wrapper) to every genuinely-required input across the app's forms — no native HTML `required` attributes exist anywhere in this codebase, so the asterisk is a visual addition only; it does not change validation, which already runs in each form's own `validate()`/disabled-submit logic. Files touched: `ProfileModal.tsx`, `MetaConfigCard.tsx`, `TemplateManagerPage.tsx`, `SendAlertPage.tsx`, `LoginPage.tsx`, `ChatbotPage.tsx`, `DeveloperPage.tsx`, `UsersPage.tsx`, `CommentRulesPage.tsx`, `MetaAdsPage.tsx`, `NotificationsPage.tsx`. `CreateAccountModal.tsx` already had asterisks everywhere required (used as the pattern reference); `PermissionsMatrixPage.tsx`, `AdminSocialSettingsPage.tsx`, `GatewaySettingsPage.tsx` have no hard-required fields and were left unchanged.

**Deliberately skipped**: `MetaAdsPage.tsx`'s "Target Industry" (only required for a separate "Generate with AI" action, not for Launch — marking it would mislead users that Launch needs it).

**Disclosed, not fixed** (out of this task's scope): `NotificationsPage.tsx`'s custom-emails `<textarea>` (~line 269) has no `<label>` at all today despite being conditionally required. Flagged as a separate follow-up, not touched.

---

## 2. "Module & Feature Access" — 3-category reorganization

### 2.1 What changed and why

The prior implementation (`COMBINED_MODULE_CHECKLIST`, 8 rows, one of which bundled `whatsapp_setup` + `chatbot` into a single "WhatsApp Chatbot & Rules" checkbox) could not satisfy this spec, which explicitly lists WhatsApp Setup and Chatbot Rules as two separate rows. **[Fact]** `grep -rn "COMBINED_MODULE_CHECKLIST" src` returned exactly one match (its own definition) before removal — it had zero consumers, so removing it was safe.

Replaced with three new exported constants in `frontend-app/src/types/account.ts`:

- `CORE_COMMON_MODULES = ['dashboard', 'analytics', 'billing', 'team_management']` — exactly the spec's 4 items. `notifications` is intentionally excluded per the spec ("Remove Notifications") but **remains a valid `Account::MODULES`/`ACCOUNT_MODULES` slug** — it is not deleted from the enum, only from this checklist's rendered rows, because `AppLayout.tsx`'s Notifications nav item still gates on it and every existing account's stored `allowed_modules` may still reference it. Every mutation added in this task (`toggleModule`, `toggleSuite`, `applyPreset`) only ever adds/removes the *specific* slugs it names, so `notifications` (and `developer_api`, also outside this checklist) is never touched by any of the new UI.
- `WHATSAPP_SUITE_MODULES = ['whatsapp_setup', 'send_alert', 'chatbot', 'templates', 'device_settings']` — 5 items, matching the spec exactly.
- `SOCIAL_SUITE_MODULES = ['social_accounts', 'meta_ads', 'lead_crm', 'social_inbox', 'comment_automation', 'reports']` — 6 items, matching the spec exactly.

Two new slugs were required and added to **both** `ACCOUNT_MODULES` (frontend) and `Account::MODULES` (backend, `backend-api/app/Models/Account.php`) — this is a plain validation-list array (used by `Rule::in()` and stored inside the JSON `allowed_modules` column), **not a DB schema column**, so no migration was needed for either addition:

- `device_settings` → maps to `AppLayout.tsx`'s "Device Settings" nav item, which is `superAdminOnly: true` with **no** `requiresModule`. **[Fact]**, confirmed by reading that nav entry. Following the same precedent already established for `templates` (Template Manager) in an earlier task, this checkbox is included for checklist completeness but is **currently a no-op** — a Client Admin can never reach `/admin/device-settings` regardless of this toggle, since the route is superAdminOnly. Disclosed, not silently hidden.
- `social_accounts` → maps to `AppLayout.tsx`'s "Social Accounts" nav item, which **previously had no `requiresModule` at all** (a gap disclosed in an earlier task, at the time justified by "no corresponding checklist row"). Since this spec adds a real checklist row for it, that gap is now closed: the nav item gained `requiresModule: 'social_accounts'`, so toggling this checkbox has a real client-visible effect (hides/shows the sidebar entry + route-guards it), unlike `device_settings`.

Three existing labels were corrected in `ACCOUNT_MODULE_LABELS` to match both the spec's wording and `AppLayout.tsx`'s actual nav labels (previously they disagreed):

| Slug | Old label | New label |
|---|---|---|
| `comment_automation` | Comment Auto-Responder | Comment Rules |
| `reports` | AI Copywriter & Reports | Social Reports |
| `team_management` | Team User Creation | Team Users |

### 2.2 Core Common persistence — verified

- **Create mode**: `allowedModules` state is now seeded to `[...CORE_COMMON_MODULES]` (previously `null`, meaning "every module enabled" — that default was correct for edit mode but wrong for a brand-new client, which should start minimal). **Verified**: with this seed, `isModuleEnabled('dashboard' | 'analytics' | 'billing' | 'team_management')` returns `true` and every other module returns `false` on first render — exactly the spec's "pre-check these 4 core items by default."
- **Cannot be fully cleared by any preset**: `applyWhatsappPlan`, `applySocialPlan`, and `applyFullEnterprisePlan` are all built as `applyPreset([...CORE_COMMON_MODULES, ...<suite(s)>])` — every one of the three literally includes the 4 Core Common slugs in the array it commits. There is no code path in this refactor that can produce a `next` array excluding them via a preset.
- **Individually toggleable, by design**: Core Common checkboxes are still ordinary, independently-clickable rows (not locked/disabled) — the spec's "pre-check by default" was read as an initial-state requirement, not an immutability requirement, consistent with how every other module in this checklist has always behaved. **[Inference]**, disclosed: a Super Admin *can* manually uncheck a Core Common item after a preset is applied; only the presets themselves are guaranteed to include all 4.

### 2.3 Master Toggle cascading behavior — verified

Each suite's Master Category checkbox (`SuiteSection`'s "Select all") is **derived state**, computed fresh on every render from its children — never stored independently, so it cannot drift out of sync with them:

```
enabledCount = suiteModules.filter(isModuleEnabled).length
allEnabled   = enabledCount === suiteModules.length   → checked
someEnabled  = enabledCount > 0 && !allEnabled        → indeterminate (via a ref + useEffect,
                                                          since React has no native `indeterminate` prop)
otherwise                                              → unchecked
```

- **Checking the Master Header**: `onChange={() => onToggleSuite(!allEnabled)}` — when not all children are enabled (none or some), clicking calls `toggleSuite(suiteModules, true)`, which unions the suite's 5 or 6 slugs into the current array. **Verified**: every child in that suite becomes enabled, matching "Checking the Master Header auto-selects all child routes in that category."
- **Unchecking any child route**: toggling one child off via `toggleModule` removes only that slug. On the next render, `enabledCount < suiteModules.length` and `> 0`, so `someEnabled = true` → the Master Header renders **indeterminate** (dash, not empty). If the last remaining child is unchecked, `enabledCount === 0` → Master Header renders fully **unchecked**. Both states match the spec exactly.
- **Clicking the Master Header when fully checked**: unchecks the entire suite (`toggleSuite(suiteModules, false)`, filtering all suite slugs out) — standard tri-state convention; the spec doesn't specify this direction explicitly but it's the only sensible complement to "checking selects all," and was verified not to touch the other suite, Core Common, or unmanaged slugs (`toggleSuite` only ever adds/removes the slugs in `suiteModules`, same pattern as `toggleModule`).

### 2.4 Quick Plan preset buttons — selection logic verified

All three call `applyPreset(modules)`, which computes `next = [...unmanaged, ...modules]` where `unmanaged` is whatever the current array holds outside `CORE_COMMON_MODULES ∪ WHATSAPP_SUITE_MODULES ∪ SOCIAL_SUITE_MODULES` (i.e., `notifications`/`developer_api`, preserved untouched) — this is an **absolute assignment** of the 15 managed slugs, not an incremental toggle, so each preset always produces exactly the same 4-or-more-item set regardless of what was checked before:

| Preset | Resulting managed slugs |
|---|---|
| **WhatsApp Plan** | `dashboard, analytics, billing, team_management` + `whatsapp_setup, send_alert, chatbot, templates, device_settings` (9 total) — Social Suite's 6 slugs are absent, i.e. cleared, per "clears Social Media." |
| **Social Media Plan** | `dashboard, analytics, billing, team_management` + `social_accounts, meta_ads, lead_crm, social_inbox, comment_automation, reports` (10 total) — WhatsApp Suite's 5 slugs are absent, per "clears WhatsApp." |
| **Full Enterprise Plan** | All 15 managed slugs (Core Common + both suites) — `applyPreset(MANAGED_CHECKLIST_MODULES)`, the same constant used to compute "unmanaged" above, so this one call is provably every managed slug at once. |

**Verified** via direct code reading (not executed — no running dev server/browser available on-device to click-test): each preset button's `onClick` resolves to one of these three functions with no other code path reachable, and `applyPreset`'s `[...new Set([...unmanaged, ...modules])]` guarantees no duplicate slugs regardless of the array's prior contents.

### 2.5 Create-mode vs. edit-mode behavior

Previously the entire "Module & Feature Access" section was gated `isEditMode && account &&` — a client's module checklist could only be set *after* creation, via a second immediate-PATCH interaction. This was flagged as a pending gap in an earlier task's audit and is now closed:

- **Edit mode** (unchanged): every checkbox/master-toggle/preset click fires `accountService.updatePermissions()` immediately via the new shared `commitModules()` helper — identical network behavior to before this refactor, just reached through more entry points (single toggle, suite toggle, or preset) instead of only single toggle.
- **Create mode** (new): the same handlers now write to local state only (`commitModules` branches on `isEditMode && account`), and the final `allowedModules` array is sent once, atomically, inside `CreateAccountPayload.allowed_modules` on submit. **[Fact]** the backend already accepted this field on create — `AccountController::store()`'s validation (`'allowed_modules' => ['nullable', 'array']`, `Rule::in(Account::MODULES)`) and its `Account::create([...])` call were added in an earlier task specifically to support this; no backend change was needed this task beyond the two new slugs in `Account::MODULES`.

---

## 3. Verification performed

- **TypeScript**: `npx tsc -b --noEmit` — clean, 0 errors, after all edits (`types/account.ts`, `AppLayout.tsx`, `CreateAccountModal.tsx`).
- **Lint**: `npx oxlint` on the three touched frontend files — 0 warnings, 0 errors.
- **Backend PHP**: no PHP binary exists on-device, so `Account.php` was checked with a comment- and string-literal-aware brace/paren/bracket balancer (skips `//`, `#`, `/* */`, and quoted strings before counting) — all three bracket types balanced (`()`: 61/61, `{}`: 22/22, `[]`: 7/7).
- Confirmed via `grep` that `COMBINED_MODULE_CHECKLIST` had zero consumers before removing it, and that `ACCOUNT_MODULES` (still imported) has three remaining live call sites in `CreateAccountModal.tsx` (the `?? [...ACCOUNT_MODULES]` null-expansion fallback in `toggleModule`/`toggleSuite`/`applyPreset`) — so removing it from the import list would have been a compile error, and lint's unused-import rule would have caught a stale import either way (0 warnings confirms it didn't).
- Not executed: no browser/dev-server was available on-device to click through the modal and visually confirm the indeterminate dash rendering or preset-button clicks — the behavior above is verified by code reading and the derived-state logic's own correctness, not by a UI screenshot. **[Unknown]** whether the tri-state checkbox renders visually as expected in this app's specific browser/OS combination — the `ref.current.indeterminate = ...` pattern is standard DOM behavior, not something specific to this codebase, so risk is low but not zero.

---

## 4. Known, disclosed follow-ups (not part of this task's literal scope)

- `device_settings` checklist row remains a no-op (superAdminOnly nav item, no `requiresModule`) — same precedent as `templates`, not a new gap.
- Server-side enforcement of `allowed_modules` still exists only for `team_management` (pre-existing gap, unrelated to this task) — toggling any other module (including the two new ones) purely hides/shows UI; a direct API call isn't blocked server-side for those.
- Core Common checkboxes remain individually uncheckable after any preset is applied (§2.2) — if "persistence" should instead mean "locked/non-removable," that would be a different, larger change (disabling those 4 checkboxes entirely) not implied by the literal spec text and not made here without confirmation.
