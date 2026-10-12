<?php

namespace App\Support;

/**
 * Default content of the public website, one entry per editable section key.
 * The Super Admin edits any section in "Website Content"; a saved section
 * replaces its default here as a whole (see PublicSiteController::site()).
 *
 * Everything below describes features this platform actually ships. No
 * customer counts, testimonials or awards are invented -- add real ones from
 * the editor once you have them.
 */
final class SiteContentDefaults
{
    public const KEYS = ['brand', 'home', 'features', 'about', 'pricing', 'contact', 'faq', 'legal'];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [
            'brand' => [
                'name' => 'WapHub',
                'tagline' => 'WhatsApp, CRM, Social Media & Ads in one platform',
                'description' => 'One workspace to message customers on WhatsApp, automate conversations, manage leads and run Facebook & Instagram marketing.',
                'support_email' => '',
                'support_phone' => '',
                'address' => '',
                // Shown on the Contact page; the address also places the map pin there.
                'business_hours' => 'Mon – Sat, 10:00 AM – 7:00 PM (IST)',
                'signup_enabled' => true,
                // A website signup starts on a free trial of this length and
                // message quota; after it ends, the customer buys a plan.
                'trial_days' => 7,
                'trial_messages' => 50,
                'social_links' => [
                    ['label' => 'Facebook', 'url' => ''],
                    ['label' => 'Instagram', 'url' => ''],
                    ['label' => 'LinkedIn', 'url' => ''],
                    ['label' => 'YouTube', 'url' => ''],
                ],
            ],

            'home' => [
                'hero' => [
                    'eyebrow' => 'WhatsApp marketing + CRM + Social, all in one',
                    'title' => 'Grow your business on WhatsApp — and everywhere your customers are',
                    'subtitle' => 'Send notifications and campaigns, build no-code chatbot journeys, capture and close leads in a built-in CRM, and run Facebook & Instagram posts and ads — from one dashboard.',
                    'primary_cta' => 'Get started',
                    'secondary_cta' => 'See features',
                ],
                'highlights' => [
                    ['value' => 'QR + Cloud API', 'label' => 'Connect with a QR scan or the official Meta Cloud API'],
                    ['value' => '29 node types', 'label' => 'in the drag-and-drop Journey Builder'],
                    ['value' => '1 dashboard', 'label' => 'for WhatsApp, CRM, social media and ads'],
                    ['value' => 'REST API', 'label' => 'with API keys and webhooks for developers'],
                ],
                'features_title' => 'Everything you need to talk to customers and sell more',
                'features_subtitle' => 'Each module works on its own, and together they share one contact list, one team and one bill.',
                'steps_title' => 'Live in minutes',
                'steps' => [
                    ['title' => 'Create your account', 'description' => 'Sign up, pick a plan and invite your team.'],
                    ['title' => 'Connect WhatsApp', 'description' => 'Scan a QR code or link the official Meta Cloud API — your first number is included in your plan.'],
                    ['title' => 'Import contacts & build journeys', 'description' => 'Upload contacts, set up templates and drag together a chatbot journey.'],
                    ['title' => 'Engage, convert, measure', 'description' => 'Send campaigns, capture leads into the CRM and track everything in analytics.'],
                ],
                'industries_title' => 'Built for every business that sells on WhatsApp',
                'industries' => [
                    ['title' => 'Retail & e-commerce', 'description' => 'Order updates, catalogues, offers and abandoned-cart follow-ups.'],
                    ['title' => 'Education & coaching', 'description' => 'Student records, fee reminders and attendance alerts to parents.'],
                    ['title' => 'Real estate', 'description' => 'Capture Facebook lead-ad enquiries and follow up instantly on WhatsApp.'],
                    ['title' => 'Services & agencies', 'description' => 'Manage many client accounts as a reseller agent from one login.'],
                ],
                'cta' => [
                    'title' => 'Ready to grow on WhatsApp?',
                    'subtitle' => 'Create your account in a minute. Pick a plan when you are ready.',
                    'button' => 'Create free account',
                ],
            ],

            'features' => [
                'title' => 'Features',
                'subtitle' => 'One platform, every channel your customers use.',
                'modules' => [
                    [
                        'icon' => 'message',
                        'title' => 'WhatsApp messaging',
                        'description' => 'Reach customers where they read messages.',
                        'bullets' => [
                            'Connect by QR scan or the official Meta Cloud API',
                            'Several WhatsApp numbers per account',
                            'Templates with variables and media',
                            'Send to one contact, a group, a bulk list or a CSV batch',
                            'Schedule messages ahead of time',
                            'Complete message logs and delivery status',
                        ],
                    ],
                    [
                        'icon' => 'workflow',
                        'title' => 'Journey Builder & chatbots',
                        'description' => 'Automate conversations without writing code.',
                        'bullets' => [
                            'Drag-and-drop canvas with 29 node types',
                            'Questions, buttons, lists, conditions and delays',
                            'Keyword and click-to-WhatsApp ad triggers',
                            'AI reply, agent and knowledge-base nodes',
                            'Versioning, draft and publish',
                        ],
                    ],
                    [
                        'icon' => 'users',
                        'title' => 'Built-in CRM',
                        'description' => 'Every conversation becomes a lead you can track.',
                        'bullets' => [
                            'Contacts and leads linked together',
                            'Kanban pipeline, tags and lead status',
                            'Assign leads to team members',
                            'Bulk actions and CRM analytics',
                        ],
                    ],
                    [
                        'icon' => 'share',
                        'title' => 'Social media',
                        'description' => 'Manage Facebook and Instagram from the same place.',
                        'bullets' => [
                            'Connect Facebook Pages and Instagram accounts',
                            'Publish posts and track insights',
                            'Social inbox for Messenger and Instagram messages',
                            'Automatic replies to comments',
                            'Social analytics and reports',
                        ],
                    ],
                    [
                        'icon' => 'megaphone',
                        'title' => 'Meta Ads',
                        'description' => 'Run and measure ads, and capture every lead.',
                        'bullets' => [
                            'Launch Facebook & Instagram campaigns',
                            'Ads dashboard with spend and results',
                            'Lead-ad sync straight into the CRM',
                        ],
                    ],
                    [
                        'icon' => 'sparkles',
                        'title' => 'AI assistant',
                        'description' => 'Let AI write and answer for you.',
                        'bullets' => [
                            'AI agents that answer from your knowledge base',
                            'Generate message templates and ad copy',
                            'AI nodes inside chatbot journeys',
                        ],
                    ],
                    [
                        'icon' => 'code',
                        'title' => 'Developer API',
                        'description' => 'Plug messaging into your own software.',
                        'bullets' => [
                            'REST API with API keys',
                            'Webhooks for delivery and inbound events',
                            'Server IP binding for security',
                        ],
                    ],
                    [
                        'icon' => 'building',
                        'title' => 'Teams, agents & resellers',
                        'description' => 'Run one business or many.',
                        'bullets' => [
                            'Team users with roles and permissions',
                            'Agent accounts that manage their own clients',
                            'Plans, add-ons, invoices and online payments',
                        ],
                    ],
                ],
            ],

            'about' => [
                'title' => 'About us',
                'subtitle' => 'We help businesses talk to their customers on the channels they already use.',
                'story_title' => 'Our story',
                'story' => [
                    'Most businesses juggle WhatsApp on a phone, leads in a spreadsheet and Facebook in another tab. Messages get missed and leads go cold.',
                    'We built one platform that brings WhatsApp messaging, chatbot automation, a CRM and social media marketing together — so a small team can work like a big one.',
                ],
                'mission_title' => 'Our mission',
                'mission' => 'Make powerful customer messaging simple and affordable for every business.',
                'values_title' => 'What we care about',
                'values' => [
                    ['title' => 'Simple', 'description' => 'Powerful tools that anyone on your team can use.'],
                    ['title' => 'Reliable', 'description' => 'Your messages, leads and data are handled with care.'],
                    ['title' => 'Honest pricing', 'description' => 'Clear plans, no surprises.'],
                    ['title' => 'Support', 'description' => 'Real people who help you get set up.'],
                ],
            ],

            'pricing' => [
                'title' => 'Simple, transparent pricing',
                'subtitle' => 'Pick the plan that fits today and upgrade whenever you grow.',
                'notes' => [
                    'One WhatsApp number is included in every plan. Extra numbers are available as add-ons.',
                    'Prices are shown as configured by us; taxes may apply at checkout.',
                ],
                'cta' => 'Choose plan',
                // Plan slug shown with a "Recommended" ribbon (leave empty for none).
                'featured_plan' => 'starter',
                // Feature lines per plan, by plan slug (Super Admin → Plans). A plan
                // without an entry here lists its messages, engine and capabilities.
                'plan_features' => [
                    [
                        'plan' => 'starter',
                        'features' => [
                            'Unlimited WhatsApp messages',
                            '1 WhatsApp number — connect by QR scan',
                            'Send to one contact, a contact group, a bulk list or a CSV file',
                            'Send to WhatsApp groups',
                            'Message templates with variables, images, videos and documents',
                            'Schedule messages for later',
                            'Contact groups and contact import',
                            'Keyword chatbot rules — automatic replies 24×7',
                            'Facebook & Instagram — connect Pages, publish posts, social inbox',
                            'Developer API key and webhooks',
                            'Message logs with delivery status',
                            'Analytics dashboard',
                            'Team users with roles',
                            'Add more WhatsApp numbers any time (add-on)',
                        ],
                    ],
                ],
            ],

            'contact' => [
                'title' => 'Contact us',
                'subtitle' => 'Questions about plans, a demo, or help getting started? Send us a message.',
                'topics' => ['Sales', 'Support', 'Partnership', 'Other'],
                'success' => 'Thanks! We have received your message and will get back to you soon.',
                // Contact page promises -- edit or clear them to match what you offer.
                'response_time' => 'We usually reply within 24 hours',
                'side_title' => 'Talk to a real person',
                'side_text' => 'Plans, a demo, onboarding or a technical question — our team will help you get set up.',
                'side_points' => ['Help with onboarding', 'Plan advice for your business', 'Reply within 24 hours'],
            ],

            'faq' => [
                'title' => 'Frequently asked questions',
                'items' => [
                    ['question' => 'Do I need the official WhatsApp Business API?', 'answer' => 'No. You can connect a number by scanning a QR code, or use the official Meta Cloud API if you prefer — both are supported.'],
                    ['question' => 'Can I use more than one WhatsApp number?', 'answer' => 'Yes. Your plan includes one number and you can add more as add-ons.'],
                    ['question' => 'Do I need to know how to code to build a chatbot?', 'answer' => 'No. The Journey Builder is drag-and-drop. Developers can still use the REST API and webhooks.'],
                    ['question' => 'Can I manage Facebook and Instagram too?', 'answer' => 'Yes. Connect your Pages and Instagram accounts to publish posts, reply to messages and comments, and run ads.'],
                    ['question' => 'I run an agency. Can I manage my clients?', 'answer' => 'Yes. Agent accounts can create and manage their own client accounts from one login.'],
                    ['question' => 'How do I pay?', 'answer' => 'Choose a plan after signing up and pay online, or request an invoice and pay by bank transfer, UPI or cash.'],
                ],
            ],

            'legal' => [
                'privacy' => [
                    'title' => 'Privacy Policy',
                    'updated' => '2026-10-12',
                    'intro' => 'How we collect, use and protect your information when you use our website and platform.',
                    'highlights' => ['We never sell your data', 'Meta data is used only for the features you turn on', 'Access tokens are encrypted', 'Ask us to delete your data any time'],
                    'sections' => [
                        ['heading' => 'Who we are', 'body' => 'This policy explains how this platform collects and uses information when you use our website and services.'],
                        ['heading' => 'Information we collect', 'body' => 'Account details you give us (name, email, phone, company); the contacts, messages and files you upload or send through the platform; and, when you connect Facebook or Instagram, the Page, Instagram account and Ad Account information you choose to share, with the access tokens needed to act on your behalf.'],
                        ['heading' => 'How we use it', 'body' => 'Only to provide the service you asked for: sending and receiving your messages, running your automations, publishing your posts and ads, showing your analytics, billing, and support. We do not sell your data.'],
                        ['heading' => 'Facebook & Instagram data', 'body' => 'Data received from Meta is used only for the features you enabled and is never shared with third parties. You can disconnect an account at any time from Social Accounts, which deletes its stored access tokens.'],
                        ['heading' => 'Storage & security', 'body' => 'Data is stored on secured servers. Secrets and access tokens are encrypted at rest.'],
                        ['heading' => 'Your rights', 'body' => 'You can ask for a copy of your data, correct it, or ask us to delete it — see our Data Deletion page.'],
                        ['heading' => 'Contact', 'body' => 'For privacy questions, contact us using the email on our Contact page.'],
                    ],
                ],
                'terms' => [
                    'title' => 'Terms of Service',
                    'updated' => '2026-10-12',
                    'intro' => 'The rules for using our platform. Please read them before creating an account.',
                    'highlights' => ['Get consent before you message people', 'No spam, fraud or unlawful content', 'Plans are billed for the period shown', 'WhatsApp and Meta rules also apply'],
                    'sections' => [
                        ['heading' => 'Using the service', 'body' => 'By creating an account you agree to these terms. You are responsible for the content you send and for following WhatsApp, Meta and local laws, including getting consent before messaging people.'],
                        ['heading' => 'No spam', 'body' => 'You must not use the platform for spam, fraud or unlawful messages. We may suspend accounts that do.'],
                        ['heading' => 'Plans & payment', 'body' => 'Paid features are available while your plan is active. Plans and add-ons are billed for the period shown at checkout.'],
                        ['heading' => 'Third-party services', 'body' => 'WhatsApp, Facebook and Instagram are services of Meta. Their availability and rules are outside our control.'],
                        ['heading' => 'Liability', 'body' => 'The service is provided as is. To the extent permitted by law, we are not liable for indirect losses.'],
                        ['heading' => 'Changes', 'body' => 'We may update these terms; continued use means you accept the updated terms.'],
                    ],
                ],
                'data_deletion' => [
                    'title' => 'Data Deletion',
                    'updated' => '2026-10-12',
                    'intro' => 'You are in control of your data. Here is how to disconnect your accounts or delete everything.',
                    'highlights' => ['Disconnect Facebook or Instagram instantly', 'Full account deletion within 30 days', 'Confirmation sent by email'],
                    'sections' => [
                        ['heading' => 'Disconnect Facebook or Instagram', 'body' => 'Log in, open Social Accounts and click Disconnect on the account. Its stored access tokens are deleted immediately. You can also remove our app from your Facebook settings under Apps and Websites.'],
                        ['heading' => 'Delete your account and data', 'body' => 'Email us from your account email address using the address on our Contact page with the subject "Delete my data". We will delete your account data within 30 days and confirm by email.'],
                    ],
                ],
            ],
        ];
    }

    /** @return mixed The default for one key, or null for an unknown key. */
    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }
}
