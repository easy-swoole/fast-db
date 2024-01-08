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

namespace EasySwoole\FastDb\Commands;

use EasySwoole\Command\Color;
use EasySwoole\Command\CommandManager;
use EasySwoole\EasySwoole\Command\CommandInterface;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\QueryBuilder;
use Swoole\Coroutine;
use Swoole\Timer;

class GenModelAction implements ActionInterface
{
    public function run(): ?string
    {
        Coroutine\run(function () {
            $commandManager = CommandManager::getInstance();
            $table = $commandManager->getOpt('table');
            if (!$table) {
                return Color::danger("The option param 'table' missed!");
            }
            $connectionName = $commandManager->getOpt('db-connection');
            if (!$connectionName) {
                $connectionName = 'default';
            }
            $path = $commandManager->getOpt('path');
            if (!$path) {
                $path = 'App/Model';
            }
            try {
                $columns = $this->formatColumns($this->getColumnTypeListing($connectionName, $table));
            } catch (\Throwable $throwable) {
                echo Color::red($throwable->getMessage()) . "\n";
                echo Color::red("Stack trace:") . "\n";
                echo Color::red($throwable->getTraceAsString()) . "\n";
                echo Color::red("  thrown in " . $throwable->getFile() . " on line " . $throwable->getLine()) . "\n";
                Timer::clearAll();
                return null;
            }

            $project = new Project();
            $class = $this->studly($table);
            $class = $project->getNamespace($path) . $class;
            $filepath = getcwd() . DIRECTORY_SEPARATOR . $project->path($class);
            if (!file_exists($filepath)) {
                $this->mkdir($filepath);
            }

            file_put_contents($filepath, $this->buildClass($table, $class, $connectionName, $columns));
            echo Color::success("Model {$class} was created.") . "\n";
            Timer::clearAll();
            return null;
        });

        return null;
    }

    /**
     * Format column's key to lower case.
     */
    private function formatColumns(array $columns): array
    {
        return array_map(function ($item) {
            return array_change_key_case($item, CASE_LOWER);
        }, $columns);
    }

    /**
     * Get the column type listing for a given table.
     *
     * @param string $connectionName
     * @param string $table
     *
     * @return mixed
     * @throws \EasySwoole\FastDb\Exception\RuntimeError
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws \EasySwoole\Pool\Exception\Exception
     * @throws \Throwable
     */
    private function getColumnTypeListing(string $connectionName, string $table)
    {
        $connection = FastDb::getInstance()->selectConnection($connectionName);

        /** @var Config $fastDbConfig */
        $fastDbConfig = $connection->getConfig($connectionName);
        if (!$fastDbConfig) {
            throw new RuntimeError("connection {$connectionName} not register yet");
        }

        $sql = 'select `column_key` as `column_key`, `column_name` as `column_name`, `data_type` as `data_type`, `is_nullable` as `is_nullable`, `column_comment` as `column_comment`, `extra` as `extra`, `column_type` as `column_type` from information_schema.columns where `table_schema` = ? and `table_name` = ? order by ORDINAL_POSITION';
        $builder = new QueryBuilder();
        $builder->raw($sql, [$fastDbConfig->getDatabase(), $table]);

        return FastDb::getInstance()->selectConnection($connectionName)->query($builder)->getResult();
    }

    private function studly(string $value, string $gap = ''): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', $gap, $value);
    }

    protected function mkdir(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $table, string $name, string $connectionName, $columns): string
    {
        $stub = file_get_contents(__DIR__ . '/stubs/Entity.stub');

        $primaryKeyName = '';
        $primaryKeyType = 'int';
        foreach ($columns as $column) {
            if ($column['column_key'] == 'PRI') {
                $primaryKeyName = $column['column_name'];
                $primaryKeyType = $column['data_type'];
                break;
            }
        }

        return $this
            ->replaceNamespace($stub, $name)
            ->replacePropertyDescAndField($stub, $columns, $primaryKeyName)
            ->replacePrimaryKey($stub, $primaryKeyName, $primaryKeyType)
            ->replaceField($stub, $columns)
            ->replaceConnection($stub, $connectionName)
            ->replaceClass($stub, $name)
            ->replaceTable($stub, $table);
    }

    /**
     * Replace the namespace for the given stub.
     */
    protected function replaceNamespace(string &$stub, string $name): self
    {
        $stub = str_replace(
            ['%NAMESPACE%'],
            [$this->getNamespace($name)],
            $stub
        );

        return $this;
    }

    protected function formatDatabaseType(string $type): ?string
    {
        return match ($type) {
            'tinyint', 'smallint', 'mediumint', 'int', 'bigint' => 'integer',
            'bool', 'boolean' => 'boolean',
            default => null,
        };
    }

    protected function enum_exists(string $enum, bool $autoload = true): bool
    {
        return $autoload && class_exists($enum) && false;
    }

    private function formatPropertyType(string $type): ?string
    {
        $cast = $this->formatDatabaseType($type) ?? 'string';

        if ($this->enum_exists($cast)) {
            return '\\' . $cast;
        }

        return match ($cast) {
            'integer' => 'int',
            'date', 'datetime' => 'string',
            'json' => 'array',
            default => $cast,
        };
    }

    protected function replacePropertyDescAndField(string &$stub, array $columns, string $primaryKeyName): self
    {
        $withComments = CommandManager::getInstance()->issetOpt('with-comments');

        $propertyDescArr = ["/**"];
        $fieldArr = [];
        foreach ($columns as $column) {
            $type = $this->formatPropertyType($column['data_type']);
            $name = $column['column_name'];
            $isNullable = $column['is_nullable'] == 'YES';
            $nullType = $isNullable ? '|null' : '';
            $desc = " * @property {$type}{$nullType} \${$name}";
            if ($withComments) {
                $desc .= " {$column['column_comment']}";
            }
            $propertyDescArr[] = $desc;

            if ($name !== $primaryKeyName) {
                $fieldNullType = $isNullable ? '?' : '';
                $fieldStrArr = [
                    "#[Property]",
                    "public {$fieldNullType}{$type} \${$name};"
                ];
                $fieldArr[] = join("\n    ", $fieldStrArr);
            }
        }
        $propertyDescArr[] = " */";
        $propertyDesc = join("\n", $propertyDescArr);
        $field = join("\n    ", $fieldArr);

        $stub = str_replace(
            ['%PROPERTY_DESC%'],
            [$propertyDesc],
            $stub
        );

        $stub = str_replace(
            ['%FIELD%'],
            [$field],
            $stub
        );

        return $this;
    }

    protected function replacePrimaryKey(string &$stub, string $name, string $type): self
    {
        $stub = str_replace(
            ['%PRIMARY_KEY_ANNOTATION%'],
            ['#[Property(isPrimaryKey: true)]'],
            $stub
        );

        $primaryKeyStr = "public {$type} \${$name};";
        $stub = str_replace(
            ['%PRIMARY_KEY%'],
            [$primaryKeyStr],
            $stub
        );

        return $this;
    }

    protected function replaceField(string &$stub, array $columns): self
    {
        $stub = str_replace(
            ['%FIELD%'],
            ['123'],
            $stub
        );

        return $this;
    }

    /**
     * Get the full namespace for a given class, without the class name.
     */
    protected function getNamespace(string $name): string
    {
        return trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');
    }

    protected function replaceConnection(string &$stub, string $connection): self
    {
        $stub = str_replace(
            ['%CONNECTION%'],
            [$connection],
            $stub
        );

        return $this;
    }

    protected function replaceUses(string &$stub, string $uses): self
    {
        $uses = $uses ? "use {$uses};" : '';
        $stub = str_replace(
            ['%USES%'],
            [$uses],
            $stub
        );

        return $this;
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string &$stub, string $name): self
    {
        $class = str_replace($this->getNamespace($name) . '\\', '', $name);

        $stub = str_replace('%CLASS%', $class, $stub);

        return $this;
    }

    /**
     * Replace the table name for the given stub.
     */
    protected function replaceTable(string $stub, string $table): string
    {
        return str_replace('%TABLE_NAME%', $table, $stub);
    }
}
