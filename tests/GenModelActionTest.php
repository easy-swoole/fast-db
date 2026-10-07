<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\ExecStatusEnum;
use EasySwoole\Command\Manager;
use EasySwoole\Command\Utility;
use EasySwoole\FastDb\Commands\ModelCommand;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use PHPUnit\Framework\Attributes\DataProvider;

final class GenModelActionTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FastDb::getInstance()->addDb(new Config(MYSQL_CONFIG));
    }

    #[DataProvider('commentOptions')]
    public function testGenModelThroughCommandManager(array $commentOptions, bool $expectedComments): void
    {
        $file = __DIR__ . '/Model/EasyswooleUser.php';
        $this->assertFileDoesNotExist($file);
        try {
            $result = $this->generate(['--table=easyswoole_user', '--path=tests/Model', ...$commentOptions]);
            $this->assertSame(ExecStatusEnum::OK, $result->status, $result->msg ?? '');
            $this->assertFileExists($file);
            $this->assertSame($file, $result->result['path']);
            $source = file_get_contents($file);
            $this->assertStringContainsString('public int $id;', $source);
            if ($expectedComments) {
                $this->assertStringContainsString('increment id', $source);
            } else {
                $this->assertStringNotContainsString('increment id', $source);
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public static function commentOptions(): array
    {
        return [[[], false], [['--with-comments'], true], [['--with-comments=false'], false]];
    }

    public function testMissingTableDoesNotGenerateFile(): void
    {
        $result = $this->generate(['--table=codex_missing_model_table_20261007', '--path=tests/Model']);
        $this->assertSame(ExecStatusEnum::COMMAND_ACTION_EXEC_FAIL, $result->status);
        $this->assertStringContainsString('does not exist', $result->msg);
        $this->assertFileDoesNotExist(__DIR__ . '/Model/CodexMissingModelTable20261007.php');
    }

    public function testNamedConnectionIsRestoredAndTimersRemainActive(): void
    {
        $db = FastDb::getInstance()->addDb(new Config(MYSQL_CONFIG), 'generator');
        $timer = \Swoole\Timer::tick(60000, static function (): void {});
        $file = __DIR__ . '/Model/EasyswooleUser.php';
        $this->assertFileDoesNotExist($file);
        try {
            $result = $this->generate(['--table=easyswoole_user', '--path=tests/Model', '--db-connection=generator']);
            $this->assertSame(ExecStatusEnum::OK, $result->status, $result->msg ?? '');
            $this->assertSame('default', $db->selectConnection());
            $this->assertTrue(\Swoole\Timer::exists($timer));
        } finally {
            \Swoole\Timer::clear($timer);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[DataProvider('primaryKeySchemas')]
    public function testGeneratedPrimaryKeyTypesFromDatabase(string $suffix, string $sqlType, mixed $value): void
    {
        $table = 'codex_generator_' . $suffix . '_20261007';
        $class = 'CodexGenerator' . ucfirst($suffix) . '20261007';
        $file = __DIR__ . '/Model/' . $class . '.php';
        $db = FastDb::getInstance();
        $this->assertFileDoesNotExist($file);
        $created = false;
        try {
            $db->rawQuery("CREATE TABLE `{$table}` (id {$sqlType} PRIMARY KEY)");
            $created = true;
            $result = $this->generate(['--table=' . $table, '--path=tests/Model']);
            $this->assertSame(ExecStatusEnum::OK, $result->status, $result->msg ?? '');
            require $file;
            $entityClass = 'EasySwoole\\FastDb\\Tests\\Model\\' . $class;
            $entity = new $entityClass(['id' => $value]);
            $this->assertSame($value, $entity->id);
            $this->assertSame($table, $entity->tableName());
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            if ($created) {
                $db->rawQuery("DROP TABLE `{$table}`");
            }
        }
    }

    public static function primaryKeySchemas(): array
    {
        return [['bigint', 'BIGINT', 42], ['varchar', 'VARCHAR(80)', 'uuid-1']];
    }

    private function generate(array $options): \EasySwoole\Command\Bean\Result
    {
        $manager = new Manager();
        $manager->addCommand(new ModelCommand());
        return $manager->exec(new Caller('model', 'gen', Utility::parseArgv($options)));
    }
}
