<?php

namespace App\Services\Mail;

/**
 * The platform's master (system) email templates — one entry per automatic
 * email the platform sends (signup code, welcome, password reset, plan and
 * add-on requests, expiry reminders, ...).
 *
 * This list is used twice:
 *   - the 2026_10_14_150000 migration inserts every entry into
 *     notification_templates (is_system = 1) when its slug is missing, so a
 *     fresh server gets them without a seeder;
 *   - SystemMailer falls back to the entry here when the database row is
 *     missing or switched off, so an automatic email is never lost.
 *
 * Bodies are complete HTML documents with INLINE styles only (email clients
 * drop <style> blocks and never load external CSS) and table layout, in the
 * website's brand: emerald #059669 / teal #0f766e, slate text, white card.
 * `{{variable}}` tokens are filled by SystemMailer; values are HTML-escaped
 * unless the caller passes them as trusted HTML.
 */
final class SystemEmailCatalog
{
    /** Variables every system email gets automatically. */
    public const GLOBAL_VARIABLES = [
        'brand' => 'Platform name (Mail Settings "From name")',
        'app_url' => 'Address of the web app',
        'support_email' => 'Support email (Website Content, else the From address)',
        'year' => 'Current year',
    ];

    /**
     * Sample values for previews and test emails. The frontend editor keeps
     * the same list (frontend-app/src/pages/notifications/emailPreview.ts).
     */
    public const SAMPLE_VALUES = [
        'name' => 'Jane Doe',
        'email' => 'jane.doe@example.com',
        'phone' => '+91 98765 43210',
        'company_name' => 'Acme Traders',
        'company' => 'Acme Traders',
        'title' => 'Acme Traders',
        'code' => '482916',
        'minutes' => '10',
        'reset_url' => 'https://app.example.com/reset-password?token=sample',
        'login_url' => 'https://app.example.com/',
        'admin_url' => 'https://app.example.com/admin/accounts',
        'billing_url' => 'https://app.example.com/billing',
        'addons_url' => 'https://app.example.com/add-ons',
        'changed_at' => '14 Oct 2026, 10:30 AM IST',
        'login_time' => '14 Oct 2026, 10:30 AM IST',
        'signup_time' => '14 Oct 2026, 10:30 AM IST',
        'ip_address' => '203.0.113.10',
        'device' => 'Chrome on Windows',
        'trial_days' => '7',
        'trial_messages' => '50',
        'added_by' => 'Rahul Sharma',
        'plan_name' => 'Growth',
        'current_plan' => 'Starter',
        'request_type' => 'Upgrade',
        'invoice_number' => 'INV-12-20261014-A1B2C3',
        'amount' => '₹1,999.00',
        'valid_until' => '13 Nov 2026 (IST)',
        'expires_at' => '21 Oct 2026, 11:59 PM',
        'renewal_amount' => '₹1,999',
        'request_summary' => '5,000 extra messages',
        'reason' => 'Festival campaign next week.',
        'reviewer' => 'The Super Admin',
        'addon_name' => 'Custom Contact Groups',
        'units' => '5',
        'requester_name' => 'Jane Doe',
        'requester_email' => 'jane.doe@example.com',
        'expected_messages' => '20000',
        'whatsapp_numbers' => '2',
        'team_users' => '5',
        'requirement' => 'We need 20,000 messages a month and two numbers.',
        // QA fixes (2026-10-14)
        'alert_title' => 'WhatsApp disconnected',
        'alert_message' => 'Your WhatsApp number 919876543210 was disconnected. Messages cannot be sent until you reconnect it.',
        'action_label' => 'Reconnect WhatsApp',
        'action_url' => 'https://app.example.com/settings/whatsapp',
        'created_by' => 'Rahul Sharma (Agent)',
        'client_name' => 'Acme Traders',
        'paid_via' => 'Razorpay',
    ];

    /** @return array<string, array{name: string, category: string, description: string, subject: string, body: string, variables: array<string, string>, sensitive?: bool}> */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------------------- Authentication
            'auth.signup_otp' => [
                'name' => 'Signup verification code (OTP)',
                'category' => 'Authentication',
                'description' => 'Sent when someone signs up on the website. Holds the 6-digit code.',
                'subject' => '{{code}} is your {{brand}} verification code',
                'variables' => ['code' => '6-digit code', 'minutes' => 'Minutes the code is valid'],
                'sensitive' => true,
                'body' => self::layout(
                    'Verify your email',
                    '<p style="margin:0 0 12px;">Use this code to finish creating your account:</p>'
                    .'<p style="margin:18px 0;font-size:32px;font-weight:bold;letter-spacing:8px;color:#047857;">{{code}}</p>'
                    .'<p style="margin:0;color:#64748b;">The code expires in {{minutes}} minutes. If you did not try to sign up, ignore this email.</p>',
                    'This is an automatic security email from {{brand}}. Please do not reply.',
                ),
            ],
            'auth.password_reset' => [
                'name' => 'Forgot password (reset link)',
                'category' => 'Authentication',
                'description' => 'Sent from the "Forgot password" page.',
                'subject' => 'Reset your {{brand}} password',
                'variables' => ['name' => 'User name', 'reset_url' => 'Password reset link', 'minutes' => 'Minutes the link is valid'],
                'sensitive' => true,
                'body' => self::layout(
                    'Reset your password',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">We received a request to reset the password for your account. Click the button below to choose a new one.</p>'
                    .self::button('Reset password', '{{reset_url}}')
                    .'<p style="margin:0 0 12px;color:#64748b;font-size:13px;">This link expires in {{minutes}} minutes. If you did not ask for this, ignore this email — your password stays the same.</p>'
                    .'<p style="margin:0;color:#94a3b8;font-size:12px;word-break:break-all;">{{reset_url}}</p>',
                    'This is an automatic security email from {{brand}}. Please do not reply.',
                ),
            ],
            'auth.password_changed' => [
                'name' => 'Password changed',
                'category' => 'Authentication',
                'description' => 'Sent after a password is changed or reset.',
                'subject' => 'Your {{brand}} password was changed',
                'variables' => ['name' => 'User name', 'changed_at' => 'Date and time (IST)', 'ip_address' => 'IP address, or "unknown"', 'reset_url' => 'Forgot-password page'],
                'body' => self::layout(
                    'Your password was changed',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">The password for your account was changed on <b>{{changed_at}}</b> from IP <b>{{ip_address}}</b>.</p>'
                    .'<p style="margin:0 0 12px;">All other devices have been logged out.</p>'
                    .'<p style="margin:0;color:#b91c1c;"><b>Not you?</b> Reset your password right away and contact us.</p>'
                    .self::button('Reset my password', '{{reset_url}}'),
                    'This is an automatic security email from {{brand}}. Please do not reply.',
                ),
            ],
            'auth.login_alert' => [
                'name' => 'Login security alert',
                'category' => 'Authentication',
                'description' => 'Sent when a new login signs every other device out.',
                'subject' => 'Security alert: all devices were logged out of your {{brand}} account',
                'variables' => ['name' => 'User name', 'login_time' => 'Date and time (IST)', 'device' => 'Browser and system', 'ip_address' => 'IP address', 'reset_url' => 'Forgot-password page'],
                'body' => self::layout(
                    'All your devices were logged out',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">Your account was logged in on one more device than allowed, so <b>every other device was logged out</b>. The new login:</p>'
                    .self::details(['When' => '{{login_time}}', 'Device' => '{{device}}', 'IP address' => '{{ip_address}}'])
                    .'<p style="margin:0 0 12px;">If this was you, you can ignore this email.</p>'
                    .'<p style="margin:0;color:#b91c1c;"><b>Not you?</b> Change your password now. Every device will be logged out.</p>'
                    .self::button('Change my password', '{{reset_url}}'),
                    'This is an automatic security email from {{brand}}. Please do not reply.',
                ),
            ],

            // ---------------------------------------------------------------- Onboarding
            'onboarding.welcome' => [
                'name' => 'Welcome (new signup)',
                'category' => 'Onboarding',
                'description' => 'Sent to a customer right after they sign up on the website.',
                'subject' => 'Welcome to {{brand}}, {{name}}!',
                'variables' => ['name' => 'User name', 'company_name' => 'Business name', 'email' => 'Login email', 'trial_days' => 'Free trial days', 'trial_messages' => 'Free trial messages', 'login_url' => 'Login page'],
                'body' => self::layout(
                    'Welcome aboard, {{name}}!',
                    '<p style="margin:0 0 12px;">Thank you for signing up <b>{{company_name}}</b> on {{brand}}. Your account is ready.</p>'
                    .'<p style="margin:0 0 12px;">Your free trial includes <b>{{trial_messages}} messages</b> for <b>{{trial_days}} days</b>. Here is how to get started:</p>'
                    .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 4px;font-size:14px;">'
                    .self::step('1', 'Connect your WhatsApp number from <b>WhatsApp Setup</b>.')
                    .self::step('2', 'Import your contacts or add a contact group.')
                    .self::step('3', 'Send your first message or build a chatbot journey.')
                    .'</table>'
                    .self::button('Go to my dashboard', '{{login_url}}')
                    .'<p style="margin:0;color:#64748b;font-size:13px;">You log in with <b>{{email}}</b>. Questions? Write to {{support_email}}.</p>',
                ),
            ],
            'onboarding.admin_new_signup' => [
                'name' => 'New signup alert (Super Admin)',
                'category' => 'Onboarding',
                'description' => 'Sent to every Super Admin when a customer signs up on the website.',
                'subject' => 'New signup: {{company_name}}',
                'variables' => ['name' => 'Customer name', 'company_name' => 'Business name', 'email' => 'Customer email', 'phone' => 'Customer phone', 'trial_days' => 'Free trial days', 'signup_time' => 'Date and time (IST)', 'admin_url' => 'Clients page'],
                'body' => self::layout(
                    'A new customer just signed up',
                    '<p style="margin:0 0 12px;">A new account was created from the website:</p>'
                    .self::details(['Business' => '{{company_name}}', 'Name' => '{{name}}', 'Email' => '{{email}}', 'Phone' => '{{phone}}', 'Signed up' => '{{signup_time}}', 'Plan' => 'Free trial ({{trial_days}} days)'])
                    .self::button('Open clients', '{{admin_url}}'),
                ),
            ],
            // QA fixes (2026-10-14) — a client created by the Super Admin or an agent is told how to log in.
            'onboarding.client_created' => [
                'name' => 'Account created for you',
                'category' => 'Onboarding',
                'description' => 'Sent to the new client admin when a Super Admin or an agent creates their account.',
                'subject' => 'Your {{brand}} account for {{company_name}} is ready',
                'variables' => ['name' => 'Admin name', 'company_name' => 'Business name', 'email' => 'Login email', 'created_by' => 'Who created it', 'login_url' => 'Login page', 'reset_url' => 'Forgot-password page'],
                'body' => self::layout(
                    'Welcome — your account is ready',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">{{created_by}} has created a {{brand}} account for <b>{{company_name}}</b>. Log in with this email address:</p>'
                    .self::details(['Login email' => '{{email}}'])
                    .'<p style="margin:0 0 12px;">Use the password you were given. If you do not have it, set a new one with "Forgot password".</p>'
                    .self::button('Log in', '{{login_url}}')
                    .'<p style="margin:0;color:#64748b;font-size:13px;">Forgot your password? <a href="{{reset_url}}" style="color:#047857;">Set a new one</a>.</p>',
                ),
            ],
            'team.member_added' => [
                'name' => 'Team member added',
                'category' => 'Onboarding',
                'description' => 'Sent to a person when a client admin adds them to their team.',
                'subject' => 'You have been added to {{company_name}} on {{brand}}',
                'variables' => ['name' => 'New member name', 'company_name' => 'Business name', 'added_by' => 'Who added them', 'email' => 'Login email', 'login_url' => 'Login page', 'reset_url' => 'Forgot-password page'],
                'body' => self::layout(
                    'Welcome to the team, {{name}}!',
                    '<p style="margin:0 0 12px;"><b>{{added_by}}</b> added you to <b>{{company_name}}</b> on {{brand}}.</p>'
                    .'<p style="margin:0 0 12px;">Log in with <b>{{email}}</b> and the password your admin shared with you.</p>'
                    .self::button('Log in', '{{login_url}}')
                    .'<p style="margin:0;color:#64748b;font-size:13px;">Did not get a password? Use <a href="{{reset_url}}" style="color:#047857;">Forgot password</a> to set your own.</p>',
                ),
            ],

            // ---------------------------------------------------------------- Plans & billing
            'billing.plan_request_received' => [
                'name' => 'Plan request received (customer)',
                'category' => 'Billing',
                'description' => 'Sent to the customer when they request a plan (upgrade, renewal or new plan) to pay offline.',
                'subject' => 'We received your {{plan_name}} plan request',
                'variables' => ['name' => 'User name', 'company_name' => 'Business name', 'plan_name' => 'Requested plan', 'request_type' => 'Upgrade / Renewal / New plan', 'invoice_number' => 'Invoice number', 'amount' => 'Amount to pay', 'billing_url' => 'Billing page'],
                'body' => self::layout(
                    'Your plan request is in',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">We have received your request for the <b>{{plan_name}}</b> plan. An invoice has been created for it:</p>'
                    .self::details(['Request' => '{{request_type}}', 'Plan' => '{{plan_name}}', 'Invoice' => '{{invoice_number}}', 'Amount' => '{{amount}}'])
                    .'<p style="margin:0 0 12px;">Pay it by bank transfer, UPI or cash. Your plan starts as soon as the payment is recorded. You can also pay online or cancel the request from the Billing page.</p>'
                    .self::button('Open billing', '{{billing_url}}'),
                ),
            ],
            'billing.plan_request_admin' => [
                'name' => 'Plan request alert (Super Admin / Agent)',
                'category' => 'Billing',
                'description' => 'Sent to Super Admins (and the client\'s agent) when a client requests a plan.',
                'subject' => 'Plan {{request_type}} request: {{company_name}} → {{plan_name}}',
                'variables' => ['company_name' => 'Client', 'requester_name' => 'Who asked', 'requester_email' => 'Their email', 'plan_name' => 'Requested plan', 'current_plan' => 'Plan running now', 'request_type' => 'Upgrade / Renewal / New plan', 'invoice_number' => 'Invoice number', 'amount' => 'Amount', 'admin_url' => 'Billing page'],
                'body' => self::layout(
                    'New plan request',
                    '<p style="margin:0 0 12px;"><b>{{company_name}}</b> has requested a plan change and is waiting for payment to be recorded:</p>'
                    .self::details(['Request' => '{{request_type}}', 'Current plan' => '{{current_plan}}', 'Requested plan' => '{{plan_name}}', 'Invoice' => '{{invoice_number}}', 'Amount' => '{{amount}}', 'Requested by' => '{{requester_name}} ({{requester_email}})'])
                    .self::button('Review in billing', '{{admin_url}}'),
                ),
            ],
            // QA fixes (2026-10-14) — the client hears about an invoice issued for it.
            'billing.invoice_issued' => [
                'name' => 'Invoice issued (customer)',
                'category' => 'Billing',
                'description' => 'Sent to the account owner when an invoice is created for them (for example a custom plan the Super Admin built).',
                'subject' => 'Invoice {{invoice_number}} for your {{plan_name}} plan',
                'variables' => ['name' => 'Owner name', 'company_name' => 'Business name', 'plan_name' => 'Plan', 'invoice_number' => 'Invoice number', 'amount' => 'Amount to pay', 'billing_url' => 'Billing page'],
                'body' => self::layout(
                    'An invoice is ready for you',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">An invoice has been created for <b>{{company_name}}</b>:</p>'
                    .self::details(['Plan' => '{{plan_name}}', 'Invoice' => '{{invoice_number}}', 'Amount' => '{{amount}}'])
                    .'<p style="margin:0 0 12px;">Pay it online from the Billing page, or by bank transfer, UPI or cash. Your plan starts as soon as the payment is recorded.</p>'
                    .self::button('Open billing', '{{billing_url}}'),
                ),
            ],
            'billing.payment_received_admin' => [
                'name' => 'Payment received (Super Admin / Agent)',
                'category' => 'Billing',
                'description' => 'Sent to Super Admins (and the client\'s agent) when a client\'s plan invoice is paid.',
                'subject' => 'Payment received: {{company_name}} → {{plan_name}}',
                'variables' => ['company_name' => 'Client', 'plan_name' => 'Plan', 'invoice_number' => 'Invoice number', 'amount' => 'Amount paid', 'paid_via' => 'How it was paid', 'valid_until' => 'Plan end date (IST)', 'admin_url' => 'Clients page'],
                'body' => self::layout(
                    'Payment received',
                    '<p style="margin:0 0 12px;"><b>{{company_name}}</b> has paid and its plan is active:</p>'
                    .self::details(['Plan' => '{{plan_name}}', 'Invoice' => '{{invoice_number}}', 'Amount' => '{{amount}}', 'Paid via' => '{{paid_via}}', 'Valid until' => '{{valid_until}}'])
                    .self::button('Open clients', '{{admin_url}}'),
                ),
            ],
            'billing.plan_activated' => [
                'name' => 'Payment received — plan active',
                'category' => 'Billing',
                'description' => 'Sent to the account owner when a plan invoice is paid and the plan starts.',
                'subject' => 'Payment received — your {{plan_name}} plan is active',
                'variables' => ['name' => 'Owner name', 'company_name' => 'Business name', 'plan_name' => 'Plan', 'invoice_number' => 'Invoice number', 'amount' => 'Amount paid', 'valid_until' => 'Plan end date (IST)', 'billing_url' => 'Billing page'],
                'body' => self::layout(
                    'Thank you for your payment',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">We have received your payment. The <b>{{plan_name}}</b> plan is now active for <b>{{company_name}}</b>.</p>'
                    .self::details(['Plan' => '{{plan_name}}', 'Invoice' => '{{invoice_number}}', 'Amount paid' => '{{amount}}', 'Valid until' => '{{valid_until}}'])
                    .self::button('View invoice', '{{billing_url}}'),
                ),
            ],
            'billing.plan_expiring' => [
                'name' => 'Plan expiring soon',
                'category' => 'Billing',
                'description' => 'Sent to the account owner a few days before the plan ends.',
                'subject' => 'Your {{brand}} plan expires on {{expires_at}}',
                'variables' => ['name' => 'Owner name', 'company_name' => 'Business name', 'plan_name' => 'Plan', 'expires_at' => 'End date and time (IST)', 'renewal_amount' => 'Renewal amount', 'billing_url' => 'Billing page'],
                'body' => self::layout(
                    'Your plan expires soon',
                    '<p style="margin:0 0 12px;">Hello {{name}},</p>'
                    .'<p style="margin:0 0 12px;">Your plan ends on <strong>{{expires_at}}</strong>. Renew before then so your messages keep going out without a break.</p>'
                    .self::details(['Account' => '{{company_name}}', 'Plan' => '{{plan_name}}', 'Term ends' => '{{expires_at}} (IST)', 'Renewal amount' => '{{renewal_amount}}'])
                    .self::button('Renew now', '{{billing_url}}'),
                    'You are receiving this because you are the owner of this {{brand}} account.',
                ),
            ],
            'billing.plan_expired' => [
                'name' => 'Plan expired',
                'category' => 'Billing',
                'description' => 'Sent to the account owner when the plan has ended.',
                'subject' => 'Your {{brand}} plan has expired. Renew to resume sending',
                'variables' => ['name' => 'Owner name', 'company_name' => 'Business name', 'plan_name' => 'Plan', 'expires_at' => 'End date and time (IST)', 'renewal_amount' => 'Renewal amount', 'billing_url' => 'Billing page'],
                'body' => self::layout(
                    'Your plan has expired',
                    '<p style="margin:0 0 12px;">Hello {{name}},</p>'
                    .'<p style="margin:0 0 12px;">Your plan ended on <strong>{{expires_at}}</strong>. Sending is paused until you renew. Renew now to resume.</p>'
                    .self::details(['Account' => '{{company_name}}', 'Plan' => '{{plan_name}}', 'Term ends' => '{{expires_at}} (IST)', 'Renewal amount' => '{{renewal_amount}}'])
                    .self::button('Renew now', '{{billing_url}}', '#dc2626'),
                    'You are receiving this because you are the owner of this {{brand}} account.',
                ),
            ],
            'billing.quota_request_received' => [
                'name' => 'Quota top-up request received (customer)',
                'category' => 'Billing',
                'description' => 'Sent to the customer when they request extra messages or a wallet top-up.',
                'subject' => 'We received your quota top-up request',
                'variables' => ['name' => 'User name', 'company_name' => 'Business name', 'request_summary' => 'What was asked for', 'reason' => 'Their note', 'reviewer' => 'Who reviews it', 'billing_url' => 'Billing page'],
                'body' => self::layout(
                    'Your top-up request is in',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">We have received your request for <b>{{request_summary}}</b>. {{reviewer}} will review it shortly and you will be notified.</p>'
                    .self::details(['Requested' => '{{request_summary}}', 'Your note' => '{{reason}}'])
                    .self::button('Open billing', '{{billing_url}}'),
                ),
            ],
            'billing.quota_request_admin' => [
                'name' => 'Quota top-up request alert (Super Admin / Agent)',
                'category' => 'Billing',
                'description' => 'Sent to the reviewers when a client requests a quota top-up.',
                'subject' => 'Quota top-up request: {{company_name}}',
                'variables' => ['company_name' => 'Client', 'requester_name' => 'Who asked', 'requester_email' => 'Their email', 'request_summary' => 'What was asked for', 'reason' => 'Their note', 'admin_url' => 'Quota requests page'],
                'body' => self::layout(
                    'New quota top-up request',
                    '<p style="margin:0 0 12px;"><b>{{company_name}}</b> is asking for more quota:</p>'
                    .self::details(['Requested' => '{{request_summary}}', 'Note' => '{{reason}}', 'Requested by' => '{{requester_name}} ({{requester_email}})'])
                    .self::button('Review request', '{{admin_url}}'),
                ),
            ],
            'billing.addon_request_received' => [
                'name' => 'Add-on request received (customer)',
                'category' => 'Billing',
                'description' => 'Sent to the customer when they request a paid module add-on.',
                'subject' => 'We received your {{addon_name}} add-on request',
                'variables' => ['name' => 'User name', 'company_name' => 'Business name', 'addon_name' => 'Add-on', 'units' => 'Units asked for, or "-"', 'reason' => 'Their note', 'reviewer' => 'Who reviews it', 'addons_url' => 'Add-ons page'],
                'body' => self::layout(
                    'Your add-on request is in',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">We have received your request for the <b>{{addon_name}}</b> add-on. {{reviewer}} will review it; once approved you will get an invoice to pay.</p>'
                    .self::details(['Add-on' => '{{addon_name}}', 'Units' => '{{units}}', 'Your note' => '{{reason}}'])
                    .self::button('View add-ons', '{{addons_url}}'),
                ),
            ],
            'billing.addon_request_admin' => [
                'name' => 'Add-on request alert (Super Admin / Agent)',
                'category' => 'Billing',
                'description' => 'Sent to the reviewers when a client requests a paid module add-on.',
                'subject' => 'Add-on request: {{company_name}} wants {{addon_name}}',
                'variables' => ['company_name' => 'Client', 'requester_name' => 'Who asked', 'requester_email' => 'Their email', 'addon_name' => 'Add-on', 'units' => 'Units asked for, or "-"', 'reason' => 'Their note', 'admin_url' => 'Add-ons page'],
                'body' => self::layout(
                    'New add-on request',
                    '<p style="margin:0 0 12px;"><b>{{company_name}}</b> has requested an add-on:</p>'
                    .self::details(['Add-on' => '{{addon_name}}', 'Units' => '{{units}}', 'Note' => '{{reason}}', 'Requested by' => '{{requester_name}} ({{requester_email}})'])
                    .self::button('Review request', '{{admin_url}}'),
                ),
            ],

            // ---------------------------------------------------------------- Sales
            // QA fixes (2026-10-14) — one general alert used whenever something stops working
            // (WhatsApp disconnected, quota used up, a connection expired, a lead could not be read, ...).
            'account.alert' => [
                'name' => 'Account alert',
                'category' => 'Account',
                'description' => 'Sent to the account owner (or the person concerned) when something needs attention: WhatsApp disconnected, messages used up, a social connection expired, a CRM reminder, a template decision, and similar.',
                'subject' => '{{alert_title}} — {{company_name}}',
                'variables' => ['name' => 'Their name', 'company_name' => 'Business name', 'alert_title' => 'What happened', 'alert_message' => 'Details and what to do', 'action_label' => 'Button text', 'action_url' => 'Button link'],
                'body' => self::layout(
                    '{{alert_title}}',
                    '<p style="margin:0 0 12px;">Hi {{name}},</p>'
                    .'<p style="margin:0 0 12px;">{{alert_message}}</p>'
                    .self::button('{{action_label}}', '{{action_url}}'),
                ),
            ],
            'sales.custom_plan_received' => [
                'name' => 'Custom plan request received (customer)',
                'category' => 'Sales',
                'description' => 'Sent to the person who fills the "Contact sales" custom plan form.',
                'subject' => 'We received your custom plan request',
                'variables' => ['name' => 'Their name', 'requirement' => 'What they wrote'],
                'body' => self::layout(
                    'Thank you, {{name}}!',
                    '<p style="margin:0 0 12px;">We have received your request for a custom plan. Our team will contact you within <strong>24 to 48 hours</strong> to understand your needs.</p>'
                    .'<p style="margin:0;color:#475569;">Your requirement:<br>{{requirement}}</p>',
                ),
            ],
            'sales.custom_plan_admin' => [
                'name' => 'Custom plan request alert (Super Admin)',
                'category' => 'Sales',
                'description' => 'Sent to every Super Admin when someone fills the custom plan form.',
                'subject' => 'New custom plan request: {{title}}',
                'variables' => ['title' => 'Company, else name', 'name' => 'Their name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone', 'expected_messages' => 'Messages per month', 'whatsapp_numbers' => 'WhatsApp numbers', 'team_users' => 'Team users', 'requirement' => 'What they wrote', 'admin_url' => 'Dashboard'],
                'body' => self::layout(
                    'New custom plan request',
                    self::details(['Name' => '{{name}}', 'Company' => '{{company}}', 'Email' => '{{email}}', 'Phone' => '{{phone}}', 'Messages / month' => '{{expected_messages}}', 'WhatsApp numbers' => '{{whatsapp_numbers}}', 'Team users' => '{{team_users}}'])
                    .'<p style="margin:0;color:#475569;">{{requirement}}</p>'
                    .self::button('Open the dashboard', '{{admin_url}}'),
                ),
            ],
        ];
    }

    /** @return array{name: string, category: string, description: string, subject: string, body: string, variables: array<string, string>, sensitive?: bool}|null */
    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** Template-specific variables plus the global ones, for the editor's variable list. */
    public static function variablesFor(string $slug): array
    {
        return (self::get($slug)['variables'] ?? []) + self::GLOBAL_VARIABLES;
    }

    // ---------------------------------------------------------------------- building blocks

    /** The branded email shell: gradient header, white card, footer. Inline styles only. */
    public static function layout(string $heading, string $content, ?string $footer = null): string
    {
        $footer ??= 'You are receiving this email because you have an account with {{brand}}. Need help? Write to {{support_email}}.';

        return '<!DOCTYPE html>'
            .'<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{brand}}</title></head>'
            .'<body style="margin:0;padding:0;background-color:#f1f5f9;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;">'
            .'<tr><td align="center" style="padding:32px 12px;font-family:Inter,Arial,Helvetica,sans-serif;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">'
            .'<tr><td style="background-color:#059669;background-image:linear-gradient(135deg,#10b981,#0f766e);padding:22px 28px;color:#ffffff;font-size:20px;font-weight:bold;font-family:\'Plus Jakarta Sans\',Inter,Arial,Helvetica,sans-serif;">{{brand}}</td></tr>'
            .'<tr><td style="padding:28px;color:#0f172a;font-size:14px;line-height:1.6;font-family:Inter,Arial,Helvetica,sans-serif;">'
            .'<h1 style="margin:0 0 14px;font-size:20px;line-height:1.3;color:#0f172a;font-family:\'Plus Jakarta Sans\',Inter,Arial,Helvetica,sans-serif;">'.$heading.'</h1>'
            .$content
            .'</td></tr>'
            .'<tr><td style="padding:16px 28px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;line-height:1.5;font-family:Inter,Arial,Helvetica,sans-serif;">'.$footer.'<br>&copy; {{year}} {{brand}}</td></tr>'
            .'</table>'
            .'</td></tr></table>'
            .'</body></html>';
    }

    /** A "bulletproof" button: a table cell with a background colour, which every email client draws. */
    public static function button(string $label, string $url, string $color = '#059669'): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">'
            .'<tr><td style="background-color:'.$color.';border-radius:10px;">'
            .'<a href="'.$url.'" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;font-family:Inter,Arial,Helvetica,sans-serif;">'.$label.'</a>'
            .'</td></tr></table>';
    }

    /** @param array<string, string> $rows label => value */
    public static function details(array $rows): string
    {
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 16px;font-size:14px;border-collapse:collapse;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="padding:5px 18px 5px 0;color:#64748b;vertical-align:top;white-space:nowrap;">'.$label.'</td>'
                .'<td style="padding:5px 0;font-weight:600;color:#0f172a;">'.$value.'</td></tr>';
        }

        return $html.'</table>';
    }

    private static function step(string $number, string $text): string
    {
        return '<tr><td style="padding:4px 10px 4px 0;vertical-align:top;">'
            .'<span style="display:inline-block;width:22px;height:22px;line-height:22px;border-radius:11px;background-color:#d1fae5;color:#047857;font-size:12px;font-weight:bold;text-align:center;">'.$number.'</span></td>'
            .'<td style="padding:4px 0;color:#334155;">'.$text.'</td></tr>';
    }
}
