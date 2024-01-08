<?php
defined('USE_MYSQLI') ?: define('USE_MYSQLI', false);
defined("MYSQL_CONFIG") ?: define('MYSQL_CONFIG', [
    'host'              => '127.0.0.1',
    'port'              => 3306,
    'user'              => 'easyswoole',
    'password'          => 'easyswoole100%#Q',
    'database'          => 'easyswoole_fastdb',
    'timeout'           => 5,
    'charset'           => 'utf8mb4',
    'autoPing'          => 5,
    'name'              => 'default',
    'useMysqli'         => USE_MYSQLI,
    'intervalCheckTime' => 10 * 1000,
    'maxIdleTime'       => 15,
    'maxObjectNum'      => 20,
    'minObjectNum'      => 5,
    'getObjectTimeout'  => 3.0,
    'loadAverageTime'   => 0.001,
]);
