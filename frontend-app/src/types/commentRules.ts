/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Mirrors backend-api's CommentAutomationRuleController JSON shape.
 */

export interface CommentAutomationRule {
  id: number;
  keyword: string;
  public_reply_template: string;
  private_dm_template: string;
  is_active: boolean;
  created_at: string | null;
}

export interface CommentAutomationRulePayload {
  keyword: string;
  public_reply_template: string;
  private_dm_template: string;
  is_active?: boolean;
}
