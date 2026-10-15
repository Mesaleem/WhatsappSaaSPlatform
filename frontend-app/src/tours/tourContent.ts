/**
 * Product tour content (owner, 2026-10-15). Loaded lazily — only when a tour
 * is actually shown — so it never weighs on the dashboard.
 *
 *   NAV_TOUR     one line per sidebar feature: what it is, why you use it,
 *                how to start. The welcome tour is built from the sidebar
 *                items the user can SEE, so it already follows their plan,
 *                role and permissions.
 *   PAGE_TOURS   a short "how this page works" tour, shown the first time a
 *                user opens that page (after the welcome tour).
 *
 * Keep PAGE_TOURS' keys in sync with PAGE_TOUR_ROUTES (tourKeys.ts).
 */

export interface TourStep {
  title: string;
  body: string;
  /** Optional "how to" points shown under the body. */
  points?: string[];
  /** A `data-tour` value to highlight; without one (or when hidden) the step is centered. */
  target?: string;
}

export type TourAudience = 'super_admin' | 'agent' | 'admin' | 'member';

interface NavEntry {
  title: string;
  body: string;
  /** Sidebar items sharing a group are explained in one step (the first visible one is highlighted). */
  group?: string;
}

export const NAV_TOUR: Record<string, NavEntry> = {
  '/': { title: 'Dashboard', body: 'Your day at a glance: messages sent, plan usage, WhatsApp status and anything that needs attention.' },
  '/analytics': { title: 'Analytics', body: 'Charts of messages delivered and failed over time, by channel. Use it to see what is working.' },
  '/message-logs': { title: 'Message Logs', body: 'Every message with its status and reason if it failed. Look here first when a customer says they got nothing.' },
  '/notifications': { title: 'Broadcast & Notifications', body: 'Send an email or in-app announcement to your team or clients, and manage the email templates.' },
  '/admin/accounts': { title: 'Clients', body: 'Create client accounts, give them a plan, and open any client to act on its behalf.' },
  '/users': { title: 'Team Users', body: 'Invite your team and choose what each person can do. Your plan decides how many users you can have.' },
  '/settings/whatsapp': { title: 'WhatsApp Setup', body: 'Connect your WhatsApp number first — scan the QR code (or connect the Meta API). Nothing can be sent until a number is connected.' },
  '/alerts/send': { title: 'Send Notification', body: 'Send a WhatsApp message to one number, a pasted list, an uploaded file or a contact group — now or scheduled.' },
  '/whatsapp/inbox': { title: 'WhatsApp Inbox', body: 'Every customer conversation on your WhatsApp number — what they wrote and what you sent — and you reply right here.' },
  '/contact-groups': { title: 'Contact Groups', body: 'Save lists of customers (e.g. "VIP", "Batch A") so you can message the whole group in one go.' },
  '/admin/templates': { title: 'Templates', body: 'Ready-made message texts with {{variables}} like name or amount. Approved templates make sending quick and consistent.' },
  '/chatbot': { title: 'Chatbot Rules', body: 'Auto-reply when a customer sends a keyword — e.g. "price" → your price list. Works 24×7.' },
  '/chatbot/journeys': { title: 'Journey Builder', body: 'Drag-and-drop conversations: questions, buttons, delays, conditions and actions that run automatically.' },
  '/developer': { title: 'Developer API', body: 'API keys and webhooks to send messages from your own software or website.' },
  '/social/accounts': { title: 'Social Accounts', body: 'Connect your Facebook Page, Instagram account and Meta ad account. Social posts, inbox and ads all start here.', group: 'social' },
  '/social/inbox': { title: 'Social Inbox', body: 'Messenger and Instagram DMs plus ad leads in one place — reply without leaving the app.', group: 'social' },
  '/social/comment-rules': { title: 'Comment Rules', body: 'Auto-reply to comments on your posts, or send a private message to whoever comments.', group: 'social' },
  '/social/analytics': { title: 'Social Analytics', body: 'Reach, likes and comments of your posts on Facebook and Instagram.', group: 'social' },
  '/social/reports': { title: 'Social Reports', body: 'Download social performance reports to share.', group: 'social' },
  '/social/ads': { title: 'Meta Ads Launcher', body: 'Create Facebook, Instagram and Click-to-WhatsApp ads in a few steps, and post Reels, Stories and carousels.', group: 'ads' },
  '/social/ads/dashboard': { title: 'Ads Dashboard', body: 'Spend, leads and cost per lead of your campaigns.', group: 'ads' },
  '/social/leads': { title: 'Instant Lead CRM', body: 'Leads that came from your Facebook/Instagram lead ads.', group: 'ads' },
  '/crm/leads': { title: 'CRM', body: 'Leads, contacts, pipeline, deals, tasks, quotes and payments — every customer from WhatsApp, ads and your website in one place.', group: 'crm' },
  '/crm/pipeline': { title: 'CRM Pipeline', body: 'Drag leads across stages from New to Converted.', group: 'crm' },
  '/crm/deals': { title: 'CRM Deals', body: 'Track deals and their value.', group: 'crm' },
  '/crm/tasks': { title: 'CRM Tasks', body: 'Follow-ups and reminders for your team.', group: 'crm' },
  '/crm/contacts': { title: 'CRM Contacts', body: 'Everyone you have talked to.', group: 'crm' },
  '/crm/companies': { title: 'CRM Companies', body: 'Businesses your contacts belong to.', group: 'crm' },
  '/crm/sales': { title: 'Sales Dashboard', body: 'Sales figures at a glance.', group: 'crm' },
  '/crm/documents': { title: 'Quotes & Invoices', body: 'Send quotes and invoices on WhatsApp or email.', group: 'crm' },
  '/crm/payments': { title: 'Payments Received', body: 'Payments recorded against invoices.', group: 'crm' },
  '/crm/products': { title: 'Products', body: 'Your products and prices for quotes.', group: 'crm' },
  '/crm/settings': { title: 'Sales Settings', body: 'Invoice numbering, taxes and sales options.', group: 'crm' },
  '/crm/tags': { title: 'CRM Tags', body: 'Labels to sort leads.', group: 'crm' },
  '/crm/analytics': { title: 'CRM Analytics', body: 'Lead sources and conversion rates.', group: 'crm' },
  '/education': { title: 'Education', body: 'Students, batches, attendance and fees for coaching institutes and schools.' },
  '/billing': { title: 'Billing & Plans', body: 'Your plan, what is used, invoices and upgrades. Renew here before the plan ends.', group: 'billing' },
  '/add-ons': { title: 'Add-ons', body: 'Buy extra WhatsApp numbers, groups and other add-ons.', group: 'billing' },
  '/admin/plans': { title: 'Plans', body: 'Prices, features and limits of every plan — and custom plans for one client, with a price calculator.', group: 'platform' },
  '/admin/billing/gateway-settings': { title: 'Payment Gateways', body: 'Razorpay / Stripe keys for online payments.', group: 'platform' },
  '/admin/social-settings': { title: 'Social Gateway Settings', body: 'Your Meta app keys for Facebook, Instagram and ads.', group: 'platform' },
  '/admin/ai-settings': { title: 'AI Settings', body: 'AI provider keys and free daily AI credits.', group: 'platform' },
  '/admin/website': { title: 'Website Content', body: 'Edit your public website text, pricing notes and contact details.', group: 'platform' },
  '/admin/website/analytics': { title: 'Website Analytics', body: 'Visitors and sign-ups from the website.', group: 'platform' },
  '/admin/device-settings': { title: 'Device Settings', body: 'The platform’s own WhatsApp number used for system messages.', group: 'platform' },
  '/admin/api-access': { title: 'API Access', body: 'Which clients may use the Developer API.', group: 'platform' },
  '/admin/audit-logs': { title: 'Activity Logs', body: 'Who changed what, and when.', group: 'platform' },
};

/** A group explained in one step: its own title and text. */
const GROUP_TOUR: Record<string, { title: string; body: string }> = {
  social: { title: 'Social media', body: 'Connect Facebook and Instagram, then reply to DMs, automate comments and see how your posts perform.' },
  ads: { title: 'Meta Ads & posts', body: 'Launch Facebook, Instagram and Click-to-WhatsApp ads, post Reels / Stories / carousels, and collect leads from your ads.' },
  crm: NAV_TOUR['/crm/leads'],
  billing: { title: 'Billing, plan & add-ons', body: 'See your plan and what is used, pay invoices, upgrade, and buy add-ons like extra WhatsApp numbers.' },
  platform: { title: 'Platform settings', body: 'Plans and prices, payment / Meta / AI keys, website content and activity logs — everything that runs the platform.' },
};

const WELCOME: Record<TourAudience, TourStep> = {
  super_admin: {
    title: 'Welcome, Super Admin 👋',
    body: 'This short tour shows the platform tools: clients, plans, templates and settings. It takes about a minute.',
  },
  agent: {
    title: 'Welcome to your agent panel 👋',
    body: 'You can create clients, give them plans and manage them. This short tour shows where everything is.',
  },
  admin: {
    title: 'Welcome aboard 👋',
    body: 'Your menu shows exactly what your plan includes. This short tour explains each part and where to start.',
  },
  member: {
    title: 'Welcome 👋',
    body: 'Your admin gave you access to the parts below. This short tour explains each one.',
  },
};

const MAX_FEATURE_STEPS = 10;

/**
 * The welcome tour for the sidebar items this user can see (already filtered
 * by plan, role and permissions): a greeting, one step per feature (or
 * group), and where to replay the tour.
 */
export function buildWelcomeTour(visibleRoutes: string[], audience: TourAudience): TourStep[] {
  const steps: TourStep[] = [WELCOME[audience]];
  const seenGroups = new Set<string>();

  for (const route of visibleRoutes) {
    const entry = NAV_TOUR[route];
    if (!entry) continue;
    if (entry.group) {
      if (seenGroups.has(entry.group)) continue;
      seenGroups.add(entry.group);
      const group = GROUP_TOUR[entry.group];
      steps.push({ title: group.title, body: group.body, target: 'nav:' + route });
    } else {
      steps.push({ title: entry.title, body: entry.body, target: 'nav:' + route });
    }
    if (steps.length > MAX_FEATURE_STEPS) break;
  }

  // A first step for a new client: connect WhatsApp before anything else.
  if (audience === 'admin' && visibleRoutes.includes('/settings/whatsapp')) {
    steps.push({
      title: 'Start here',
      body: 'Connect your WhatsApp number in WhatsApp Setup, then send your first message from Send Notification.',
      target: 'nav:/settings/whatsapp',
    });
  }

  steps.push({
    title: 'Need this again?',
    body: 'Click the ? button at the top any time to replay this tour or get a tour of the page you are on.',
    target: 'tour-button',
  });

  return steps;
}

/** First-visit page tours. */
export const PAGE_TOURS: Record<string, TourStep[]> = {
  '/settings/whatsapp': [
    // QA fixes (2026-10-14): covers both engines, and no promise that messages wait while disconnected.
    { title: 'Connect WhatsApp', body: 'How you connect depends on your plan.', points: ['QR plans: on your phone open WhatsApp → Linked devices → Link a device, then scan the QR code shown here (or use “Link with phone number”).', 'Meta plans: click Connect with Meta and follow the steps for your WhatsApp Business account and number.', 'The status turns Connected when it worked.'] },
    { title: 'Stay connected', body: 'If the status shows Disconnected later, reconnect here. While it is disconnected messages cannot be sent — they show as failed in Message Logs, so send them again once you are connected.' },
  ],
  '/alerts/send': [
    { title: 'Send a message', body: 'Pick who gets it, write or pick a template, then send now or schedule.', points: ['One number, a pasted list, an Excel/CSV file or a contact group.', 'Use {{name}}-style variables to personalise each message.', 'Large sends go out gradually to protect your number.'] },
    { title: 'Check what was sent', body: 'Every message appears in Message Logs with its status, so you can see what went out and why anything failed.' },
  ],
  '/whatsapp/inbox': [
    { title: 'Your WhatsApp conversations', body: 'Customers who message you appear here, newest first, with everything you sent them too.', points: ['Click a conversation to read it and reply.', 'Unread conversations show a green count.', 'On the Meta API, a free reply is possible within 24 hours of the customer’s last message; after that send a template.'] },
  ],
  '/contact-groups': [
    { title: 'Contact groups', body: 'Create a group, add contacts (by hand or from a file), then message the whole group from Send Notification.', points: ['Each member gets a personal message, not a group chat.', 'Groups also help you target the right customers later.'] },
  ],
  '/admin/templates': [
    { title: 'Message templates', body: 'Write a message once with {{variables}} and reuse it. A template is reviewed before it can be sent.', points: ['On the Meta API, Meta approves the template too.', 'Marketing templates count against your monthly marketing limit.'] },
  ],
  '/chatbot': [
    { title: 'Auto-reply rules', body: 'Add a keyword and the reply to send. When a customer’s message matches, the reply goes out automatically.', points: ['Use several keywords per rule (price, rate, cost).', 'Turn a rule off without deleting it.'] },
  ],
  '/chatbot/journeys': [
    { title: 'Journey Builder', body: 'Build a conversation as a flow: start with a trigger (a keyword or an ad click), then add messages, buttons, questions, delays and conditions.', points: ['Drag blocks from the left and connect them.', 'Test a journey on your own number before publishing.', 'Your plan sets how many journeys you can have.'] },
  ],
  '/crm/leads': [
    { title: 'Your leads', body: 'Every enquiry from WhatsApp, ads and your website lands here. Assign it, move its status and add follow-up tasks.', points: ['Open a lead to see its full history.', 'Convert a lead when it becomes a customer.', 'Use the Pipeline view to drag leads between stages.'] },
  ],
  '/crm/pipeline': [
    { title: 'Pipeline', body: 'Each column is a stage. Drag a lead to the next stage as the conversation moves forward.' },
  ],
  '/social/accounts': [
    { title: 'Connect social accounts', body: 'Click Connect and sign in with Facebook. Choose the Page, the Instagram account linked to it and your ad account.', points: ['You must be an admin of the Page.', 'If a connection expires, reconnect it here.'] },
    { title: 'No Facebook Page yet?', body: 'A personal Facebook profile cannot be connected. The checklist on this page shows how to create a Page, switch Instagram to a Business account and open an ad account — with a button to the right Facebook page for each.', points: ['After a connect, WapHub tells you exactly what is still missing.', 'Done it? Click “I’ve done it — check again”.'] },
  ],
  '/social/inbox': [
    { title: 'Social inbox', body: 'Messenger and Instagram DMs and ad leads in one list. Open a conversation and reply.', points: ['Replies are allowed up to 7 days after the customer’s last message (Meta’s rule).'] },
  ],
  '/social/ads': [
    { title: 'Launch an ad or a post', body: 'Paid Ad: pick the goal (leads, messages, website visits or WhatsApp chats), budget, audience, placements and creative. Organic Post: publish or schedule a post, Reel, Story or carousel.', points: ['Click-to-WhatsApp ads open a chat with your number.', 'Choose WhatsApp Status as a placement for Click-to-WhatsApp ads.', 'New ads can start paused so you can check them first.'] },
  ],
  '/billing': [
    { title: 'Your plan', body: 'See your plan, what is used (users, templates, broadcasts, messages) and when it renews.', points: ['Buy or upgrade a plan, or pay an open invoice.', 'Need something different? Use Contact sales for a custom plan.'] },
  ],
  '/users': [
    { title: 'Your team', body: 'Invite a team member with their email and choose their role. Each role decides what they can see and do.', points: ['Your plan sets how many users you can have.'] },
  ],
  '/admin/accounts': [
    { title: 'Clients', body: 'Create a client, choose its plan and record the payment — its menu then follows the plan automatically.', points: ['Use the client switcher at the top to act for a client.', 'Open a client to edit it, extend the plan or reset a password.'] },
  ],
  '/admin/plans': [
    { title: 'Plans & custom plans', body: 'Edit any plan’s price, features and limits. Build a custom plan for one client and use the price calculator to price it.', points: ['Changes apply to new purchases only.', 'Use the send button on a custom plan to create the client’s invoice.'] },
  ],
  '/developer': [
    { title: 'Developer API', body: 'Create an API key, then send messages from your own system. Webhooks tell your system when a message is sent or fails.' },
  ],
};
