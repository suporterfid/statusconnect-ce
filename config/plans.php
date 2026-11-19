<?php

/*
 * Plans and their limits (#119). A null limit means "no limit".
 *
 * The core has one plan, `unlimited`, and offers none publicly. An edition
 * with paid plans replaces this table (the enterprise service provider loads
 * its own, #250).
 */
return [
    'default' => env('PLAN_DEFAULT', 'unlimited'),

    // Plans offered publicly, in display order.
    'public' => [],

    'plans' => [
        'unlimited' => [
            'name' => 'Unlimited',
            'price_cents' => 0,
            'price_yearly_cents' => 0,
            'monitors' => null,
            'agent_monitors' => null,
            'min_interval_seconds' => 10,
            'status_pages' => null,
            'custom_domain' => true,
            'history_days' => null,
            'check_regions' => true,
        ],
    ],

    'currency' => 'BRL',
];
