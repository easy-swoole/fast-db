# EasySwoole FastDb

FastDb 是面向 Swoole 协程环境的 MySQL 数据访问库，组合连接池、SQL 构建器和基于 PHP Attributes 的实体映射。可以在 EasySwoole 框架内使用，也可以独立使用。

本文以当前 `src/` 和 `composer.json` 为准。实体 API 位于 `AbstractEntity`，数据库与连接管理位于 `FastDb`，SQL 构建能力由 `easyswoole/mysqli` 提供。

## 目录

- [特性与运行要求](#特性与运行要求)
- [安装与快速开始](#安装与快速开始)
- [数据库和连接池配置](#数据库和连接池配置)
- [实体与属性映射](#实体与属性映射)
- [查询](#查询)
- [分页分块与聚合](#分页分块与聚合)
- [插入更新删除](#插入更新删除)
- [字段筛选与序列化](#字段筛选与序列化)
- [属性转换](#属性转换)
- [实体生命周期钩子](#实体生命周期钩子)
- [关联查询](#关联查询)
- [事务与行锁](#事务与行锁)
- [多数据库与连接生命周期](#多数据库与连接生命周期)
- [原始 SQL 与底层构建器](#原始-sql-与底层构建器)
- [查询日志与查询栈](#查询日志与查询栈)
- [模型生成器](#模型生成器)
- [API 速查](#api-速查)
- [开发与测试](#开发与测试)
- [使用边界与常见问题](#使用边界与常见问题)

## 特性与运行要求

### 特性

- 每个数据库配置维护独立连接池，同一协程、同一连接名复用当前连接。
- 协程结束后自动归还连接，也可通过 `invoke()` 或 `recycleContext()` 提前归还。
- 连接归还时回滚未提交事务，成功回滚后清理事务状态；回滚失败的连接由连接池丢弃。
- `#[Property]` 映射属性，支持单主键、默认值、nullable 属性和自定义转换。
- 查询结果可返回实体、原始数组或支持遍历/计数/数组访问的 `ListResult`。
- 条件、排序、连接、分组、分页、分块、聚合与 `FOR UPDATE`。
- 实体只更新已变化的字段；成功写入后更新比较基准，支持同一对象连续修改和还原。
- 字段白名单、隐藏字段与可重复序列化的输出限制。
- 插入、更新、删除、初始化钩子，以及显式一对一/一对多关联查询。
- 全局/单次查询回调、耗时统计与协程级查询栈。
- 基于 `easyswoole/command` 2.x 的模型生成器。
- PHPUnit 13.4 测试入口，支持独立单元测试和真实数据库集成测试。

### 运行要求

| 场景 | 要求 |
| --- | --- |
| 运行库 | PHP ≥ 8.1、Swoole ≥ 5.1、mysqli 扩展 |
| 数据库 | MySQL；示例表使用 InnoDB、utf8mb4 与 JSON 字段 |
| 开发测试 | PHP ≥ 8.4.1、PHPUnit ^13.4，以及 Composer 安装的开发依赖 |
| 命令系统 | `easyswoole/command` ^2.0 |

当前驱动通过 mysqli 访问 MySQL。库依赖 Swoole 的协程上下文和连接池；需要数据库访问可协程调度时，在应用入口启用相应 Swoole runtime hooks。

## 安装与快速开始

安装到应用：

```bash
composer require easyswoole/fast-db
```

克隆本项目进行开发：

```bash
composer install
```

开发依赖包含 PHPUnit 13.4，因此开发环境需要 PHP ≥ 8.4.1；部署不运行测试时可使用 `composer install --no-dev`。

### 1. 准备示例表

在自己的数据库执行：

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    status INT NOT NULL DEFAULT 1,
    score INT NOT NULL DEFAULT 0,
    tags JSON DEFAULT NULL,
    meta JSON DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2. 定义实体

例如 `App/Model/User.php`，并确保应用的 Composer PSR-4 配置包含 `App\`：

```php
<?php

namespace App\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

class User extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property]
    public string $name;

    #[Property]
    public int $status = 1;

    #[Property]
    public int $score = 0;

    #[Property]
    public ?string $tags;

    #[Property]
    public ?string $meta;

    public function tableName(): string
    {
        return 'users';
    }
}
```

不设置转换时，JSON 字段使用字符串属性接收数据库的原始 JSON 文本。后文介绍如何映射成数组或对象。

### 3. 注册连接并执行

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use App\Model\User;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$db = FastDb::getInstance();
$db->addDb(new Config([
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'user' => getenv('DB_USER') ?: 'app',
    'password' => (string) getenv('DB_PASSWORD'),
    'database' => getenv('DB_DATABASE') ?: 'app_db',
    'charset' => 'utf8mb4',
    'minObjectNum' => 0,
    'maxObjectNum' => 16,
]));

Swoole\Coroutine\run(function () use ($db): void {
    try {
        $user = new User(['name' => 'Alice', 'score' => 10]);
        if (!$user->insert()) {
            throw new RuntimeException('Insert failed');
        }

        $saved = User::findRecord($user->id);
        if ($saved !== null) {
            $saved->score = 20;
            $saved->update();
            echo json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } finally {
        // 独立 CLI 示例：清理连接池定时器，使进程可以退出。
        $db->recycleContext();
        $db->reset();
    }
});
```

在常驻服务中，通常保持连接池运行，由协程结束自动回收连接；不要在每个请求结束时 `reset()` 整个连接池。

以下代码片段默认已完成连接注册、运行于协程内，并已导入 `App\Model\User`。涉及其他实体时，需要定义对应模型和数据库表。 常用类型的导入如下：

```php
use App\Model\User;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
```


## 数据库和连接池配置

`EasySwoole\FastDb\Config` 继承 `EasySwoole\Pool\Config`，支持通过构造数组和对应 setter 设置参数。

### 数据库参数

| 参数 | 默认值 | 说明 |
| --- | --- | --- |
| `host` | 无 | 数据库地址，必填 |
| `port` | `3306` | 数据库端口 |
| `user` | 无 | 登录用户，必填 |
| `password` | 无 | 密码；无密码也应显式传空字符串 |
| `database` | 无 | 数据库名，必填 |
| `charset` | `utf8mb4` | 连接字符集 |
| `name` | `default` | 连接池名称 |
| `isForceRollback` | `false` | 每次归还连接都尝试回滚；该配置不会因一次回收被清除 |
| `maxConnectTim` | `5` | 当前 FastDb 配置中的字段名；注意末尾缺少 `e` |
| `autoPing` | `5` | 当前配置保留的字段；连接有效性检查由 `beforeUse()` / `intervalCheck()` 执行 |

`maxConnectTim` 与 mysqli 依赖的 `maxConnectTime` 名称不一致，当前透传不能保证修改实际连接超时；不要将该项作为已生效的超时控制。当前 `Config` 没有 `timeout`、`useMysqli`、`maxIdleTime`、`loadAverageTime` 配置属性；连接池负载阈值应使用 `waitLoadAverageTime`。

### 连接池参数

以下默认值对应当前使用的 `easyswoole/pool`：

| 参数 | 默认值 | 单位与作用 |
| --- | --- | --- |
| `maxObjectNum` | `16` | 单个连接池的最大连接数 |
| `minObjectNum` | `8` | 连接池预热/维持的最小连接数，必须小于最大值 |
| `getObjectTimeout` | `3.0` | 等待可用连接的秒数 |
| `intervalCheckTime` | `5000` | 定时检查间隔，毫秒；`0` 关闭该定时检查 |
| `intervalCheckBatchSize` | `8` | 每轮定时检查的最大连接数 |
| `waitLoadAverageTime` | `0.001` | 连接池负载判断的平均等待时间阈值，秒 |
| `extraConf` | 无 | 连接池提供的额外配置槽位 |

连接池按进程维护，多个 worker 的连接总量需要合并计算。`intervalCheckTime=0` 只关闭定时健康检查，连接池还可能有负载维护定时器；独立 CLI 可在工作完成后调用 `reset()` 清理。

### 常用配置操作

```php
$config = new Config([
    'host' => '127.0.0.1',
    'user' => 'app',
    'password' => (string) getenv('DB_PASSWORD'),
    'database' => 'app_db',
]);
$config->setName('default');
$config->setMinObjectNum(0);
$config->setMaxObjectNum(32);
$config->setGetObjectTimeout(2.0);
$config->setIntervalCheckBatchSize(8);

$db = FastDb::getInstance()->addDb($config);
$db->testDb(); // 成功返回 true；连接失败抛异常。
$registered = $db->getConfig('default'); // Config 或 false。
$db->preConnect(); // 对已注册的连接池预热到最小连接数。
```

连接池在首次使用时创建。`addDb()` 更新配置不会自动替换已经创建的连接池，应在初始化阶段完成注册。

## 实体与属性映射

继承 `AbstractEntity` 并实现 `tableName()`。只有带 `#[Property]` 的 public、非 static 属性参与映射；未知输入键会被 `setData()` 忽略。

### Property 参数

| 参数 | 含义 |
| --- | --- |
| `isPrimaryKey: true` | 将该属性声明为主键；每个实体最多一个 |
| `defaultValue` | 实体初始化默认值 |
| `convertObject` | 实现 `ConvertObjectInterface` 的类或枚举 |
| `assignCall` | 输入赋值时执行的 `Call` 回调 |
| `toValue` | 转数组、插入、更新时执行的输出转换回调 |

```php
#[Property(isPrimaryKey: true)]
public int $id;

#[Property(defaultValue: 1)]
public int $status;

#[Property]
public ?string $note;
```

PHP 属性上非 null 的默认值会优先作为初始化值；nullable 属性没有默认值时初始化为 `null`。没有默认值的非 nullable 属性可能保持未初始化，读取前应先赋值或从数据库加载。

### 构造和 setData

```php
$user = new User(['id' => 1, 'name' => 'Alice']);
$user->setData(['name' => 'Bob']); // 形成待更新变更。
$user->setData(['name' => 'Bob'], true); // 同时接受为比较基准。
```

构造参数会使用 `setData($data, true)`，表示这些数据是对象的初始基准。手动创建用于更新的对象时，可以先只传主键，再赋待更新值：

```php
$user = new User(['id' => 1]);
$user->name = 'Bob';
$user->update();
```

如果把主键和目标值全部作为构造参数，之后不修改任何值，`update()` 会认为没有变更而跳过 SQL。`setData(..., true)` 应用于接受已知数据库状态，不用于提交更改。

## 查询

### 查找一条记录

```php
$user = User::findRecord(1); // User|null。
$user = User::findRecord(['name' => 'Alice', 'status' => 1]);
$user = User::findRecord(['score' => [10, '>=']]);
$user = User::findRecord(function (QueryBuilder $query): void {
    $query->where('status', 1)->orderBy('id', 'ASC');
});
```

参数顺序：`findRecord($queryLimit, $tableName = null, $selectForUpdate = false, $onQuery = null)`。没有命中返回 `null`；查询失败抛异常。主键条件支持整数和字符串，按属性主键查找；数组/回调可以查询无主键实体。

### 查找多条记录

```php
$users = User::findAll(); // 普通 PHP 数组，元素为 User。
$users = User::findAll('1'); // 单个主键条件。
$users = User::findAll('1,2,3'); // 数字主键列表。
$users = User::findAll(['status' => 1]);
$rows = User::findAll(['status' => 1], returnAsArray: true);
```

参数顺序：`findAll($queryLimit = null, $tableName = null, $returnAsArray = false, $selectForUpdate = false, $onQuery = null)`。

`findAll()` 的主键简写参数类型是字符串，不是整数。逗号分隔的主键会转换成整数；UUID、字符串主键列表应使用显式 `IN` 条件：

```php
$users = User::findAll(['id' => [['uuid-a', 'uuid-b'], 'IN']]);
```

`findAll()` 返回普通数组，不提供 `list()` / `totalCount()`。需要 `ListResult`、分页或输出字段限制时使用实例 `all()`。

### 实例查询与链式条件

```php
$queryEntity = new User();
$queryEntity->queryLimit()
    ->where('status', 1)
    ->where('score', 10, '>=')
    ->orderBy('id', 'DESC')
    ->limit(20);
$result = $queryEntity->all();

foreach ($result as $user) {
    echo $user->name;
}
```

`queryLimit()` 返回 `Query`，可以通过 `returnEntity()` 返回原实体继续执行：

```php
$result = (new User())->queryLimit()
    ->where('status', 1)
    ->returnEntity()
    ->all();
```

### 条件运算、分组与连接

```php
$entity = new User();
$entity->queryLimit()
    ->where('id', [1, 2, 3], 'IN')
    ->where('name', 'A%', 'LIKE')
    ->orWhere('status', 2);
$result = $entity->all();
```

`where($column, $value, $operator = '=', $cond = 'AND')`，`orWhere()` 使用 OR。多个 AND/OR 的分组语义由底层 SQL 构建器决定，复杂条件建议通过 `func()` 或参数化 SQL 明确括号。

```php
$entity = new User();
$entity->queryLimit()
    ->join('profiles p', 'p.user_id = users.id', 'LEFT')
    ->fields(['users.id', 'users.name', 'p.bio'], true)
    ->orderBy('users.id', 'ASC');
$rows = $entity->all()->list();
```

连接查询的额外列/别名若不是实体声明的属性，实体模式会忽略它们，建议返回原始数组。`groupBy($field)` 添加分组；`func(callable)` 接收当前 `QueryBuilder`，可使用其其他能力：

```php
$entity->queryLimit()->func(function (QueryBuilder $query): void {
    $query->having('COUNT(*)', 1, '>');
});
```

列名、表名、排序表达式等 SQL 标识符应由应用控制；绑定参数只处理数据值。

## 分页分块与聚合

### 分页与总数

```php
$entity = new User();
$entity->queryLimit()
    ->where('status', 1)
    ->orderBy('id', 'ASC')
    ->page(2, true, 20);
$page = $entity->all();

$items = $page->list();
$total = $page->totalCount(); // 请求统计时为总数，否则 null。
$currentSize = count($page); // 本页返回的数量。
```

`page($page, $withTotalCount = false, $pageSize = 10)` 从第 1 页开始；偏移量为 `($page - 1) * $pageSize`。`limit($num, $withTotalCount = false)` 仅限制数量。

总数统计通过 `SQL_CALC_FOUND_ROWS` 和额外的 `SELECT FOUND_ROWS()` 完成，不是独立的 `COUNT(*)` 查询。对于使用相应 MySQL 特性的环境，可根据业务用单独计数查询替代。

```php
use EasySwoole\FastDb\Beans\Page;

$limit = new Page();
$limit->getPage(); // null，表示 limit 模式。
$limit->toLimitArray(); // [10]。

$page = new Page(3, true, 20);
$page->toLimitArray(); // [40, 20]。
```

调用方应保证页码、每页条数和分块大小为合理正数。

### 分块处理

```php
$entity = new User();
$entity->queryLimit()->where('status', 1)->orderBy('id', 'ASC');
$entity->chunk(function (User $user): void {
    // 逐条处理。
    echo $user->id;
}, 100);
```

`chunk($callback, $chunkSize = 10)` 按 OFFSET 分页读取，每次回调接收一条记录，不是一个数组块。请提供稳定排序；遍历中删除记录或修改筛选条件可能改变后续页的偏移，不适合需要稳定游标的迁移任务。

### 计数和聚合

```php
$total = (new User())->count();
$nonNullNames = (new User())->count('name');

$entity = new User();
$entity->queryLimit()->fields(['score', 'status']);
$counts = $entity->count(); // ['score' => ..., 'status' => ...]。

$sum = (new User())->sum('score');
$average = (new User())->avg('score');
$maximum = (new User())->max('score');
$minimum = (new User())->min('score');
$values = (new User())->sum(['score', 'status']);
```

`count($field = '*', $group = null)` 返回整数；通过 `fields()` 指定多列计数时返回列名到计数的数组。

`sum()` / `avg()` / `max()` / `min()` 的签名为 `($cols, $group = null, $force = true)`。单列返回数字，多列返回数组；默认强制转为 float，没有聚合值时单列按 0 处理。金额等精确值可用底层参数化 SQL 获取原始结果，避免浮点精度损失。

这些快捷聚合方法只取第一行。传入 `$group` 或先调用 `groupBy()` 不会返回所有分组；需要完整分组结果时用 `QueryBuilder` 查询。

## 插入更新删除

### 插入

```php
$user = new User(['name' => 'Alice', 'score' => 10]);
$ok = $user->insert(); // bool。
$id = $user->id; // 自增主键由 last insert id 回填。
```

插入从 `toArray(true)` 生成数据，因此字段选择、隐藏字段和转换配置会影响实际写入列。普通 null 属性会被过滤，数据库默认值可生效；转换字段是否生成 null 由转换逻辑决定。未从数据库读取的默认值、触发器结果等需要重新查询才能获得。

支持 MySQL duplicate-key 更新：

```php
$user->insert(['name', 'score']);
```

参数为底层 `onDuplicate()` 的更新列列表；命中重复键时按构建器语义更新这些列。该方法按结果、影响行数及 insert id 判断成功，不能用返回值区分本次是插入还是重复键更新；需要准确的最终记录时重新查询。

### 变更更新

```php
$user = User::findRecord(1);
if ($user !== null) {
    $user->name = 'Bob';
    $user->update();
    $user->name = 'Alice';
    $user->update(); // 比较基准已更新，改回原值也会执行。
}
```

`update()`：

- 以对象的比较基准识别已变化字段。
- 对 `ConvertObjectInterface` 属性调用 `toValue()`；对配置了 `Property::toValue` 的普通属性调用对应回调。
- 使用实体主键与当前主键值构造 WHERE 条件，也会使用之前设置的额外查询条件。
- 有实际变更且影响行数大于 0 时返回 `true`，影响行数为 0 时返回 `false`；没有待写入变更时返回 `true` 并跳过 SQL。
- 成功后仅同步本次写入字段的比较基准，未被字段筛选选中的变更仍待保存；失败不接受新的基准。

```php
$user->name = 'Bob';
$user->score = 50;
$user->queryLimit()->fields(['name']);
$user->update(); // 只更新 name。
$user->update(); // 保存尚未写入的 score。
```

`fields(null)` 或 `fields([])` 不限制更新字段。成功执行的更新会重置查询配置，需要每次重新设置限制。影响行数依赖 MySQL 返回结果，不代表是否命中记录的完整信息。

### 快捷更新

```php
$affected = User::fastUpdate(1, ['status' => 0]);
$affected = User::fastUpdate('1,2', ['status' => 0]);
$affected = User::fastUpdate(['status' => 1], ['score' => 5]);
$affected = User::fastUpdate(function (QueryBuilder $query): void {
    $query->where('score', 10, '<');
}, ['status' => 0]);
```

签名：`fastUpdate($updateLimit, $data, $tableName = null, $onQuery = null)`，通常返回影响行数。它直接写入 `$data`，不执行实体更新钩子，也不做属性转换或变更比较。

当前 `fastUpdate()` 的条件数组逐项传入 `where($key, $value)`，不支持 `findRecord()` 中的 `[值, 运算符]` 展开简写；复杂条件请用回调。空条件数组会生成无 WHERE 的更新，调用前应明确业务意图。

### 删除

```php
$user = User::findRecord(1);
$deleted = $user?->delete(); // bool；没有对象时示例结果为 null。

$affected = User::fastDelete(1);
$affected = User::fastDelete('1,2');
$affected = User::fastDelete(['score' => [10, '<']]);
$affected = User::fastDelete(function (QueryBuilder $query): void {
    $query->where('status', 0);
});
```

`delete()` 使用对象主键并触发删除钩子，影响至少一行才返回 `true`。`fastDelete($deleteLimit, $tableName = null, $onQuery = null)` 直接删除并返回影响行数，不触发实体钩子。空数组/空字符串条件直接返回 0；回调如果不添加条件仍可能删除所有记录。

实例 `update()` / `delete()` 用 `empty()` 检查主键值，因此值为 0 / `'0'` 的主键无法通过该检查；必要时使用带显式条件的快捷方法或底层构建器。

### 批量插入

当前实体没有 `insertAll()`。使用底层构建器：

```php
$result = FastDb::getInstance()->query(function (QueryBuilder $query): void {
    $query->insertAll('users', [
        ['name' => 'Alice', 'status' => 1, 'score' => 10],
        ['name' => 'Bob', 'status' => 1, 'score' => 20],
    ]);
});
$affected = $result->getConnection()->getLastAffectRows();
```

底层批量写入不触发每个实体的钩子或属性转换，应先准备符合数据库格式的数据。

## 字段筛选与序列化

### 查询列与返回数组

```php
$entity = new User();
$entity->queryLimit()->fields(['id', 'name']);
$entities = $entity->all();

$entity = new User();
$entity->queryLimit()->fields(['id', 'name'], true);
$rows = $entity->all()->list();
```

`fields($fields = null, $returnAsArray = false)` 同时控制查询列和返回模式。`returnAsArray()` 也可单独开启数组模式；它返回数据库行，不执行实体转换回调。

### 隐藏字段与限制传播

```php
$entity = new User();
$entity->queryLimit()
    ->fields(['id', 'name', 'score'])
    ->hideFields('score')
    ->persistFieldLimit();
$result = $entity->all();
echo json_encode($result, JSON_THROW_ON_ERROR);
```

`hideFields()` 接受字符串或字符串数组；隐藏配置会过滤返回数组/实体输入数据，`persistFieldLimit(true)` 将 `fields`、`hideFields` 配置传给 `all()` 返回的子实体，以限制之后的序列化。

实体初始化仍可能生成被查询省略字段的默认值或 null。需要确保输出键也被隐藏时，使用 `persistFieldLimit()`，或者直接返回数组。

### 重复序列化

```php
$user = User::findRecord(1);
if ($user !== null) {
    $user->queryLimit()->hideFields(['tags', 'meta']);
    $first = $user->toArray();
    $second = json_encode($user, JSON_THROW_ON_ERROR);
    // 两次输出都保留隐藏限制。

    $user->queryLimit()->hideFields([]); // 显式取消隐藏。
    $user->queryLimit()->fields(null); // 显式取消字段白名单。
}
```

`toArray($filterNull = false)` / `jsonSerialize()` 输出实体映射字段，调用转换逻辑，并保留输出字段限制。`toArray()` 会清理 SQL 条件，不保留此前 `where()` / `orderBy()` 等查询状态。

`toArray(true)` 的 null 过滤位于普通属性分支；带转换对象或 `toValue` 的字段应通过转换器控制输出，不能假设转换结果为 null 的字段必然被移除。

### ListResult 与 ArrayList

`all()` / `relateMany()` 返回 `ListResult`，继承 `ArrayList`：

```php
$items = $result->list(); // 底层数组，元素仍可能是实体。
$items = $result->toArray(); // 同样返回底层数组，不递归调用实体 toArray。
$first = $result->first(); // 索引 0 的实体/数组或 null。
$total = $result->totalCount(); // 未请求总数时 null。
$count = count($result);

$result[] = new User(['id' => 100, 'name' => 'Local item']);
foreach ($result as $key => $item) {
    // 支持追加后遍历；保留显式键。
}
unset($result[1]); // 保留其他元素的键。
```

`remove($indexOrEntity)` 接受数组位置整数或对象实例，对象按身份比较并删除第一个匹配项。整数走 `array_splice` 的位置语义，与 `unset($list[$key])` 的键语义不同。对稀疏索引结果使用 `list()` 取得数组后自行整理更明确。

`json_encode($result)` 序列化底层数据；`totalCount` 不自动写入 JSON，可在响应中自行包装为 `['list' => $result, 'total' => $result->totalCount()]`。

## 属性转换

可以选择转换对象，或配对输入/输出回调。`convertObject` 与 `assignCall` / `toValue` 不能同时配置在一个属性上。

### ConvertList

在自己的实体中声明：

```php
use EasySwoole\FastDb\AbstractInterface\ConvertList;

#[Property(convertObject: ConvertList::class)]
public ?ConvertList $tags;
```

```php
$user = new User(['id' => 1, 'name' => 'Alice', 'tags' => '["php"]']);
$user->tags?->append('swoole');
$user->tags?->has('php', true);
$user->tags?->length();
$user->tags?->first();
$user->tags?->remove('php', true);
$user->tags?->toArray(); // PHP 数组。
$user->tags?->toValue(); // JSON 字符串。
$user->update();

$user->setData(['tags' => null]);
$user->update(); // nullable 属性可清空到 SQL NULL。
```

本节示例需要把快速开始模型的 `tags` 声明替换为转换属性。实体 `toArray()` 对转换对象使用 `toValue()`，因此这里输出的 `tags` 是 JSON 字符串，不是嵌套数组。

`ConvertList::toObject()` 接收数组或 JSON 字符串，空值/不能解码为数组的值转成空列表。`append(null)` 不追加。`remove()` 会保留原数组键，删除后 JSON 编码可能呈现对象形式；需要列表 JSON 时可先使用 `array_values()` 整理数据并重新赋值。

### ConvertBean

```php
use EasySwoole\FastDb\AbstractInterface\ConvertBean;

class Address extends ConvertBean
{
    public ?string $city = null;
    public ?string $province = null;
}
```

在实体中：

```php
#[Property(convertObject: Address::class)]
public ?Address $address;
```

`ConvertBean` 继承 `SplBean`，可从 JSON 或数组恢复字段，`toValue()` 返回其字符串表示，用于数据库存储。必须为模型增加相应的数据库字段。

### 自定义转换对象与枚举

实现 `ConvertObjectInterface`，提供 `toObject(mixed $data)` 和 `toValue()`：

```php
use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;

enum UserState: int implements ConvertObjectInterface
{
    case Disabled = 0;
    case Enabled = 1;

    public static function toObject(mixed $data): object
    {
        return self::tryFrom((int) $data) ?? self::Disabled;
    }

    public function toValue(): int
    {
        return $this->value;
    }
}
```

```php
#[Property(convertObject: UserState::class)]
public UserState $status;
```

非 nullable 转换属性在初始化时也调用转换器，转换器需要能处理默认值或 null。传入已经是目标类型的对象时，`setData()` 直接接受该对象。

### assignCall 与 toValue

JSON 字段直接映射为数组：

```php
use EasySwoole\FastDb\Attributes\Hook\Call;

#[Property(
    assignCall: new Call([JsonUser::class, 'decodeMeta']),
    toValue: new Call([JsonUser::class, 'encodeMeta'])
)]
public ?array $meta;

public static function decodeMeta(string|array|null $value): ?array
{
    if ($value === null || is_array($value)) {
        return $value;
    }
    return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
}

public static function encodeMeta(?array $value): ?string
{
    return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
}
```

以上属性和方法放在自己的 `JsonUser extends AbstractEntity` 中，并实现 `tableName()`。

`assignCall` 在构造数据和 `setData()` 时执行；直接赋值 `$entity->meta = [...]` 不会执行输入回调。`toValue` 在 `toArray()`、插入和更新需要该值时执行。更新比较基准保留赋值后的属性表示，实际写入使用转换后的值，因此没有变化时不会反复更新，保存后也能改回原值。

回调可使用显式运行时参数：

```php
new Call(
    [JsonUser::class, 'encodeWithContext'],
    [Call::PARAM_PROPERTY_VALUE, Call::PARAM_CURRENT_ENTITY, 'custom-option']
);
```

`PARAM_PROPERTY_VALUE` 替换为当前值，`PARAM_CURRENT_ENTITY` 替换为当前实体，其余参数按字面传入。未指定参数时，属性回调收到当前值。

## 实体生命周期钩子

钩子是类级 Attribute：`OnInitialize`、`OnInsert`、`OnUpdate`、`OnDelete`。可以使用实例方法名或 callable。

```php
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;

#[OnInitialize('initializeEntity')]
#[OnInsert('beforeInsert')]
#[OnUpdate('beforeUpdate')]
#[OnDelete('beforeDelete')]
class AuditedUser extends User
{
    protected function initializeEntity(): void
    {
        // 初始化发生在构造数据 setData() 之前。
    }

    protected function beforeInsert(): bool
    {
        return isset($this->name) && $this->name !== '';
    }

    protected function beforeUpdate(): bool
    {
        return true;
    }

    protected function beforeDelete(): bool
    {
        return true;
    }
}
```

插入、更新、删除钩子返回严格的 `false` 会中止操作；其他返回值继续执行。初始化钩子没有以返回 false 中止构造的机制。

传入 callable 时，默认收到当前实体；显式参数支持 `Call::PARAM_CURRENT_ENTITY`。钩子可从父类继承，最近一级的声明优先。当前没有 after-insert / after-update / after-delete 钩子，`fastUpdate()`、`fastDelete()` 和原始 SQL 不触发这些生命周期钩子。

## 关联查询

关联是按需执行的独立查询，不自动 eager loading，也不自动通过 JOIN 加载。

例如用户有一条 profile 和多条 order；目标实体要声明 `userId` 属性，数据库中需要对应字段：

```php
use EasySwoole\FastDb\Attributes\Relate;

class RelatedUser extends User
{
    #[Relate(targetEntity: Profile::class, targetProperty: 'userId')]
    public function profile()
    {
        return $this->relateOne();
    }

    #[Relate(targetEntity: Order::class, targetProperty: 'userId', selfProperty: 'id')]
    public function orders()
    {
        return $this->relateMany();
    }
}
```

```php
$user = RelatedUser::findRecord(1);
if ($user !== null) {
    $profile = $user->profile(); // 目标实体|null。
    $orders = $user->orders(); // ListResult。

    $user->queryLimit()->where('status', 1)->page(1, true, 20);
    $activeOrders = $user->orders();

    $user->queryLimit()->returnAsArray();
    $profileRow = $user->profile(); // 原始数组|null。
}
```

`selfProperty` 省略时使用当前实体主键；`targetProperty` 和显式 `selfProperty` 必须是已映射属性。也可以显式传入 `Relate` 对象和目标表名：`relateOne($relate, $tableName)` / `relateMany($relate, $tableName)`，这两个方法是 protected，需要从实体方法调用。

`relateOne()` 查询最多两条用于检测异常，多于一条时抛 `RuntimeError`。查询配置在关联执行后重置。当前 `relateOne()` / `relateMany()` 不具备 `all()` 的完整隐藏字段和输出限制传播行为，需要隐藏目标字段时自行在返回实体上设置限制或使用明确列选择/数组结果。

遍历大量父实体逐一加载关联会产生多次 SQL；需要批量加载时可用底层查询或自行组织 `IN` 查询。

## 事务与行锁

```php
$db = FastDb::getInstance();
if (!$db->begin()) {
    throw new RuntimeException('Cannot begin transaction');
}
try {
    $user = User::findRecord(1, selectForUpdate: true);
    if ($user === null) {
        throw new RuntimeException('User not found');
    }
    $user->score += 10;
    if (!$user->update()) {
        throw new RuntimeException('Update failed');
    }
    if (!$db->commit()) {
        throw new RuntimeException('Commit failed');
    }
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}
```

| 方法 | 行为 |
| --- | --- |
| `begin($client = null, $timeout = 3.0)` | 默认获取当前连接并开始事务；事务状态已为 true 时直接返回 true |
| `commit($client = null, $timeout = 3.0)` | 默认提交当前连接；没有连接/没有事务时返回 true |
| `rollback($client = null, $timeout = 3.0)` | 默认回滚当前连接；没有连接/没有事务时返回 true |
| `isInTransaction($connection = null)` | 读取当前或指定连接的事务状态 |

这不是 savepoint 嵌套事务：多次 `begin()` 不会增加嵌套层级。显式传 `Connection` 可操作指定连接。当前实现中 `$timeout` 为整数时传给 mysqli 的事务方法作为 flags；浮点值不传入驱动，不应把该参数当成已实现的事务超时。

`queryLimit()->selectForUpdate()` 或 `all(true)` 也可生成锁定查询。锁应配合事务使用，并保持查询、更新、提交在同一协程和同一连接名内。

连接被回收时，未提交事务会被回滚。`invoke()` 只归还本次调用新获取的连接；复用已有连接或嵌套调用时，连接继续由外层作用域管理。

事务内成功执行 `update()` 后，连接会保存实体在本次事务中首次更新前的字段比较基准。显式回滚或连接回收触发回滚时，会恢复比较基准，保留当前属性值，因此可以再次调用 `update()` 重试保存。提交后清除基准快照。此机制只管理实体 `update()` 的字段比较状态，不撤销属性赋值或其他业务对象状态。

InnoDB 死锁错误（1213）会自动回滚整个事务。连接在收到该错误时会清理事务标记并恢复实体比较基准，之后可调用 `begin()` 开启新事务并重试整个业务操作。错误继续向调用方抛出，不会自动重试。普通 SQL 错误及锁等待超时（1205）不会按死锁处理，仍需显式回滚。死锁识别依赖底层 mysqli 库保留异常错误码；使用已支持该行为的依赖版本。

事务操作成功后先同步事务状态，再执行日志回调。日志回调异常仍向调用方传播；尤其是 `commit()` 的日志回调抛异常时，数据库已经提交，调用方不能据此认定提交失败或直接重试。

## 多数据库与连接生命周期

### 注册和选择连接

```php
$db = FastDb::getInstance();
$db->addDb(new Config($primaryConfig), 'primary');
$db->addDb(new Config($reportingConfig), 'reporting');

$previous = $db->selectConnection(); // 当前连接名，未设置时 default。
$db->selectConnection('reporting');
try {
    $users = User::findAll(['status' => 1]);
} finally {
    $db->selectConnection($previous);
}
```

第二个 `addDb()` 参数会修改传入 Config 的名称；不要将同一个 Config 对象重复注册成不同连接名，建议分别构造配置。

连接选择按协程保存。实体没有自身绑定的连接名，所有实体查询使用 `FastDb::getInstance()` 当前选择的连接；使用局部 `new FastDb()` 不会改变实体所使用的单例。

### 提前归还

```php
$rows = $db->invoke(function (Connection $connection): array {
    $query = new QueryBuilder();
    $query->get('users', 10, ['id', 'name']);
    return $connection->query($query);
});
```

`invoke()` 返回回调返回值，finally 中归还由本次调用获取的连接。直接调用 `$connection->query()` 不会经过 `FastDb` 的日志包装；需要全局日志时在回调中调用 `$db->query()`。不要保存回调中的连接给后续协程使用。

```php
$db->currentConnection(); // 当前选中连接的 Connection|null，不主动创建连接。
$db->recycleContext(); // 归还当前协程所有连接，并清理查询栈。
$db->reset(); // 重置所有已创建连接池，清理连接池定时器。
```

`reset()` 用于关闭、测试或重新初始化阶段，不是正常请求结束的替代动作；应先归还上下文连接，并避免还有协程使用连接池时调用。

## 原始 SQL 与底层构建器

下文需要导入：

```php
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
```

### 参数化 SQL

```php
$query = new QueryBuilder();
$query->raw('SELECT id, name FROM users WHERE status = ? AND score >= ?', [1, 10]);
$result = FastDb::getInstance()->query($query);
$rows = $result->getResult();
$first = $result->getResultOne(); // 第一行或 null。
```

也可传回调：

```php
$result = FastDb::getInstance()->query(function (QueryBuilder $query): void {
    $query->where('status', 1)->get('users', 10, ['id', 'name']);
});
```

`query()` 回调需要完成 `get()` / `insert()` / `update()` / `delete()` / `raw()` 等最终构建动作。

### 原始 SQL

```php
$result = FastDb::getInstance()->rawQuery('SELECT CURRENT_TIMESTAMP AS now');
$row = $result->getResultOne();
```

`rawQuery(string $sql)` 不支持单独的绑定参数。动态数据使用 `QueryBuilder::raw($sql, $params)`，不要拼接输入数据。

### QueryResult

| 方法 | 返回内容 |
| --- | --- |
| `getResult()` | 驱动结果；查询失败回调中为 null |
| `getResultOne()` | 数组结果的第一行，否则 null |
| `getConnection()` | 本次实际使用的连接；实体失败回调的替代结果可能未设置连接 |
| `getQueryBuilder()` | 构建器查询的副本；原始 SQL 查询时 null |
| `getRawSql()` | 原始 SQL 文本；构建器查询时通常 null |
| `getStartTime()` / `getEndTime()` | 秒单位的时间戳，可相减计算执行耗时 |

写入结果的影响行数和 insert id 在连接上：

```php
$affected = $result->getConnection()->getLastAffectRows();
$insertId = $result->getConnection()->getLastInsertId();
```

这些连接级数值会随着后续 SQL 改变，应在本次调用后立即读取。`query()` 签名虽然带可选 `$timeout`，当前 mysqli Client 没有对应逐次查询超时实现。

## 查询日志与查询栈

### 全局回调

```php
$db->setOnQuery(function (QueryResult $result): void {
    $durationMs = ($result->getEndTime() - $result->getStartTime()) * 1000;
    $builder = $result->getQueryBuilder();
    $sql = $result->getRawSql() ?? $builder?->getLastPrepareQuery();
    $params = $builder?->getLastBindParams();
    // 写入自己的日志系统：$sql、$params、$durationMs。
});
```

开始时间在获得连接之后记录，因此不包含连接池等待。`query()` / `rawQuery()` 结束时间在执行结束或失败后、调用日志回调之前记录，不包含日志回调耗时。事务成功执行的 begin/commit/rollback 也会进入日志回调。

SQL 抛异常时仍调用查询回调，`getResult()` / `getResultOne()` 可以安全读取为 null。查询已失败时，回调另抛异常不会覆盖原始查询异常；查询成功时，回调异常正常抛出。`QueryResult` 没有保存原异常的独立字段，需要在调用层捕获异常。

底层 `getLastQuery()` 是用于调试的占位符替换文本，可能与实际绑定执行的细节不同；尤其 null 的展示不能代替实际 SQL 判断。优先记录 `getLastPrepareQuery()` 和 `getLastBindParams()`。

### 实体与单次回调

```php
$entity = new User();
$entity->setOnQuery(function (QueryResult $result): void {
    // 此实体调用的查询。
});
$entity->all();
$entity->setOnQuery(null);

$user = User::findRecord(1, onQuery: function (QueryResult $result): void {
    // 本次静态查询。
});
```

`findAll()`、`fastUpdate()`、`fastDelete()` 也有 `$onQuery` 参数。全局回调与实体/单次回调可以同时执行。实体查询在获得结果前失败时会创建替代 `QueryResult`，该结果仅确保时间与 SQL 信息、空结果可读，不保证拥有可用连接。

### 查询栈

```php
$db->isEnableQueryStack(true);
$db->rawQuery('SELECT 11');
$db->rawQuery('SELECT 22');

$stack = $db->getQueryStack(); // 当前协程记录数组，未记录时 null。
$first = $db->getQueryStack(0);
$last = $db->getQueryStack(-1); // SELECT 22。
$previous = $db->getQueryStack(-2); // SELECT 11。
$missing = $db->getQueryStack(100); // null。
$db->isEnableQueryStack(false); // 停止追加，不立即删除已有记录。
```

每项 `QueryStack` 包含 `connectionName`、`query`、`rawQuery`、`startTime`、`endTime`。协程退出/归还上下文时清理。开启后会积累记录，长生命周期协程应控制使用范围。

## 模型生成器

### command 2.x 集成

`ModelCommand` 继承 `EasySwoole\Command\AbstractInterface\AbstractCommand`，注册 `gen` Action，使用 `Caller::commandLine` 读取参数并通过 `Result` 返回结果。没有旧版 `CommandManager` 依赖。

在 EasySwoole 框架的 `bootstrap.php` 中注册：

```php
<?php

use EasySwoole\EasySwoole\Command\CommandRunner;
use EasySwoole\FastDb\Commands\ModelCommand;

// 应用初始化时注册 FastDb 数据库配置。
// 对 model 命令，可按自己的启动流程先执行 Core::initialize()。
CommandRunner::getInstance()->addCommand(new ModelCommand());
```

生成前需要把目标连接加入 `FastDb::getInstance()`；框架注册命令本身不会替你注册数据库。

```bash
php easyswoole.php model gen --table=users --path=App/Model
php easyswoole.php model gen --table=users --db-connection=reporting --with-comments
php easyswoole.php model gen --table=users --with-comments=false
```

| 参数 | 默认值 | 说明 |
| --- | --- | --- |
| `--table` | 必填 | 读取结构的表名 |
| `--db-connection` | `default` | 使用哪个已注册连接读取表结构 |
| `--path` | `App/Model` | 输出目录，需匹配项目 `composer.json` 的 PSR-4 路径 |
| `--with-comments` | 不启用 | 添加数据库列注释；显式 false/0 关闭 |

表名如 `user_profile` 生成类名 `UserProfile`。目录从当前工作目录下的 `composer.json` 推导命名空间，因此应从应用根目录运行命令。

### 独立 Manager 用法

```php
use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\ExecStatusEnum;
use EasySwoole\Command\Manager;
use EasySwoole\Command\Utility;
use EasySwoole\FastDb\Commands\ModelCommand;

$manager = new Manager();
$manager->addCommand(new ModelCommand());
$caller = new Caller('model', 'gen', Utility::parseArgv([
    '--table=users',
    '--path=App/Model',
    '--with-comments',
]));
$result = $manager->exec($caller);

if ($result->status === ExecStatusEnum::OK) {
    echo $result->msg;
    $class = $result->result['class'];
    $path = $result->result['path'];
} else {
    echo $manager->result2HelpMsg($caller, $result);
}
```

`GenModelAction::run(Caller $caller, Result $result): void` 支持直接调用。已有协程时在当前协程执行；无协程时创建协程执行，并在结束时回收/重置数据库连接池。生成过程不会对整个应用调用 `Timer::clearAll()`；在已有协程中也会恢复之前选择的连接。

### 类型映射与生成行为

| MySQL 类型 | 生成 PHP 类型 |
| --- | --- |
| tinyint / smallint / mediumint / int / bigint | `int` |
| bool / boolean | `bool` |
| float / double / real | `float` |
| decimal、JSON、日期时间、文本及其他类型 | `string` |

列允许 NULL 时使用 `?类型`。主键使用同一套类型映射，标记 `#[Property(isPrimaryKey: true)]`；支持整数和字符串主键。JSON 默认生成字符串，若需要数组或对象映射，生成后自行配置转换器。

- 无主键表仍可生成模型；使用条件查询或快捷写入，不能调用依赖主键的实例更新/删除。
- 多列复合主键返回明确错误，因为实体只支持一个主键。
- 无表结构、未注册连接、无法映射的 PHP 属性名、目录/文件写入失败，通过失败 `Result` 反馈。
- 列注释中的换行和注释结束符会整理，表名写入 PHP 字符串时转义。
- 同名模型文件会被覆盖；生成前保留自己的手工修改。
- `--db-connection` 只选择读取结构的连接，生成模型不会自动绑定该连接。
- 生成器不会生成数据库默认值映射、自增标记、索引/关联方法或 CRUD 服务代码。
- `bigint unsigned` 超过 PHP 整数范围时需自行使用合适的字符串映射与转换。

## API 速查

### AbstractEntity

| API | 用途 / 返回 |
| --- | --- |
| `new Entity(?array $data)` | 初始化并接受输入为比较基准 |
| `tableName(): string` | 子类必须实现表名 |
| `setData(array $data, bool $mergeCompare = false)` | 输入转换、赋值；返回当前实体 |
| `queryLimit(): Query` | 取得当前查询限制 |
| `all(bool $selectForUpdate = false): ListResult` | 执行实例查询 |
| `findRecord($queryLimit, $tableName, $selectForUpdate, $onQuery)` | 单条实体或 null |
| `findAll($queryLimit, $tableName, $returnAsArray, $selectForUpdate, $onQuery)` | 普通数组 |
| `insert(?array $updateDuplicateCols = null)` | 插入/重复键更新；bool |
| `update()` / `delete()` | 按对象主键更新/删除；bool |
| `fastUpdate($limit, array $data, $tableName, $onQuery)` | 直接更新；影响行数 |
| `fastDelete($limit, $tableName, $onQuery)` | 直接删除；影响行数 |
| `count($field = '*', $group = null)` | 整数或多列计数数组 |
| `sum/avg/max/min($cols, $group = null, $force = true)` | 数字或多列聚合数组 |
| `chunk(callable $func, int $chunkSize = 10)` | OFFSET 分页逐条回调 |
| `toArray(bool $filterNull = false): array` | 属性转换并筛选输出 |
| `jsonSerialize()` | 使用 toArray 输出 |
| `setOnQuery(?callable $call)` | 当前实体查询回调；返回实体 |
| `relateOne()` / `relateMany()` | protected 关联查询，需要实体方法包装 |

### Query

| API | 用途 |
| --- | --- |
| `where($column, $value, $operator = '=', $cond = 'AND')` | 条件 |
| `orWhere($column, $value, $operator = '=')` | OR 条件 |
| `orderBy($field, $direction = 'DESC', $customFieldsOrRegExp = null)` | 排序 |
| `groupBy(string $field)` | 分组 |
| `join($table, $condition, $type = '')` | JOIN |
| `page(?int $page, bool $withTotalCount = false, int $pageSize = 10)` | 分页 |
| `limit(int $num, bool $withTotalCount = false)` | 数量限制 |
| `fields(?array $fields = null, bool $returnAsArray = false)` | 字段白名单与返回模式 |
| `returnAsArray()` | 开启原始数组模式 |
| `hideFields(array|string $fields)` | 隐藏字段 |
| `persistFieldLimit(bool $enabled = true)` | all() 子实体输出限制传播 |
| `getFields()` / `getHideFields()` / `isPersistFieldLimit()` | 读取限制 |
| `func(callable $func)` | 操作底层构建器 |
| `selectForUpdate()` | 添加行锁查询 |
| `returnEntity()` | 返回发起查询的实体 |
| `__getQueryBuilder()` | 获取底层 QueryBuilder |

除读取方法、返回实体和获取构建器外，以上方法返回 `Query` 支持链式调用。

### FastDb

| API | 用途 |
| --- | --- |
| `getInstance()` | 数据库管理单例 |
| `addDb(Config $config, ?string $name = null)` | 注册配置；返回管理器 |
| `getConfig(string $name = 'default')` | Config 或 false |
| `selectConnection(?string $name = null)` | 设置连接名时返回管理器；省略时返回名字 |
| `testDb(string $name = 'default')` | 检查连接，成功 true，失败异常 |
| `query(QueryBuilder|callable $query, ?float $timeout = null)` | 构建器查询；QueryResult |
| `rawQuery(string $sql)` | 原始 SQL；QueryResult |
| `begin()` / `commit()` / `rollback()` | 事务；bool |
| `isInTransaction(?Connection $connection = null)` | bool |
| `invoke(callable $call)` | 执行并归还连接；返回回调值 |
| `currentConnection()` | 当前 Connection 或 null |
| `recycleContext()` | 归还当前协程的全部连接 |
| `preConnect()` | 预热各连接池 |
| `reset()` | 重置全部连接池 |
| `setOnQuery(callable $call)` | 设置全局回调；返回管理器 |
| `isEnableQueryStack(bool $enabled)` | 开关记录；返回管理器 |
| `getQueryStack(?int $index = null)` | 整栈、单项或 null |

## 开发与测试

```bash
composer install
composer test:unit
```

完整测试需要独立测试数据库，会建表、TRUNCATE 和写入数据。凭据从环境变量读取，不写入项目配置：

```bash
export FAST_DB_TEST_HOST=127.0.0.1
export FAST_DB_TEST_PORT=3306
export FAST_DB_TEST_USER=test
export FAST_DB_TEST_DATABASE=test
read -s FAST_DB_TEST_PASSWORD
export FAST_DB_TEST_PASSWORD

composer test:integration
composer test
```

还可使用自定义 PHPUnit 选项：

```bash
php tests/run.php --filter ModelCommandTest --display-all-issues
php tests/run.php --list-tests
php tests/run.php --log-junit ./test-results.xml
```

`tests/run.php` 使用 PHPUnit 13 的 `TextUI\Application` 并在 Swoole 协程内执行测试，信息查询命令在协程外运行。`vendor/bin/phpunit --testsuite unit` 可直接执行单元测试；数据库测试请使用协程入口。

配置位于 `phpunit.xml.dist`，分为 `unit` 与 `integration`。数据库用例结束后回收上下文、重置池并清理测试定时器。更多说明见 [tests/README.md](tests/README.md)。

## 使用边界与常见问题

### 查询后不能反复复用同一套条件吗？

`all()`、成功执行的更新/删除、聚合和关联查询会重置查询配置；`toArray()` 清理 SQL 条件，但保留字段输出限制。没有变化而直接返回的 `update()` 不重置限制。建议每次数据库操作明确设置所需条件。

### 隐藏字段会不会改变数据库写入？

`insert()` 从 `toArray(true)` 构造数据，因此会受 `fields` / `hideFields` 影响。`update()` 的写入白名单是 `fields`，不会自动把 `hideFields` 当成排除写入规则。输出控制与写入规则应按实际操作分别设置。

### 查询少量字段后能直接把结果完整写回吗？

未加载字段仍可能有默认值/null，且对象比较基准不等于完整数据库记录。需要修改时只改已知字段，并使用 `fields()` 指定写入范围；需要完整状态时重新查询。

### 关联查询是否自动走其他数据库？

不会。关联和普通实体一样使用当前选中的单例连接。目标表名可以覆盖，连接需要由调用方选择。

### 原始写入为什么没有执行钩子/转换？

`fastUpdate()`、`fastDelete()`、`QueryBuilder` 与原始 SQL 是直接数据库操作。需要实体转换与钩子时通过实体 `insert()` / `update()` / `delete()`。

### 如何批量查询 UUID 主键？

用数组/回调显式构建 `IN`。逗号主键字符串简写会调用 `intval()`，适用于数字主键。

### 事务中能使用 invoke() 吗？

可以。`invoke()` 复用当前连接时不会归还外层连接，嵌套调用也不会提前结束外层事务。仍应由开启事务的作用域负责 `commit()` / `rollback()`；多次 `begin()` 不会创建 savepoint 或独立的嵌套事务。

### 目前没有哪些功能？

当前实体没有 migration/schema builder、save()、insertAll()、软删除、自动时间戳、复合主键映射、savepoint 嵌套事务、自动读写分离、自动关联预加载。需要这些能力时通过应用封装或底层 SQL 明确实现。

## 许可证

本项目采用 Apache License 2.0，详见 [LICENSE](LICENSE)。
