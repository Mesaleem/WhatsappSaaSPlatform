import type { CustomPlanPriceList } from '../../types/plan';

/** The backend defaults (App\Services\Billing\CustomPlanPricing::DEFAULTS), for tests. */
export const DEFAULT_PRICES: CustomPlanPriceList = {
  base_fee: 299,
  base_users_included: 2,
  per_user: 100,
  unlimited_users: 1500,
  engine: { qr: 300, meta: 499, none: 0 },
  per_message: 0.2,
  per_marketing_message: 1.1,
  features: { crm: 500, journey_automation: 300, ads: 500, social: 300, external_api: 100, ai: 300, whatsapp_groups: 200, email: 100, payments: 100, commerce: 300, custom_code: 200, billing_collections: 200 },
  per_template: 10,
  unlimited_templates: 500,
  per_journey: 50,
  unlimited_journeys: 1000,
  per_broadcast: 25,
  unlimited_broadcasts: 1000,
  meta_marketing_cost: 0.8631,
  meta_utility_cost: 0.115,
  gst_percent: 18,
};
