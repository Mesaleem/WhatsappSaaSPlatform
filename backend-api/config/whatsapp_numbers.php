<?php

/*
 * WhatsApp number slots and their paid add-ons (agreed 2026-10-05).
 *
 * The plan includes one number. Each extra number is an add-on: a one-month term
 * from payment, independent of the plan's own expiry. The price includes GST, and
 * invoices show the GST-inclusive total only.
 */
return [

    // Price of one extra number for one term, in INR, including GST.
    'addon_price' => (float) env('WHATSAPP_ADDON_PRICE', 99),

    // Length of an add-on's term, counted from the payment that bought it.
    'addon_term_months' => (int) env('WHATSAPP_ADDON_TERM_MONTHS', 1),

    // Most extra numbers one purchase can buy.
    'max_addons_per_purchase' => 10,

    // The invoice's plan_key for add-on purchases (not a plan in the catalog).
    'addon_plan_key' => 'whatsapp_addon',
];
