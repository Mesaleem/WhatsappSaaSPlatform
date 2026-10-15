/**
 * Email preview helpers for the Mail Templates editor and the broadcast
 * composer.
 *
 * renderPreview() mirrors App\Support\TemplateRenderer::render(): `{{name}}`
 * and `{{ name }}` are both variables, and an unknown variable is left as it
 * is (so a typo stays visible). SAMPLE_VALUES mirrors
 * SystemEmailCatalog::SAMPLE_VALUES on the backend — the "Send test email"
 * button fills the same values server-side.
 */

export const SAMPLE_VALUES: Record<string, string> = {
  brand: 'WapHub',
  app_url: 'https://app.example.com/',
  support_email: 'support@example.com',
  year: String(new Date().getFullYear()),
  name: 'Jane Doe',
  email: 'jane.doe@example.com',
  phone: '+91 98765 43210',
  company_name: 'Acme Traders',
  company: 'Acme Traders',
  title: 'Acme Traders',
  code: '482916',
  minutes: '10',
  reset_url: 'https://app.example.com/reset-password?token=sample',
  login_url: 'https://app.example.com/',
  admin_url: 'https://app.example.com/admin/accounts',
  billing_url: 'https://app.example.com/billing',
  addons_url: 'https://app.example.com/add-ons',
  changed_at: '14 Oct 2026, 10:30 AM IST',
  login_time: '14 Oct 2026, 10:30 AM IST',
  signup_time: '14 Oct 2026, 10:30 AM IST',
  ip_address: '203.0.113.10',
  device: 'Chrome on Windows',
  trial_days: '7',
  trial_messages: '50',
  added_by: 'Rahul Sharma',
  plan_name: 'Growth',
  current_plan: 'Starter',
  request_type: 'Upgrade',
  invoice_number: 'INV-12-20261014-A1B2C3',
  amount: '₹1,999.00',
  valid_until: '13 Nov 2026 (IST)',
  expires_at: '21 Oct 2026, 11:59 PM',
  renewal_amount: '₹1,999',
  request_summary: '5,000 extra messages',
  reason: 'Festival campaign next week.',
  reviewer: 'The Super Admin',
  addon_name: 'Custom Contact Groups',
  units: '5',
  requester_name: 'Jane Doe',
  requester_email: 'jane.doe@example.com',
  expected_messages: '20000',
  whatsapp_numbers: '2',
  team_users: '5',
  requirement: 'We need 20,000 messages a month and two numbers.',
  // QA fixes (2026-10-14)
  alert_title: 'WhatsApp disconnected',
  alert_message: 'Your WhatsApp number 919876543210 was disconnected. Messages cannot be sent until you reconnect it.',
  action_label: 'Reconnect WhatsApp',
  action_url: 'https://app.example.com/settings/whatsapp',
  created_by: 'Rahul Sharma (Agent)',
  client_name: 'Acme Traders',
  paid_via: 'Razorpay',
};

export function renderPreview(html: string, vars: Record<string, string> = SAMPLE_VALUES): string {
  return html.replace(/\{\{\s*([^{}]*?)\s*\}\}/g, (match, key: string) =>
    Object.prototype.hasOwnProperty.call(vars, key) ? vars[key] : match,
  );
}

export interface InlineCssIssue {
  level: 'error' | 'warning';
  message: string;
}

/**
 * What an email client will drop or ignore. Gmail, Outlook and most apps
 * only apply INLINE styles (style="…"): <style> blocks and <link>
 * stylesheets are stripped, so class names style nothing, and scripts never run.
 */
export function findInlineCssIssues(html: string): InlineCssIssue[] {
  const issues: InlineCssIssue[] = [];
  const count = (re: RegExp) => (html.match(re) ?? []).length;

  if (count(/<style[\s>]/gi) > 0) {
    issues.push({ level: 'error', message: '<style> blocks are removed by most email clients. Move every style into a style="…" attribute.' });
  }
  if (count(/<link[^>]*stylesheet/gi) > 0 || count(/@import/gi) > 0) {
    issues.push({ level: 'error', message: 'External CSS (<link> / @import) is never loaded in email. Use inline style="…" only.' });
  }
  if (count(/<script[\s>]/gi) > 0) {
    issues.push({ level: 'error', message: '<script> does not run in email and may get the message marked as spam. Remove it.' });
  }
  const classes = count(/\sclass\s*=/gi);
  if (classes > 0) {
    issues.push({ level: 'warning', message: `${classes} class="…" attribute(s) found. Without a stylesheet they do nothing — use inline styles.` });
  }
  if (/display\s*:\s*(flex|grid)/i.test(html)) {
    issues.push({ level: 'warning', message: 'display:flex / grid is not supported in Outlook and some apps. Use tables for layout.' });
  }
  if (/position\s*:\s*(absolute|fixed)/i.test(html)) {
    issues.push({ level: 'warning', message: 'position:absolute / fixed is ignored by most email clients.' });
  }

  return issues;
}

/** The {{variables}} used in a text, in order of first use. */
export function usedVariables(text: string): string[] {
  const found = new Set<string>();
  for (const m of text.matchAll(/\{\{\s*([^{}]*?)\s*\}\}/g)) found.add(m[1]);
  return Array.from(found);
}
