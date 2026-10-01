<?php

/*
| Phase 11 Task 1 — the industry registry (code-owned, like the capability catalog).
|
| Industry → Industry Module → (future) Features. An account is ASSIGNED industries
| (`account_industries`), and may USE an industry's modules only when ALL of these hold
| (App\Services\Industry\IndustryAuthorizer): administratively active, subscription (writes),
| the `industry_modules` account module on, the industry's capability held, the industry assigned,
| the module registered AND available, and the user's permission. Authorization never looks at where
| a record came from (manual, Journey, API, import).
|
| Every industry module is an ADD-ON to the existing CRM, never a second contact/lead system:
| `crm_anchor` says which CRM record the module's profile hangs off — 'contact' (crm_contacts /
| contacts, e.g. a student or patient) or 'lead' (crm_leads, e.g. a property enquiry).
|
| `requires_capability` (optional) names a further capability that module needs — e.g. the generic
| Billing & Collections core — so a shared feature is entitled separately from any one industry.
|
| `available => false` registers a planned module (visible in the catalog, refused by the gate) —
| flip it, and set `permission` for its writes, when the feature ships. Verticals are the optional
| sub-type of an industry (school / coaching / institute); an empty list means no sub-type.
*/

return [
    'view_permission' => 'view-industry-modules',

    'module' => 'industry_modules',

    'industries' => [
        'education' => [
            'label' => 'Education',
            'capability' => 'industry_education',
            // The vertical is configuration, not three implementations: `group_kind` is only the DEFAULT
            // kind (class | batch) and label for a new student group of an account of that vertical.
            'verticals' => ['school' => 'School', 'coaching' => 'Coaching', 'institute' => 'Institute'],
            'vertical_config' => [
                'school' => ['group_kind' => 'class', 'group_label' => 'Class'],
                'coaching' => ['group_kind' => 'batch', 'group_label' => 'Batch'],
                'institute' => ['group_kind' => 'batch', 'group_label' => 'Batch'],
            ],
            'modules' => [
                // Phase 11 Task 2 — shipped. `permission` gates reads, `write_permission` gates writes.
                'students' => ['label' => 'Students & Parents', 'crm_anchor' => 'contact', 'available' => true, 'permission' => 'view-education', 'write_permission' => 'manage-education'],
                'batches' => ['label' => 'Batches & Classes', 'crm_anchor' => 'contact', 'available' => true, 'permission' => 'view-education', 'write_permission' => 'manage-education'],
                'attendance' => ['label' => 'Attendance', 'crm_anchor' => 'contact', 'available' => true, 'permission' => 'view-education', 'write_permission' => 'manage-education'], // Phase 11 Task 3
                // Phase 11 Task 4 — Education's adapter over the generic Billing & Collections core. `requires_capability`
                // names an EXTRA capability the module needs besides the industry's own (enforced by IndustryAuthorizer,
                // so the API and the frontend's `industry_modules` keys use the same source).
                'fees' => ['label' => 'Fees & Collections', 'crm_anchor' => 'contact', 'available' => true, 'permission' => 'view-education', 'write_permission' => 'manage-education', 'requires_capability' => 'billing_collections'],
            ],
        ],
        'healthcare' => [
            'label' => 'Healthcare',
            'capability' => 'industry_healthcare',
            'verticals' => ['doctor' => 'Doctor', 'clinic' => 'Clinic', 'hospital' => 'Hospital'],
            'modules' => [
                'patients' => ['label' => 'Patients', 'crm_anchor' => 'contact', 'available' => false],
                'appointments' => ['label' => 'Appointments', 'crm_anchor' => 'contact', 'available' => false],
                'prescriptions' => ['label' => 'Prescriptions & Medicine Reminders', 'crm_anchor' => 'contact', 'available' => false],
                'follow_ups' => ['label' => 'Follow-ups', 'crm_anchor' => 'contact', 'available' => false],
            ],
        ],
        'ecommerce' => [
            'label' => 'Ecommerce',
            'capability' => 'industry_ecommerce',
            'verticals' => [],
            'modules' => [
                'customers' => ['label' => 'Customers', 'crm_anchor' => 'contact', 'available' => false],
                'orders' => ['label' => 'Orders & Delivery Updates', 'crm_anchor' => 'contact', 'available' => false],
                'abandoned_carts' => ['label' => 'Abandoned-cart Automation', 'crm_anchor' => 'contact', 'available' => false],
            ],
        ],
        'real_estate' => [
            'label' => 'Real Estate',
            'capability' => 'industry_real_estate',
            'verticals' => [],
            'modules' => [
                'properties' => ['label' => 'Properties', 'crm_anchor' => 'lead', 'available' => false],
                'enquiries' => ['label' => 'Property Enquiries', 'crm_anchor' => 'lead', 'available' => false],
                'site_visits' => ['label' => 'Site Visits & Follow-ups', 'crm_anchor' => 'lead', 'available' => false],
            ],
        ],
        'financial_services' => [
            'label' => 'Money Transfer & Financial Services',
            'capability' => 'industry_financial_services',
            'verticals' => [],
            'modules' => [
                'customers' => ['label' => 'Customers', 'crm_anchor' => 'contact', 'available' => false],
                'transactions' => ['label' => 'Transaction References & Status', 'crm_anchor' => 'contact', 'available' => false],
                'documents' => ['label' => 'Documents & Reminders', 'crm_anchor' => 'contact', 'available' => false],
            ],
        ],
    ],
];
