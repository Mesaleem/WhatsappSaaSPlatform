# MODULE 5 FIX — SA Direct Client Agent Field Verification & Agent Subscription Overview
**Date:** 2026-09-16
**Scope Lock honored:** `AccountController.php` & Subscription/Account Services ONLY. `Account.php`, `QuotaService.php`, and `routes/api.php` were read for audit context but not modified (verified below).

---

## 1. AUDIT FINDING (How `agent_id` and Agent subscription overview currently function)

**[Fact]** `index()` (`GET /api/admin/accounts`) already eager-loads `->with(['currentSubscription', 'owner:id,name,email,account_id', 'agent:id,company_name,account_type'])`, and `show()` (`GET /api/admin/accounts/{id}`) already eager-loads the same `agent:id,company_name,account_type`. `agent_id` itself is not in `Account::$hidden` (only `gemini_api_key` is), so it serializes on every response regardless.

**[Fact, correcting the task's premise]** The task asks for `agent:id,name,email`. Neither `name` nor `email` exists on the `accounts` table — `accounts.name` was renamed to `accounts.company_name` in `2026_09_08_100521_update_accounts_table_for_provisioning.php`, and `accounts` has never had an `email` column (an Account's contact email lives on its owner `User`, via the separate `owner` relation, not on the Agent's Account row itself). Eager-loading `'agent:id,name,email'` as literally requested would generate `SELECT id, name, email FROM accounts ...` and fail with an unknown-column SQL error. The existing `agent:id,company_name,account_type` is the schema-correct equivalent, and I left it as-is rather than "fixing" it to match an invalid field list.

**[Fact]** `store()`'s and `update()`'s validation (`Rule::exists('accounts', 'id')->where('account_type', 'agent')`) already correctly restricts `agent_id` to point only at a real Agent account, and `update()` already has purpose-built promotion/demotion handling (promoting a Client to Agent force-clears its `agent_id`; demoting an Agent back to Client is refused with a 422 if it still has its own Sub-Clients). This is all real, working, pre-existing logic.

**[Fact — the one real gap found]** Neither `store()` nor `update()` stops an account whose `account_type` **is** (or is becoming) `'agent'` from **also** having a non-null `agent_id` set on itself. The existing validation only checks that `agent_id` *points at* a real Agent — it never checked that the account being written *isn't itself* an Agent. This directly contradicts this same file's own documented, load-bearing invariant, stated in two separate comments already in the code ("Agents do not create other Agents in this phase" — `store()`; "An Agent is always top-level, directly under Super Admin — never itself a Sub-Client of another Agent" — `update()`'s promotion branch). A Super Admin could submit `{"account_type":"agent","agent_id":5}` at creation, or `{"agent_id":5}` on an account that's already an Agent, and create a nested Agent-under-Agent that `scopeOwnedByAgent()`, module delegation, and quota-pool logic elsewhere in this codebase are not designed to handle. There is no DB-level foreign key or check constraint backing this rule either (confirmed in an earlier audit this session: "agent_id has no DB-level FK on this project"), so the application layer is the only place this can be enforced — and it wasn't, in these two specific spots. Fixed (Section 4).

**[Fact]** "Agent Subscription Overview" is not a separate/dedicated endpoint — it's `index()`/`expiringSoon()` themselves, each already eager-loading `currentSubscription` (plan = `billing_model`/`engine_type`, quota = `total_allocated_messages`/`used_messages`) and already scoped via `->when($agentScopeId, fn ($query, $scopeId) => $query->ownedByAgent($scopeId))`. `show()` goes further, additionally exposing `effective_modules` and — for an Agent caller specifically — `agent_remaining_pool` (how much of the Agent's own quota pool remains, via `QuotaService::remainingPool()`).

---

## 2. DIRECT SA CLIENT AGENT FIELD IMPLEMENTATION/VERIFICATION

**Already correct, verified, no change needed:** `agent_id` (raw FK) and the `agent` relation (`id`, `company_name`, `account_type` — the real, existing columns) are present on every `index()` row and every `show()` response.

**Fixed (the real gap):** added an explicit `abort_if(..., 422, 'An Agent account cannot itself be assigned to a parent Agent.')` guard in both `store()` and `update()` so a Super Admin can no longer create or edit an Agent-type account into having a non-null `agent_id` of its own — see Section 1 for why this was missing and Section 4 for the exact diffs.

**Additive consistency fix (not a bug, but a real gap in the objective's spirit):** `store()`'s and `update()`'s own JSON responses (as opposed to a follow-up `index()`/`show()` call) did **not** eager-load `agent` — meaning a Super Admin creating or reassigning a client's Agent got the raw `agent_id` back but not the resolved Agent's `company_name`/`account_type`, forcing a second request to see it. Added `'agent:id,company_name,account_type'` to both responses' eager-load list, matching `index()`/`show()` exactly.

---

## 3. AGENT SUBSCRIPTION OVERVIEW SCOPING VERIFICATION

Traced every relevant path:

- `index()`: `->when($agentScopeId, fn ($query, $scopeId) => $query->ownedByAgent($scopeId))` — an Agent caller's `$agentScopeId` (from `callerAgentScopeId()`, non-null only when the caller's own account is an Agent) unconditionally restricts the query to `WHERE agent_id = <caller's own account_id>`. A Super Admin's `?agent_id=` query param is a *separate*, explicitly Super-Admin-only `->when($isSuperAdmin && ..., ...)` branch — an Agent's own request can never widen its scope by passing that param (confirmed: the Agent-forced `ownedByAgent($agentScopeId)` `when()` clause is unconditional and independent of any query param the Agent submits).
- `expiringSoon()`: identical `->when($agentScopeId, fn ($query, $scopeId) => $query->ownedByAgent($scopeId))` guard.
- `show()`: `assertCallerCanAccessAccount()` — `abort_if($agentScopeId !== null && $account->agent_id !== $agentScopeId, 404)` — an Agent requesting a specific account id that isn't its own Sub-Client (or itself) gets a 404, not the data.
- `updateSubscription()`/`updateQuota()`: both also call `assertCallerCanAccessAccount()` first (confirmed by direct read), so quota/subscription mutations are equally scoped.

**No leak found. No change needed for this objective.**

---

## 4. FILES MODIFIED

| File | Change |
|---|---|
| `backend-api/app/Http/Controllers/Api/AccountController.php` | Modified — 2 new validation guards (`store()`, `update()`) closing the Agent-nesting gap, plus 2 additive eager-load additions (`store()`'s and `update()`'s responses) for Agent-relation consistency. +49 insertions / -2 deletions (the 2 "deletions" are the two single-line response statements each replaced by the same line plus a new field — not a behavior change to the pre-existing fields, only an addition). |
| `backend-api/app/Models/Account.php` | **Not modified** — read only, to confirm `agent_id`/`account_type`/`$fillable`/`$hidden`/`scopeOwnedByAgent()`/`scopeAgentsOnly()` are already exactly what's needed. |
| `backend-api/app/Services/QuotaService.php` | **Not modified** — read only (`remainingPool()` already correctly used by `show()`). |
| `backend-api/routes/api.php` | **Not modified by this task** (its pre-existing +33/-0 from an earlier session is unchanged — confirmed via `git diff --stat`). |

---

## 5. PAYLOAD STRUCTURE VERIFICATION

Traced both the "already correct" and "now fixed" paths:

- `index()`/`show()` row shape (unchanged by this task): `{ ..., "agent_id": 7, "agent": { "id": 7, "company_name": "Acme Resellers", "account_type": "agent" }, "currentSubscription": { "billing_model": ..., "engine_type": ..., "total_allocated_messages": ..., "used_messages": ..., "status": ... }, ... }` — already present before this task.
- `store()`/`update()` response shape (changed by this task): now additionally includes the same `"agent": {...}` object described above, alongside the pre-existing `currentSubscription`/`owner` — additive only, no existing key removed or renamed.
- New rejection path (added by this task): `POST /api/admin/accounts` with `{"account_type":"agent","agent_id":5}`, or `PUT /api/admin/accounts/{id}` with `{"agent_id":5}` on an account that's already an Agent, now returns `422 {"message": "An Agent account cannot itself be assigned to a parent Agent."}` instead of silently creating an invalid nested hierarchy.
- Unaffected, verified by inspection: promoting a Client to Agent (`{"account_type":"agent"}` with no `agent_id`, or with a stray `agent_id` alongside it) still succeeds and still silently clears `agent_id` to `null`, exactly as before — my new `update()` guard's `! $isConvertingType` condition deliberately excludes this path, so it is untouched.

**Cannot execute an actual HTTP request from this environment** (no PHP/DB access) — this is a code-path trace, disclosed as such, not an executed test.

---

## 6. DATABASE SAFETY CONFIRMATION

No migration, no `migrate:fresh`/`db:wipe`/`TRUNCATE`/`DROP TABLE`. **No migration required** — `accounts.agent_id` and `accounts.account_type` are already indexed (confirmed in an earlier audit this session, migration `2026_09_14_090000_add_account_type_and_agent_id_to_accounts_table.php`), and this task added application-layer validation only, no new column or query shape.

---

## 7. SYNTAX / INTEGRITY CHECK

**PHP CLI remains unavailable in this environment** (`which php` → not found) — disclosed, not assumed passing.

- Brace/paren/bracket balance verified after the edit: `{}` 53/53, `()` 356/356, `[]` 125/125 — balanced.
- Manual proofread of all 4 hunks (reproduced in Section 8): confirmed the new `store()` guard sits after both `$data['account_type']`/`$data['agent_id']` are finalized (so it sees the true final values, not pre-defaulted ones) and before the module-delegation logic that follows; confirmed the new `update()` guard sits after `$newType`/`$isConvertingType` are computed but before the existing `if ($isConvertingType)` block, and that its `! $isConvertingType` condition is the exact complement of that block's own `$isConvertingType` check, so the two guards' conditions cannot both fire on the same request (no double-abort, no dead code).
- Confirmed the two new eager-load additions are pure string additions to an existing array literal (`'agent:id,company_name,account_type'` — the exact same string already used in `index()`/`show()`, copy-pasted for consistency, not retyped).

**Not run** (same PHP-unavailability reason): `php artisan test`, a live HTTP request. **Recommend testing**: (1) create an Agent with no `agent_id` — succeeds, response includes `"agent": null`; (2) create with `{"account_type":"agent","agent_id":<real agent id>}` — 422; (3) `PUT` an existing Agent account with `{"agent_id":<real agent id>}` — 422; (4) promote an existing Client to Agent while a stray `agent_id` is also in the payload — succeeds, `agent_id` silently becomes `null` (unchanged pre-existing behavior); (5) Agent reassignment between two different Agents on a Client account (`{"agent_id": <different agent's id>}`, no `account_type` in payload) — still succeeds, unaffected by either new guard.

---

## 8. GIT DIFF SUMMARY

```
$ git diff -w --stat -- backend-api/app/Http/Controllers/Api/AccountController.php
 backend-api/app/Http/Controllers/Api/AccountController.php | 51 +++++++++++++++++++++-
 1 file changed, 49 insertions(+), 2 deletions(-)
```

Full diff (all 4 hunks) reviewed inline during this audit; reproduced in condensed form:
1. `store()`: new `abort_if($data['account_type'] === 'agent' && $data['agent_id'] !== null, 422, ...)` inserted after the account_type/agent_id resolution block, before module-delegation logic.
2. `store()`: response eager-load gains `'agent:id,company_name,account_type'`.
3. `update()`: new `abort_if($newType === 'agent' && ! $isConvertingType && array_key_exists('agent_id', $data) && $data['agent_id'] !== null, 422, ...)` inserted after `$isConvertingType`/`$newType`, before the existing `if ($isConvertingType)` block.
4. `update()`: response eager-load gains `'agent:id,company_name,account_type'`.

`git status --short`/`git diff --stat` confirm `Account.php`, `QuotaService.php` show zero changes from this task; `routes/api.php` shows only its pre-existing, unrelated +33/-0 from an earlier session.

---

## 9. REMAINING OBSERVATIONS

- **No dedicated "Agent subscription overview" aggregate endpoint exists** (e.g., a single summary object like "12 active clients, 340,000 of 500,000 pooled messages used"). The task's Section 3 only asked me to *verify the existing scoping*, not build a new aggregate, so I did not add one — the per-client data (`index()`'s list, each with `currentSubscription`) is already complete and correctly scoped, just not pre-aggregated into a single summary figure. If you want a literal "Agent Subscription Overview" dashboard card/endpoint (total active clients, combined quota used/remaining across the Agent's whole portfolio), that's a genuinely new, larger feature I'd want to scope out separately rather than fold into this verification task.
- The two new `abort_if` guards are deliberately asymmetric (silent-clear on promotion vs. explicit-reject when already an Agent) — see the inline comment reasoning in Section 4/8. If you'd prefer both paths to behave identically (either both silent-clear or both reject), that's a one-line change to make, but I chose the more defensible default rather than guessing you wanted uniformity without being asked.
- As in every prior report this session: PHP CLI and live database access remain unavailable from this environment, so Sections 5 and 7's checks are code-path traces backed by direct reads of the actual source, not executed tests.

---

## 10. FINAL VERIFICATION

- [x] Audited `agent_id`/`agent` relation eager-loading in `index()`, `show()`, `store()`, `update()`.
- [x] Corrected the task's factually-incorrect premise (`name`/`email` don't exist on `accounts`) rather than blindly applying an invalid field list.
- [x] Confirmed `agent_id` is not hidden and serializes on every response.
- [x] Found and fixed a real gap: an Agent-type account could be given a non-null `agent_id` of its own in both `store()` and `update()`, contradicting this codebase's own documented "Agents are always top-level" invariant.
- [x] Added a consistency fix so `store()`/`update()`'s own responses include the resolved `agent` relation, not just `index()`/`show()`.
- [x] Verified Agent subscription-overview scoping (`index()`, `expiringSoon()`, `show()`, `updateSubscription()`, `updateQuota()`) all correctly restrict to the caller's own Sub-Clients — no cross-agent leak found.
- [x] No database mutation, migration, or destructive command; confirmed existing indexes are sufficient.
- [x] No file outside `AccountController.php` modified.
- [x] `git diff -w --stat` confirms an isolated, minimal, additive change.
- [ ] `php -l` / live HTTP test — **not executed, PHP CLI and DB access unavailable in this environment**; five concrete test cases recommended in Section 7.

**Bottom line:** Both objectives were mostly already correctly implemented. The one real, evidence-based gap — an Agent account could be assigned its own `agent_id`, nesting Agents in a way nothing else in this codebase is built to handle — is now closed with two narrowly-targeted, minimal validation guards, plus a small response-consistency improvement so Super Admin create/update calls show the resolved Agent relationship without a follow-up request.
