<?php

/*
 * The landed-cost calculator (mini app: the lot card and the «Калькулятор» tab), as the
 * sales team's calculator counts it. Defaults are edited in /admin/calculator and kept in
 * calculator_settings; a manager can change any of them for one calculation.
 */
return [
    // key => [default, label, unit]
    'defaults' => [
        'dealer_fee_millions'     => ['default' => 0.44, 'label' => 'Стояночные дилера',                       'unit' => 'млн ₩'],
        'vat_refund_pct'          => ['default' => 0,    'label' => 'Возврат НДС',                             'unit' => '%'],
        'delivery_usd'            => ['default' => 5400, 'label' => 'Доставка до Минска с расходами в Корее',  'unit' => '$'],
        'korea_expenses_usd'      => ['default' => 1000, 'label' => 'Из доставки: расходы в Корее',            'unit' => '$'],
        'customs_usd'             => ['default' => 4100, 'label' => 'Таможня, СВХ и декларант',                'unit' => '$'],
        'transfer_fee_pct'        => ['default' => 2.5,  'label' => 'Комиссия за перевод',                     'unit' => '%'],
        'old_banknotes_extra_pct' => ['default' => 1,    'label' => 'Надбавка к комиссии за старые купюры',    'unit' => '%'],
        'rate_adjustment_krw'     => ['default' => 25,   'label' => 'Вычет из курса Bithumb',                  'unit' => '₩'],
        'service_byn'             => ['default' => 800,  'label' => 'Стоимость наших услуг',                   'unit' => 'BYN'],
    ],

    // Shown in the client's message: «Услуги {company}»
    'company' => env('CALCULATOR_COMPANY', 'Arena Drive'),

    // USDT/KRW on Bithumb: the rate the team quotes, minus rate_adjustment_krw
    'bithumb_url' => env('BITHUMB_TICKER_URL', 'https://api.bithumb.com/public/ticker/USDT_KRW'),
    'rate_cache_seconds' => (int) env('CALCULATOR_RATE_CACHE_SECONDS', 300),
];
