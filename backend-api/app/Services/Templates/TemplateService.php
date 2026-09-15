<?php

namespace App\Services\Templates;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * Tiered Template Approval Workflow for 3-Tier Hierarchy.
 *
 * Pure status-routing + notification logic for MessageTemplateController,
 * kept out of the controller for the same reason QuotaService/AccountService
 * already are in this codebase: the "who gets routed to whom" decision is
 * reused from more than one controller action (store(), submitRequest(),
 * approve()) and is worth unit-testing in isolation from HTTP concerns.
 *
 * Business rules implemented here (see the request that created this
 * class for the literal spec):
 *   1. A Sub-Client (Account.agent_id set) submitting a request lands on
 *      'pending_agent_review', routed to its own Parent Agent.
 *   2. A direct/platform Client (agent_id null) submitting a request
 *      lands on 'pending_admin_review', routed straight to Super Admin —
 *      no Agent in the chain.
 *   3. Super Admin sees and can act on every status (enforced by
 *      MessageTemplateController's existing Super-Admin branch of
 *      approve()/reject(), which is deliberately left status-agnostic —
 *      not this service's concern).
 *   4. Super Admin or an Agent authoring a template directly (for their
 *      own account, or on a Sub-Client's behalf from the Template
 *      Manager) bypasses the two review-queue statuses above entirely.
 *
 * [Disclosed interpretation]: "approve directly based on gateway mode"
 * (rule 1) does NOT mean an Agent's approval can ever set status
 * 'approved' directly. This codebase has a pre-existing, load-bearing
 * Strict 1-Template-Per-Client & Testing Gate — MessageTemplateController
 * ::approve()'s requirement that is_super_admin_tested be true (a real
 * WhatsApp test-fire) before ANY template goes live — and that gate is a
 * content-safety/deliverability check orthogonal to the hierarchy, not a
 * business review step this feature is meant to let a hierarchy tier
 * skip. An Agent's approval therefore always forwards to a Super-Admin
 * final-review status (routeAfterAgentApproval() below): 'pending' for a
 * 'qr'-engine account (the same terminal state a Super-Admin-authored
 * template already starts at), or 'pending_meta_approval' for a
 * 'meta'-engine account — a distinctly labeled variant of the same
 * terminal state, since this codebase has no live Meta WhatsApp Template
 * Library API integration to actually call (see the migration that added
 * these statuses for the same disclosure). Both funnel into the exact
 * same, unmodified Super-Admin approve()/reject() actions.
 */
class TemplateService
{
    /**
     * The initial status for a NEW template row, given who is
     * authoring/submitting it and which account it is FOR.
     *
     * Covers both entry points that create a message_templates row:
     *   - MessageTemplateController::store() — Super Admin (any target
     *     account, including null/global) or an Agent (their own account,
     *     or one of their own Sub-Clients — ownership already asserted by
     *     the controller before this is called).
     *   - MessageTemplateController::submitRequest() — a plain Client
     *     Admin/User submitting a request for their OWN account (never
     *     Super Admin or Agent — the controller only calls this method
     *     from that endpoint with the caller's own resolved account).
     */
    public function resolveCreationStatus(User $creator, Account $targetAccount): string
    {
        if ($creator->isSuperAdmin()) {
            // Rule 4a — unchanged, pre-existing behavior.
            return 'pending';
        }

        if ($creator->account?->account_type === 'agent') {
            // Rule 4b — an Agent authoring directly (own account, or on
            // behalf of a Sub-Client from the Template Manager) bypasses
            // both review-queue statuses. Still subject to the
            // unrelated, pre-existing Super-Admin testing gate below
            // 'pending' before it can ever become 'approved'.
            return 'pending';
        }

        // Rules 1 & 2 — a plain Client submitting their own request.
        return $targetAccount->agent_id !== null ? 'pending_agent_review' : 'pending_admin_review';
    }

    /**
     * Where a template goes once its Parent Agent approves a
     * 'pending_agent_review' submission. See this class's docblock for
     * why this never returns 'approved' directly.
     */
    public function routeAfterAgentApproval(?Account $targetAccount): string
    {
        $engineType = $targetAccount?->currentSubscription?->engine_type;

        return $engineType === 'meta' ? 'pending_meta_approval' : 'pending';
    }

    /**
     * In-app notification for "this template needs your review" —
     * covers both pending_agent_review (-> the Sub-Client's Parent
     * Agent's own primary Admin/owner user) and pending_admin_review /
     * pending_meta_approval (-> every Super Admin). A no-op for any
     * other status, or for a global (account_id null) template, which
     * has no owning Agent/hierarchy to route to.
     *
     * [Disclosed, scoped]: this is the one new notification-producing
     * call site added by this feature — there is no pre-existing
     * "auto-notify on event" convention anywhere else in this codebase
     * to match (InAppNotification rows are otherwise only ever created
     * from an explicit Super Admin broadcast — see
     * NotificationBroadcastController). Kept intentionally narrow (who
     * to notify + a short title/body), not a new subsystem.
     */
    public function notifyPendingReview(MessageTemplate $template): void
    {
        $account = $template->account;

        if (! $account) {
            return;
        }

        // [Feedback fix, disclosed]: a Sub-Client's request originally
        // notified ONLY its Parent Agent for 'pending_agent_review' —
        // Super Admin could already SEE and act on it regardless (Rule
        // 3, index()/approve() are deliberately status-agnostic for
        // Super Admin), just never got a notification prompting them
        // to. Per explicit request, Super Admin is now notified
        // alongside the Agent for this status too, so a Sub-Client
        // created under an Agent effectively has two reviewers who can
        // both act (whichever gets there first "wins" — approve()/
        // reject() re-check the template's current status, so a second
        // reviewer acting after the first simply gets the "not awaiting
        // your review"/404 outcome instead of double-processing it).
        $recipients = match (true) {
            $template->status === 'pending_agent_review' && $account->agent_id !== null => User::query()
                ->where(function ($q) use ($account) {
                    $q->where(fn ($qq) => $qq->where('account_id', $account->agent_id)->whereHas('roles', fn ($qr) => $qr->where('name', 'agent')))
                        ->orWhereHas('roles', fn ($qr) => $qr->where('name', 'super_admin'));
                })
                ->get(),
            // 'pending_admin_review' — a direct Client submitted straight
            // to Super Admin (Rule 2). 'pending_meta_approval' — an Agent
            // just approved a 'meta'-engine Sub-Client submission (Rule
            // 1). Plain 'pending' is ALSO included here, but ONLY reached
            // via that same Agent-approval call site
            // (MessageTemplateController::approve()'s Agent branch, for a
            // 'qr'-engine Sub-Client) — every OTHER path that sets
            // 'pending' (store()'s Super-Admin/Agent-direct-authorship,
            // Rule 4) never calls this method at all, so a bypassed
            // template never triggers this notification, by construction
            // rather than by a status check here.
            in_array($template->status, ['pending_admin_review', 'pending_meta_approval', 'pending'], true) => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
                ->get(),
            default => collect(),
        };

        if ($recipients->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $recipients->map(fn (User $u) => [
            'user_id' => $u->id,
            'title' => 'Template awaiting your review',
            'body' => sprintf('"%s" (%s) needs your review.', $template->title, $account->company_name),
            'category' => 'template_review',
            'is_read' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        InAppNotification::insert($rows);
    }

    /**
     * In-app notification back to the submitting account's owner when a
     * template they're responsible for is rejected — by an Agent or by
     * Super Admin, either way. No-op for a global template (no single
     * account "owns" it to notify).
     */
    public function notifyRejected(MessageTemplate $template): void
    {
        $account = $template->account;
        $owner = $account?->owner;

        if (! $owner) {
            return;
        }

        InAppNotification::create([
            'user_id' => $owner->id,
            'title' => 'Template request rejected',
            'body' => $template->rejection_reason
                ? sprintf('"%s" was rejected: %s', $template->title, $template->rejection_reason)
                : sprintf('"%s" was rejected.', $template->title),
            'category' => 'template_review',
            'is_read' => false,
        ]);
    }
}
