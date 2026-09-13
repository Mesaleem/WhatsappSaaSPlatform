/**
 * Module 7 — Analytics Dashboard, Message Logs & Report Exporter type
 * definitions. Mirrors AnalyticsController / MessageLogController /
 * ExportController's JSON shapes.
 */

import type { BillingModel, EngineType, SubscriptionStatus } from './subscription';
import type { PaymentAlert, PaymentAlertStatus } from './alert';

export interface DateRange {
  from: string; // YYYY-MM-DD
  to: string; // YYYY-MM-DD
}

/** 'account' = scoped to one tenant (unchanged shape). 'global' = Super Admin, no client selected — platform-wide aggregate. */
export type AnalyticsScope = 'account' | 'global';

export interface QuotaSummary {
  billing_model: BillingModel;
  engine_type: EngineType;
  total_allocated_messages: number | null;
  used_messages: number;
  /** null when billing_model === 'unlimited'. */
  remaining_messages: number | null;
  /** null when billing_model === 'unlimited'. 0-100. */
  quota_percent_used: number | null;
  subscription_status: SubscriptionStatus;
}

/** GET /api/analytics/summary */
export interface AnalyticsSummary {
  /** 'global' when Super Admin hasn't selected a client — see quota's note. */
  scope: AnalyticsScope;
  period: DateRange;
  total_alerts_attempted: number;
  total_sent: number;
  total_failed: number;
  /** 0-100, rounded to 2 decimals. 0 when no alerts were attempted yet. */
  delivered_rate: number;
  /** Decimal-cast sum, serialized as a string (e.g. "25.6000") — see subscription.ts's rate_per_message note. */
  total_cost_incurred: string;
  /** null when scope === 'global' — quota is a per-subscription concept with no platform-wide equivalent. */
  quota: QuotaSummary | null;
  /** Client Admin Dashboard Overhaul — "Today's Broadcast Metrics" card. Server-timezone "today". */
  total_sent_today: number;
  total_failed_today: number;
  /**
   * Group Messaging Phase 5 — Dashboard Analytics Upgrade. null when the
   * backend's recipient_type column isn't migrated yet (self-healing,
   * same convention as `quota` being null for the global scope — see
   * AnalyticsController::summary()'s docblock).
   */
  recipient_breakdown: RecipientTypeBreakdown | null;
  /** null when scope === 'global' (per-tenant concept, same as `quota`), or when contact_groups isn't migrated yet. */
  active_contact_groups: number | null;
  /**
   * Dashboard & Analytics Fix Round 2 — "Today's Group & Individual
   * Sent/Failed Breakdown". Same self-healing null condition as
   * `recipient_breakdown` above (re-windowed to today instead of the
   * requested period).
   */
  today_breakdown: TodayRecipientBreakdown | null;
}

/** Dashboard & Analytics Fix Round 2. Field names match AnalyticsController::summary()'s literal keys. */
export interface TodayRecipientBreakdown {
  today_individual_sent: number;
  today_individual_failed: number;
  today_group_sent: number;
  today_group_failed: number;
}

/**
 * Group Messaging Phase 5. Individual counts are exact (one row per
 * send). Group counts sum each batch's persisted success_count/
 * failure_count when available, falling back to counting resolved
 * BATCHES (not recipients) when the backend migration adding those two
 * columns hasn't run yet — see AnalyticsController::recipientTypeBreakdown()'s
 * docblock. `recipient_count` is the sum of every group batch's total
 * addressee count in range, regardless of resolution state.
 */
export interface RecipientTypeBreakdown {
  individual: { sent: number; failed: number };
  group: { sent: number; failed: number; queued_batches: number; recipient_count: number };
}

export interface DailyVolumePoint {
  date: string; // YYYY-MM-DD
  sent: number;
  failed: number;
}

export interface EngineBreakdownEntry {
  engine_type: EngineType;
  count: number;
}

export type ChartRange = '7' | '30' | 'custom';

/** GET /api/analytics/charts */
export interface AnalyticsChartsResponse {
  scope: AnalyticsScope;
  range: DateRange;
  /** Gap-filled — one entry per calendar day in range, zero-filled where there was no activity. */
  daily: DailyVolumePoint[];
  /**
   * Reflects each account's CURRENT subscription engine only, not a true
   * per-message historical attribution (the platform doesn't version
   * subscription changes — see AnalyticsController::charts()'s docblock).
   * For an account that has never switched engines this is exact. When
   * scope === 'global' this is a REAL sum across every tenant's current
   * engine (see AnalyticsController::globalEngineBreakdown()), not an
   * approximation from a single subscription.
   */
  engine_breakdown: EngineBreakdownEntry[];
  /**
   * Super Admin Dashboard Overhaul — ECG/Heartbeat chart's revenue series.
   * Gap-filled like `daily`, keyed by paid Invoice.paid_at (the only
   * dated revenue signal this schema has — see
   * AnalyticsController::globalDailyRevenue()'s docblock). null when
   * scope === 'account' — platform revenue has no per-tenant equivalent.
   * Decimal-cast sum, serialized as a string.
   */
  daily_revenue: Array<{ date: string; revenue: string }> | null;
  /**
   * Group Messaging Phase 5 — "Individual vs Group" toggle on the
   * Message Pulse chart. Gap-filled per day like `daily` above (which
   * stays unchanged, every recipient_type combined); null under the
   * same self-healing condition as `recipient_breakdown` in
   * AnalyticsSummary.
   */
  daily_by_recipient_type: {
    individual: DailyVolumePoint[];
    group: DailyVolumePoint[];
  } | null;
}

/**
 * GET /api/analytics/global-summary — Super Admin only. Mirrors
 * AnalyticsController::globalSummary()'s JSON shape. Deliberately a
 * separate, flatter type from AnalyticsSummary: there is no per-tenant
 * "quota" concept at platform scope (quota belongs to one subscription).
 */
export interface GlobalAnalyticsSummary {
  total_clients: number;
  /** Financial & Revenue Analytics — accounts with Account.status === 'active' (admin-level, independent of subscription status). */
  active_clients_count: number;
  total_messages_sent: number;
  total_messages_failed: number;
  /** 0-100, rounded to 2 decimals. 0 when no alerts were attempted platform-wide yet. */
  global_success_rate: number;
  active_whatsapp_engines: number;
  /** Super Admin Dashboard Enhancement — scoped to the server's "today" (no per-account timezone concept in this schema). */
  total_messages_sent_today: number;
  total_messages_failed_today: number;
  /** Count backing the "Expiring in 7 Days" metric card — see AccountController::expiringSoon() for the matching detail list. */
  expiring_in_7_days_count: number;
  /**
   * Financial & Revenue Analytics. All four are decimal-cast sums,
   * serialized as strings. See AnalyticsController::globalSummary()'s
   * docblock for exactly how each is computed and why
   * `current_month_revenue` can legitimately read "0.00" even when
   * `total_platform_revenue` is not (admin-provisioned subscriptions have
   * no dated payment event to bucket by month).
   */
  total_platform_revenue: string;
  current_month_revenue: string;
  pending_overdue_revenue: string;
  arpu: string;
}

/** One row of GET /api/admin/accounts/expiring-soon — Super Admin Dashboard's "Expiring in 7 Days" modal. */
export interface ExpiringSoonAccount {
  account_id: number;
  company_name: string;
  plan_label: string;
  expires_at: string;
  days_remaining: number;
}

export interface MessageLogFilters {
  search?: string;
  status?: PaymentAlertStatus;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

/** Filters shared by both export endpoints (no pagination — exports are the full filtered set). */
export type ExportFilters = Omit<MessageLogFilters, 'page' | 'per_page'>;

/**
 * GET /api/alerts/logs — a Laravel paginate() response plus `scope`
 * (MessageLogController::index() merges it onto the paginator's own
 * data/current_page/... shape).
 */
export interface MessageLogsResponse {
  data: PaymentAlert[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  scope: AnalyticsScope;
}
