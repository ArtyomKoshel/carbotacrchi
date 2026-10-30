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
        'transfer_fee_pct'        => ['default' => 2.5,  'label' => 'Комиссия за перевод',                     'unit' => '%'],
        'old_banknotes_extra_pct' => ['default' => 1,    'label' => 'Надбавка к комиссии за старые купюры',    'unit' => '%'],
        'rate_adjustment_krw'     => ['default' => 25,   'label' => 'Вычет из курса Bithumb',                  'unit' => '₩'],
        'service_byn'             => ['default' => 800,  'label' => 'Стоимость наших услуг',                   'unit' => 'BYN'],
        // customs in Belarus, on top of the duty (paid in BYN, counted in $ at the NBRB rate)
        'customs_fee_byn'         => ['default' => 120,  'label' => 'Таможенный сбор',                         'unit' => 'BYN'],
        'recycling_fee_new_byn'   => ['default' => 625,  'label' => 'Утилизационный сбор: машины до 3 лет',    'unit' => 'BYN'],
        'recycling_fee_byn'       => ['default' => 1282, 'label' => 'Утилизационный сбор: старше 3 лет',       'unit' => 'BYN'],
        'svh_byn'                 => ['default' => 401,  'label' => 'СВХ',                                     'unit' => 'BYN'],
        'declarant_byn'           => ['default' => 300,  'label' => 'Декларант',                               'unit' => 'BYN'],
        'valuation_byn'           => ['default' => 600,  'label' => 'Оценка на таможне (пошлина от стоимости)', 'unit' => 'BYN'],
        'valuation_discount_usd'  => ['default' => 2500, 'label' => 'Таможенная стоимость: цена авто минус',   'unit' => '$'],
        'benefit_pct'             => ['default' => 50,   'label' => 'Льгота: скидка с пошлины',                'unit' => '%'],
    ],

    /*
     * Customs duty for individuals (EAEU rates, as customs.gov.by/calc counts them), € per the
     * engine's cm³ or a share of the customs value in €. Petrol, diesel and parallel hybrids:
     *  - up to 3 years inclusive: pct of the value, but not less than per_cc by the value bracket;
     *  - over 3 up to 5 years, over 5 years: per_cc by the engine volume bracket.
     * Electric cars: 15 % of the value; over 5 years also 20 % VAT on the value (not the duty).
     * Age counts from the production date; a bracket's limit is its upper bound, inclusive.
     */
    'customs' => [
        'new' => [ // [value up to €, pct, € per cm³ at least]
            [8500, 54, 2.5], [16700, 48, 3.5], [42300, 48, 5.5], [84500, 48, 7.5], [169000, 48, 15], [null, 48, 20],
        ],
        'mid' => [[1000, 1.5], [1500, 1.7], [1800, 2.5], [2300, 2.7], [3000, 3.0], [null, 3.6]], // [cm³ up to, € per cm³]
        'old' => [[1000, 3.0], [1500, 3.2], [1800, 3.5], [2300, 4.8], [3000, 5.0], [null, 5.7]],
        'electric_pct' => 15,
        'electric_old_vat_pct' => 20,
    ],
    'nbrb_url' => env('NBRB_RATES_URL', 'https://api.nbrb.by/exrates/rates'),

    // Shown in the client's message: «Услуги {company}»
    'company' => env('CALCULATOR_COMPANY', 'Arena Drive'),

    // USDT/KRW on Bithumb: the rate the team quotes, minus rate_adjustment_krw
    'bithumb_url' => env('BITHUMB_TICKER_URL', 'https://api.bithumb.com/public/ticker/USDT_KRW'),
    'rate_cache_seconds' => (int) env('CALCULATOR_RATE_CACHE_SECONDS', 300),
];
