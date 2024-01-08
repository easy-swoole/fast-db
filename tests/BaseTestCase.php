<?php
declare(strict_types=1);
/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Utility\File;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

class BaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // check table exists
        $this->createTestTable();
    }

    private function createTestTable()
    {
        $config = new Config(MYSQL_CONFIG);
        $fastDb = (new FastDb())->addDb($config);

        $ddlFileDirs = File::scanDirectory(__DIR__ . '/resources');
        $ddlFiles = $ddlFileDirs['files'];
        foreach ($ddlFiles as $ddlFile) {
            $sql = trim(file_get_contents($ddlFile));
            $fastDb->rawQuery($sql);
        }

        $fastDb->reset();
    }

    protected function truncateTable(string $table)
    {
        $sql = "truncate {$table}";
        FastDb::getInstance()->rawQuery($sql);
    }
}
