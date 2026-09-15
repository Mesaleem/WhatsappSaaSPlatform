<?php

namespace Database\Seeders;

use App\Models\RouteCategory;
use App\Models\SystemRoute;
use Illuminate\Database\Seeder;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — one-time backfill of route_categories/system_routes from
 * the existing hardcoded frontend-app/src/types/account.ts constants
 * (CORE_COMMON_MODULES / WHATSAPP_SUITE_MODULES / SOCIAL_SUITE_MODULES)
 * plus backend-api's Account::MODULES, so every existing account's
 * "Module & Feature Access" checklist renders identically after this
 * feature ships, just from the database instead of a constant array.
 *
 * DISCLOSED SCOPE ADDITION: 'notifications' and 'developer_api' are
 * valid Account::MODULES slugs with real nav-item gates
 * (AppLayout.tsx's requiresModule) but were deliberately absent from
 * all three frontend UI category groups (see CORE_COMMON_MODULES's
 * docblock: "excluding Notifications" / "never part of the spec's
 * checklist"). Account::effectiveModules() now dynamically intersects
 * allowed_modules against SystemRoute::activePermissionKeys() (see that
 * method's docblock) — if these two slugs were left out of
 * system_routes entirely, seeding this table would silently strip them
 * from every account's effective modules the moment this seeder runs
 * (activePermissionKeys() stops returning null once the table is
 * non-empty). To avoid that regression, they're backfilled here too,
 * under a 4th "Other Platform Features" category. This is a disclosed
 * capability ADDITION (a Super Admin can now toggle them via Route
 * Master, where previously they were permanently unmanaged/always-on),
 * not a behavior change for any existing account — every module stays
 * enabled exactly as before until a Super Admin deliberately deactivates
 * a route or category.
 *
 * Idempotent, but deliberately NEVER overwrites an existing row
 * (firstOrCreate, not updateOrCreate): once a Super Admin renames a
 * category or edits a route via the new Route Master CRUD UI, re-running
 * this seeder (e.g. after a future deploy adds a brand-new module) must
 * never silently revert that customization back to the hardcoded
 * default below. Re-running only ever backfills rows that don't exist
 * yet, keyed on the stable category_code / (category_id, permission_key)
 * pair.
 */
class RouteMasterSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, icon: string, modules: array<int, array{key: string, title: string, path: ?string}>}>
     */
    private const CATEGORIES = [
        [
            'code' => 'core_common',
            'name' => 'Core Common Features (Shared)',
            'icon' => 'ShieldCheck',
            'modules' => [
                ['key' => 'dashboard', 'title' => 'Dashboard', 'path' => '/'],
                ['key' => 'analytics', 'title' => 'Analytics', 'path' => '/analytics'],
                ['key' => 'billing', 'title' => 'Billing & Plans', 'path' => '/billing'],
                ['key' => 'team_management', 'title' => 'Team Users', 'path' => '/users'],
            ],
        ],
        [
            'code' => 'whatsapp_suite',
            'name' => 'WhatsApp Messaging Suite',
            'icon' => 'MessageSquare',
            'modules' => [
                ['key' => 'whatsapp_setup', 'title' => 'WhatsApp Setup', 'path' => '/settings/whatsapp'],
                ['key' => 'send_alert', 'title' => 'Send Alert', 'path' => '/alerts/send'],
                ['key' => 'chatbot', 'title' => 'Chatbot Rules', 'path' => '/chatbot'],
                ['key' => 'templates', 'title' => 'Template Manager', 'path' => '/admin/templates'],
                ['key' => 'device_settings', 'title' => 'Device Settings', 'path' => '/admin/device-settings'],
                ['key' => 'message_logs', 'title' => 'Message Logs & Audit Trail', 'path' => '/message-logs'],
                ['key' => 'contact_groups', 'title' => 'Custom Contact Groups (Paid Addon)', 'path' => '/contact-groups'],
            ],
        ],
        [
            'code' => 'social_suite',
            'name' => 'Social Media Suite',
            'icon' => 'Share2',
            'modules' => [
                ['key' => 'social_accounts', 'title' => 'Social Accounts', 'path' => '/social/accounts'],
                ['key' => 'meta_ads', 'title' => 'Meta Ads Launcher', 'path' => '/social/ads'],
                ['key' => 'lead_crm', 'title' => 'Instant Lead CRM', 'path' => '/social/leads'],
                ['key' => 'social_inbox', 'title' => 'Unified Social Inbox', 'path' => '/social/inbox'],
                ['key' => 'comment_automation', 'title' => 'Comment Rules', 'path' => '/social/comment-rules'],
                ['key' => 'reports', 'title' => 'Social Reports', 'path' => '/social/reports'],
            ],
        ],
        [
            'code' => 'platform_other',
            'name' => 'Other Platform Features',
            'icon' => 'Settings',
            'modules' => [
                ['key' => 'notifications', 'title' => 'Notifications', 'path' => '/notifications'],
                ['key' => 'developer_api', 'title' => 'Developer API', 'path' => '/developer'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $categoryIndex => $category) {
            /** @var RouteCategory $categoryModel */
            $categoryModel = RouteCategory::query()->firstOrCreate(
                ['category_code' => $category['code']],
                [
                    'category_name' => $category['name'],
                    'icon_name' => $category['icon'],
                    'sort_order' => $categoryIndex,
                    'is_active' => true,
                ]
            );

            foreach ($category['modules'] as $routeIndex => $module) {
                SystemRoute::query()->firstOrCreate(
                    [
                        'category_id' => $categoryModel->id,
                        'permission_key' => $module['key'],
                    ],
                    [
                        'route_title' => $module['title'],
                        'route_path' => $module['path'],
                        'is_agent_assignable' => true,
                        'is_active' => true,
                        'sort_order' => $routeIndex,
                    ]
                );
            }
        }
    }
}
