<?php

declare(strict_types=1);

return [
    // ── Page ──────────────────────────────────────────────────────────────────
    'page_title'         => 'Plans',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Plans',
    'btn_new'            => 'New plan',
    'search_placeholder' => 'Search by name...',
    'total_label'        => 'Total:',
    'loading'            => 'Loading...',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Table view',
    'view_cards' => 'Card view',

    // ── Confirmations ─────────────────────────────────────────────────────────
    'confirm_delete' => 'Are you sure you want to remove this plan?',

    // ── Table columns ─────────────────────────────────────────────────────────
    'col_order'   => 'Order',
    'col_name'    => 'Name',
    'col_price'   => 'Price',
    'col_cycle'   => 'Cycle',
    'col_status'  => 'Status',
    'col_actions' => 'Actions',

    // ── Status badges ─────────────────────────────────────────────────────────
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',

    // ── Actions ───────────────────────────────────────────────────────────────
    'action_view'       => 'View',
    'action_edit'       => 'Edit',
    'action_activate'   => 'Activate',
    'action_deactivate' => 'Deactivate',
    'action_delete'     => 'Remove',

    // ── Pagination ────────────────────────────────────────────────────────────
    'showing_from'   => 'Showing',
    'showing_to'     => 'to',
    'showing_of'     => 'of',
    'showing_suffix' => 'plans',

    // ── Empty states ──────────────────────────────────────────────────────────
    'empty_list'     => 'No plans found.',
    'empty_features' => 'No features configured for this plan.',

    // ── Form — titles ─────────────────────────────────────────────────────────
    'form_title_create' => 'New Plan',
    'form_title_edit'   => 'Edit Plan',

    // ── Form — tabs ───────────────────────────────────────────────────────────
    'tab_data'     => 'Data',
    'tab_features' => 'Features',

    // ── Form — fields (Data) ──────────────────────────────────────────────────
    'field_name'                    => 'Name',
    'field_name_required'           => 'Name *',
    'field_sort_order'              => 'Order',
    'field_description'             => 'Description',
    'field_description_placeholder' => 'Brief description of the plan...',
    'field_price'                   => 'Price',
    'currency_prefix'               => '$',
    'field_billing_cycle'           => 'Billing cycle',
    'field_billing_cycle_required'  => 'Billing cycle *',
    'field_status'                  => 'Status',
    'status_option_active'          => 'Active',
    'status_option_inactive'        => 'Inactive',

    // ── Form — features ───────────────────────────────────────────────────────
    'features_info'                 => 'For numeric limits, 0 = unlimited. Boolean features indicate whether the resource is available in the plan.',
    'features_numeric_section'      => 'Quantitative Limits',
    'features_numeric_placeholder'  => '0 = unlimited',
    'features_numeric_hint'         => '0 = unlimited',
    'features_boolean_section'      => 'Available Features',
    'features_boolean_not_included' => 'Not included',
    'features_boolean_included'     => 'Included',

    // ── Form — buttons ────────────────────────────────────────────────────────
    'btn_cancel'       => 'Cancel',
    'btn_save_changes' => 'Save changes',
    'btn_create_plan'  => 'Create plan',

    // ── Detail drawer ─────────────────────────────────────────────────────────
    'detail_btn_edit'    => 'Edit',
    'section_pricing'    => 'Pricing',
    'section_features'   => 'Limits & Features',
    'detail_price'       => 'Price',
    'detail_cycle'       => 'Cycle',
    'detail_sort_order'  => 'Display order',
    'detail_description' => 'Description',
    'detail_created_at'  => 'Registered on',

    // ── Feature values (drawer) ───────────────────────────────────────────────
    'feature_included'     => 'Included',
    'feature_not_included' => 'Not included',
    'feature_unlimited'    => 'Unlimited',

    // ── Prices per billing cycle ─────────────────────────────────────────────
    'tab_pricing'                => 'Prices & cycles',
    'pricing_info'               => 'Tick the cycles customers can pick on the website and sign-up, and set the price of each. The default cycle is shown first.',
    'pricing_offer'              => 'Offer the :cycle cycle',
    'pricing_price_label'        => ':cycle cycle price',
    'pricing_default'            => 'Default',
    'no_sellable_cycle'          => 'No billing cycle for sale — hidden from the site until you add one.',
    'pricing_default_label'      => 'Use :cycle as the default cycle',
    'pricing_monthly_equivalent' => '≈ :price/month',
    'pricing_savings'            => ':percent% saving over monthly',
    'pricing_no_savings'         => 'Same as paying monthly',
    'pricing_more_expensive'     => 'More expensive than paying monthly',
    'pricing_discount_label'     => 'Discount over monthly (%)',
    'pricing_apply_discount'     => 'Fill quarterly, semiannual and yearly',
    'pricing_apply_hint'         => 'Fills the ticked cycles from the monthly price with the given discount. You can still adjust each value.',
    'pricing_need_monthly'       => 'Enter the monthly price to calculate the other cycles.',
    'field_is_featured'          => 'Highlight on the website ("Most popular")',
    'field_is_featured_hint'     => 'The plan card stands out on the pricing page.',

    // ── List: prices and subscribers ─────────────────────────────────────────
    'col_prices'                => 'Prices per cycle',
    'col_subscribers'           => 'Subscribers',
    'subscribers_link_title'    => 'See this plan\'s subscriptions',
    'action_view_subscriptions' => 'See subscriptions',
    'action_new_subscription'   => 'New subscription on this plan',
    'featured_badge'            => 'Highlighted',
    'cycles_count'              => ':count cycle|:count cycles',

    // ── Drawer: prices and subscribers ───────────────────────────────────────
    'section_prices'             => 'Prices per cycle',
    'section_subscribers'        => 'Subscribers',
    'detail_default_cycle'       => 'Default cycle',
    'detail_featured'            => 'Highlighted on the website',
    'detail_yes'                 => 'Yes',
    'detail_no'                  => 'No',
    'subscribers_total'          => 'Companies on this plan',
    'subscribers_trial'          => 'On trial',
    'subscribers_gateway'        => 'Automatic billing',
    'subscribers_complimentary'  => 'Complimentary',
    'subscribers_empty'          => 'No company is on this plan right now.',
    'features_numeric_hint_none' => '0 = not included',

    // ── Plans ↔ Subscriptions navigation ─────────────────────────────────────
    'nav_plans'         => 'Plans',
    'nav_subscriptions' => 'Subscriptions',
    'nav_label'         => 'Plans and subscriptions',

    // ── Messages ──────────────────────────────────────────────────────────────
    'flash_created'        => 'Plan created successfully.',
    'flash_updated'        => 'Plan updated successfully.',
    'flash_status_updated' => 'Plan status updated.',
    'flash_deleted'        => 'Plan removed successfully.',

    // ── Trial (new companies) ─────────────────────────────────────────────────
    'trial_title'        => 'Free trial',
    'trial_days'         => 'Trial days',
    'trial_days_unit'    => 'day|days',
    'trial_hint'         => 'Every new company starts with this free period. When it ends, access is blocked right away (there are no grace days) and only comes back once the company subscribes to a plan. Applies to upcoming trials; trials already running do not change.',
    'trial_save'         => 'Save',
    'trial_saved'        => 'Trial days updated.',
    'trial_save_failed'  => 'Could not save the trial days.',
    'trial_audit_reason' => 'Trial days changed on the Plans page.',

    // ── In-app checkout (annual card installments) ────────────────────────────
    'checkout_title'             => 'Card installments',
    'checkout_max_installments'  => 'Maximum interest-free installments',
    'checkout_installments_hint' => 'Annual cycle only, interest-free for the customer (EasyEye absorbs the fee). Also a ceiling: the gateway may accept fewer (Stripe: single payment only).',
    'checkout_saved'             => 'Checkout installments updated.',
    'checkout_save'              => 'Save',
    'checkout_save_failed'       => 'Could not save the installments.',
    'checkout_installments_unit' => 'installment|installments',
    'checkout_audit_reason'      => 'Checkout maximum installments changed on the Plans page.',

    'validation' => [
        'name_required'             => 'The plan name is required.',
        'cycle_required'            => 'Choose the default cycle.',
        'cycle_invalid'             => 'Invalid billing cycle.',
        'cycle_duplicated'          => 'Each cycle can only have one price.',
        'prices_required'           => 'Offer at least one billing cycle.',
        'price_required'            => 'Enter the cycle price.',
        'price_invalid'             => 'The price must be zero or more.',
        'default_cycle_not_offered' => 'The default cycle must be one of the offered cycles.',
        'feature_required'          => 'Fill in this limit (0 = unlimited).',
        'feature_integer'           => 'Use a whole number equal to or greater than zero.',
    ],
];
