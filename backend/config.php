<?php

declare(strict_types=1);

define('APP_ENV',  $_ENV['APP_ENV']  ?? getenv('APP_ENV')  ?: 'development');
define('BOT_TOKEN', $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN') ?: '');
define('DB_HOST',  $_ENV['DB_HOST']  ?? getenv('DB_HOST')  ?: 'mysql');
define('DB_NAME',  $_ENV['DB_NAME']  ?? getenv('DB_NAME')  ?: 'auction_bot');
define('DB_USER',  $_ENV['DB_USER']  ?? getenv('DB_USER')  ?: 'carbot');
define('DB_PASS',  $_ENV['DB_PASS']  ?? getenv('DB_PASS')  ?: 'carbot_pass');

define('DATA_DIR', dirname(__DIR__) . '/data');
