import axiosInstance from '../core/api/axiosInstance';

/**
 * Phase 10 Task 5 — conversion values of converted, ad-attributed CRM leads.
 * Reads use the Ads attribution list; writes go through the CRM lead endpoint
 * (CRM + Ads gates, checked again by the backend). A Super Admin's selected
 * client is attached by axiosInstance (?account_id=).
 */
export interface ConvertedAttribution {
  id: number;
  crm_lead_id: number | null;
  contact_phone: string | null;
  source_id: string | null;
  headline: string | null;
  campaign: { id: number; name: string } | null;
  converted_at: string | null;
  /** null = not recorded; 0 is a recorded value. */
  conversion_value: number | null;
  conversion_currency: string | null;
}

const adsConversionValueService = {
  listConverted() {
    return axiosInstance
      .get<{ data: ConvertedAttribution[] }>('/social/ads/attribution', { params: { converted: 1, per_page: 50 } })
      .then((res) => res.data.data);
  },

  /** value null clears it; currency is required when value is a number (0 included). */
  setValue(leadId: number, value: number | null, currency: string | null) {
    return axiosInstance
      .patch<{ changed: boolean; message: string }>(`/crm/leads/${leadId}/conversion-value`, { value, currency })
      .then((res) => res.data);
  },
};

export default adsConversionValueService;
