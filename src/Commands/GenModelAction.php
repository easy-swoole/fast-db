<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Commands;

use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\ExecStatusEnum;
use EasySwoole\Command\Bean\Result;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\QueryBuilder;
use Swoole\Coroutine;

class GenModelAction implements ActionInterface
{
    public function run(Caller $caller, Result $result): void
    {
        $execute = function () use ($caller, $result): void {
            try {
                $this->runAction($caller, $result);
                $result->status = ExecStatusEnum::OK;
            } catch (\Throwable $exception) {
                $result->status = ExecStatusEnum::COMMAND_ACTION_EXEC_FAIL;
                $result->msg = $exception->getMessage();
            }
        };
        if (Coroutine::getCid() > 0) {
            $execute();
        } else {
            Coroutine\run(function () use ($execute): void {
                try {
                    $execute();
                } finally {
                    FastDb::getInstance()->recycleContext();
                    FastDb::getInstance()->reset();
                }
            });
        }
    }

    private function runAction(Caller $caller, Result $result): void
    {
        $options = $caller->commandLine;
        $table = $options->getOption('table');
        if (!is_string($table) || $table === '') {
            throw new RuntimeError("The option param 'table' missed!");
        }
        $connectionName = $options->getOption('db-connection') ?? 'default';
        $path = $options->getOption('path') ?? 'App/Model';
        if (!is_string($connectionName) || $connectionName === '' || !is_string($path) || $path === '') {
            throw new RuntimeError('Database connection and model path must be non-empty strings');
        }
        $withComments = $options->hasOption('with-comments') &&
            !in_array($options->getOption('with-comments'), [false, 'false', '0', 0], true);
        $columns = $this->getColumnTypeListing($connectionName, $table);
        if ($columns === []) {
            throw new RuntimeError("Table {$table} does not exist or has no columns");
        }
        $project = new Project();
        $shortName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $table)));
        $this->checkIdentifier($shortName);
        $class = $project->getNamespace($path) . $shortName;
        $filepath = getcwd() . DIRECTORY_SEPARATOR . $project->path($class);
        $source = $this->buildClass($table, $class, $connectionName, $columns, $withComments);
        $this->mkdir($filepath);
        if (file_put_contents($filepath, $source) === false) {
            throw new RuntimeError("Failed to write model {$filepath}");
        }
        $result->result = ['class' => $class, 'path' => $filepath];
        $result->msg = "Model {$class} was created.";
    }

    private function getColumnTypeListing(string $connectionName, string $table): array
    {
        $db = FastDb::getInstance();
        $config = $db->getConfig($connectionName);
        if (!$config) {
            throw new RuntimeError("connection {$connectionName} not register yet");
        }
        $previousConnection = $db->selectConnection();
        $db->selectConnection($connectionName);
        try {
            $builder = new QueryBuilder();
            $builder->raw('SELECT COLUMN_KEY AS column_key, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_COMMENT AS column_comment FROM information_schema.columns WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$config->getDatabase(), $table]);
            return array_map(fn(array $column): array => array_change_key_case($column, CASE_LOWER), $db->query($builder)->getResult());
        } finally {
            $db->selectConnection($previousConnection);
        }
    }

    protected function mkdir(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeError("Failed to create model directory {$dir}");
        }
    }

    protected function buildClass(string $table, string $name, string $connectionName, array $columns, bool $withComments = false): string
    {
        $primaryKeys = array_filter($columns, fn(array $column): bool => $column['column_key'] === 'PRI');
        if (count($primaryKeys) > 1) {
            throw new RuntimeError('Composite primary keys are not supported by AbstractEntity');
        }
        $properties = [];
        $descriptions = ['/**'];
        foreach ($columns as $column) {
            $field = $column['column_name'];
            $this->checkIdentifier($field);
            $type = $this->formatPropertyType($column['data_type']);
            $nullable = $column['is_nullable'] === 'YES';
            $comment = $withComments ? ' ' . str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $column['column_comment']) : '';
            $descriptions[] = ' * @property ' . $type . ($nullable ? '|null' : '') . ' $' . $field . $comment;
            $attribute = $column['column_key'] === 'PRI' ? '#[Property(isPrimaryKey: true)]' : '#[Property]';
            $properties[] = $attribute . "\n    public " . ($nullable ? '?' : '') . $type . ' $' . $field . ';';
        }
        $descriptions[] = ' */';
        $parts = explode('\\', $name);
        $class = array_pop($parts);
        $namespace = implode('\\', $parts);
        $stub = file_get_contents(__DIR__ . '/stubs/Entity.stub');
        if ($stub === false) {
            throw new RuntimeError('Failed to read entity template');
        }
        return str_replace(
            ['%NAMESPACE%', '%CLASS%', '%PROPERTY_DESC%', '%FIELD%', '%TABLE_NAME%'],
            [$namespace, $class, implode("\n", $descriptions), implode("\n\n    ", $properties), var_export($table, true)],
            $stub
        );
    }

    private function formatPropertyType(string $type): string
    {
        return match (strtolower($type)) {
            'tinyint', 'smallint', 'mediumint', 'int', 'bigint' => 'int',
            'bool', 'boolean' => 'bool',
            'float', 'double', 'real' => 'float',
            // Keep decimal precision and raw JSON strings, matching database values.
            default => 'string',
        };
    }

    private function checkIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $identifier)) {
            throw new RuntimeError("Cannot generate PHP identifier for {$identifier}");
        }
    }
}
