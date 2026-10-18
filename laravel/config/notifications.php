<?php

/*
 * Admin Telegram notifications (`notifications:watch`, /admin/notifications).
 * Recipients, per-recipient types and thresholds live in the DB; this file
 * holds the type catalogue, threshold defaults and external endpoints.
 */
return [
    // type => [label, enabled by default for a new recipient]
    'types' => [
        'jobs.started'          => ['label' => 'Парсинг: запуск',                 'default' => true],
        'jobs.progress'         => ['label' => 'Парсинг: прогресс',               'default' => true],
        'jobs.finished'         => ['label' => 'Парсинг: завершение',             'default' => true],
        'jobs.failed'           => ['label' => 'Парсинг: ошибка / прервано / завис', 'default' => true],
        'infra.db_down'         => ['label' => 'БД недоступна',                   'default' => true],
        'balance.proxy_low'     => ['label' => 'Баланс прокси (FloppyData)',      'default' => true],
        'balance.ai_low'        => ['label' => 'Баланс AI (DeepSeek)',            'default' => true],
        'parser.no_recent_run'  => ['label' => 'Нет успешного прогона',           'default' => false],
    ],

    // key => [default, label, unit]
    'thresholds' => [
        'progress_step_pct'    => ['default' => 25,  'label' => 'Прогресс: шаг',                         'unit' => '%'],
        'progress_every_hours' => ['default' => 2,   'label' => 'Прогресс: не реже чем раз в',           'unit' => 'ч'],
        'stall_minutes'        => ['default' => 15,  'label' => 'Задача «зависла»: нет записи дольше',   'unit' => 'мин'],
        'proxy_min_gb'         => ['default' => 2,   'label' => 'Прокси: остаток меньше',                'unit' => 'ГБ'],
        'proxy_expiry_days'    => ['default' => 3,   'label' => 'Прокси: подписка истекает через',       'unit' => 'дн'],
        'ai_min_usd'           => ['default' => 1,   'label' => 'DeepSeek: баланс меньше',               'unit' => '$'],
        'no_run_hours'         => ['default' => 26,  'label' => 'Нет успешного полного прогона дольше',  'unit' => 'ч'],
        'repeat_hours'         => ['default' => 12,  'label' => 'Повторять открытые алерты каждые (0 = нет)', 'unit' => 'ч'],
    ],

    // Balance APIs are polled at most this often (dry-run always polls).
    'balance_check_minutes' => (int) env('NOTIFICATIONS_BALANCE_CHECK_MINUTES', 15),

    // Terminal jobs (done/error/cancelled) younger than this are still announced.
    'job_window_hours' => 3,

    // State store for dedup, the DB-down incident and the recipient snapshot.
    // Must not depend on MySQL: redis when configured, else the local file store.
    'cache_store' => env('NOTIFICATIONS_CACHE_STORE', env('REDIS_HOST') ? 'redis' : 'file'),

    'deepseek_base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
];
