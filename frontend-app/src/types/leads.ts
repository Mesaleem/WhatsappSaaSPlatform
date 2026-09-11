/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Mirrors backend-api's LeadController JSON shape.
 */

export type LeadPlatform = 'facebook' | 'instagram';

export interface Lead {
  id: number;
  platform: LeadPlatform;
  form_id: string | null;
  ad_id: string | null;
  lead_name: string | null;
  lead_phone: string | null;
  lead_email: string | null;
  tenant_notified_at: string | null;
  tenant_notify_error: string | null;
  lead_welcomed_at: string | null;
  lead_welcome_error: string | null;
  created_at: string | null;
}

export interface LeadDetail extends Lead {
  raw_field_data: Record<string, unknown> | null;
}

/** Shape of a Laravel paginate() response. */
export interface PaginatedLeads {
  data: Lead[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
