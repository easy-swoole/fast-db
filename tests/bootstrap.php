<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('USE_MYSQLI', true);
define('MYSQL_CONFIG', [
    'host' => getenv('FAST_DB_TEST_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('FAST_DB_TEST_PORT') ?: 3306),
    'user' => getenv('FAST_DB_TEST_USER') ?: 'test',
    'password' => getenv('FAST_DB_TEST_PASSWORD') ?: '',
    'database' => getenv('FAST_DB_TEST_DATABASE') ?: 'test',
    'timeout' => 5,
    'charset' => 'utf8mb4',
    'autoPing' => 5,
    'name' => 'default',
    'useMysqli' => true,
    'intervalCheckTime' => 0,
    'maxIdleTime' => 15,
    'maxObjectNum' => 20,
    'minObjectNum' => 0,
    'getObjectTimeout' => 3.0,
    'loadAverageTime' => 0.001,
]);
