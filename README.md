# EasySwoole FastDb

FastDb 是面向 Swoole 协程环境的 MySQL 数据访问库，组合连接池、SQL 构建器和基于 PHP Attributes 的实体映射。可以在 EasySwoole 框架内使用，也可以独立使用。

本文面向 `3.x` 分支，依赖 `easyswoole/mysqli` ^5.0，以当前 `src/` 和 `composer.json` 为准。实体 API 位于 `AbstractEntity`，数据库与连接管理位于 `FastDb`，SQL 构建能力由 `easyswoole/mysqli` 提供。

## 内部执行与实体状态

`FastDb` 保留现有查询和事务入口，内部由以下组件分工：

| 组件 | 职责 |
| --- | --- |
| `Internal\ExecutionFrame` | 保存单次执行的结果、计时与完成回调状态；分页总数在外部回调前读取 |
| `Mysql\Connection` | 执行查询、读取底层协议事务状态、保存成功时点的结果快照 |
| `Internal\QueryDiagnostics` | 按需保存全局查询回调和每协程有界事件栈；分别记录查询执行与连接获取 |
| `AbstractInterface\AbstractEntity` | 字段比较基准、变更检测、主键保护和成功写入后的基准更新 |
| `Internal\FieldCodec` | 加载赋值转换、写入值转换；与序列化隐藏字段分别处理 |
| `Internal\FieldSelection` | 保存字段白名单、隐藏字段和输出限制，不混入 SQL 构建状态 |
| `Internal\RelationLoader` | 关联结果构造、唯一性检查及实体实例范围的关联缓存 |
| `Internal\RelationPreloader` | 显式预加载时逐父捕获关联计划、分批查询，并回填实体缓存或附加数组结果 |

`QueryResult` 保存查询执行结果及原有执行阶段耗时；连接获取独立记录为 `QueryStack` 中的 `connectionAcquire` 事件，使用同一个 `duration` 字段表示该事件耗时。查询栈默认每协程只保留最近 256 条事件，可按实例调整；未配置查询回调或查询栈时，不创建诊断组件。

FastDb 通过 `FastDb::getInstance()` 获取固定实例并长期复用。需要其他独立管理器时，由业务定义子类并按需初始化。

`Query` 仅在首次添加 SQL 条件或显式读取底层构造器时创建 `QueryBuilder`；只配置输出字段或序列化实体时无需分配 SQL 构造器。清理 SQL 条件仍生成新的 Query，外部持有的旧 Query 和构造器引用保持原有状态；克隆实体时查询所有者和可变配置继续独立。

加载后的实体不允许修改主键再执行 `update()` / `delete()`，以避免定位到其他记录。需要显式修改主键时应自行构造条件明确的 SQL。实体克隆后，比较状态和查询所有者独立。

实体 `update()` / `delete()` 和关联查询在最终构建 SQL 前，将全部已有 WHERE 条件整体分组，再以 AND 追加主键或关联外键条件。例如 `WHERE (status = ? OR score = ?) AND id = ?`，附加 OR 条件始终受实体范围约束。该分组也覆盖 `func()`、底层构建器和生命周期钩子添加的条件；`fastUpdate()` / `fastDelete()` 继续按调用方提供的条件批量执行。

上述约束要求 `easyswoole/mysqli` 提供 `QueryBuilder::groupExistingWhere()`。升级 FastDb 前，应先安装包含该接口的 mysqli 版本；驱动缺少接口时，实体写入及关联查询会明确抛出 `RuntimeError`，不会执行缺少分组保护的 SQL。

`Connection::isInTransaction(): bool` 直接读取底层协议的事务状态；`FastDb::isInTransaction()` 转发到当前或指定连接，不另外保存事务布尔副本。实体 `update()` 在数据库 SQL 成功后、公开查询回调前接受字段比较基准；返回值按本次 SQL 是否成功确定。后续事务回滚不会恢复模型属性或比较基准，需要由业务重新加载、重建对象或显式修改字段后再保存。

`all()` 持有本次查询的输出配置，回调重入查询不会丢失外层的隐藏字段及字段限制；回调对当前查询配置的显式修改仍可生效。成功 SQL 的查询元数据及分页总数在 FastDb/实体查询回调前保存，不会被回调中的嵌套查询覆盖；主查询与总数查询的公开回调仍按原有顺序通知。`close()` 成功后释放底层连接；事务状态同样由协议 getter 读取。提交超时等结果不明的场景仍需由业务核实数据库最终状态。

`insert()` 不再把 `hideFields()` 当作数据库写入过滤器；写入白名单仍使用 `fields()`。没有待更新字段时也会消费本次查询配置，不会把一次性的字段限制带到下次保存。

## 目录

- [特性与运行要求](#特性与运行要求)
- [安装与快速开始](#安装与快速开始)
- [3.x 迁移说明](#3x-迁移说明)
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
- [API 速查](#api-速查)
- [开发与测试](#开发与测试)
- [使用边界与常见问题](#使用边界与常见问题)

## 特性与运行要求

### 特性

- 每个数据库配置维护独立连接池，同一协程、同一连接名复用当前连接。
- 协程结束后自动归还连接，也可通过 `invoke()` 或 `recycleContext()` 提前归还。
- 连接归还时回滚未提交的数据库事务；回滚失败的连接由连接池丢弃。
- `#[Property]` 映射属性，支持单主键、默认值、nullable 属性和自定义转换。
- 查询结果可返回实体、原始数组或支持遍历/计数/数组访问的 `ListResult`。
- 条件、排序、连接、分组、分页、分块、聚合与 `FOR UPDATE`。
- 实体只更新已变化的字段；成功写入后更新比较基准，支持同一对象连续修改和还原。
- 数据库格式数组可通过 iterable 分批直接插入或 upsert，不逐行构造实体。
- 选库作用域退出时恢复连接名；回调事务按实际连接管理提交、回滚及同连接嵌套加入。
- 字段白名单、隐藏字段与可重复序列化的输出限制。
- 插入、更新、删除、初始化钩子，以及一对一/一对多关联与可选批量预加载。
- 全局/单次查询回调、SQL 与连接获取耗时统计，以及每协程容量可配置的查询栈。
- PHPUnit 13.4 测试入口，支持独立单元测试和真实数据库集成测试。

### 运行要求

| 场景 | 要求 |
| --- | --- |
| 运行库 | PHP ≥ 8.1、Swoole ≥ 5.1；其余扩展由 Composer 按依赖检查 |
| 数据库 | MySQL；示例表使用 InnoDB、utf8mb4 与 JSON 字段 |
| 开发测试 | PHP ≥ 8.4.1、PHPUnit ^13.4、原生 mysqli 扩展，以及 Composer 安装的开发依赖 |

数据库访问由 `easyswoole/mysqli` 5.x 的 Swoole 协程 Socket 协议客户端完成，生产环境不需要原生 mysqli 扩展，也不需要为数据库访问启用 runtime hooks。数据库操作应在 Swoole 协程内执行；`testDb()` 可在协程外调用，内部会创建协程调度器。原生 mysqli 仅用于开发测试中的独立观察连接。

## 安装与快速开始

安装当前 3.x 开发分支到应用：

```bash
composer require easyswoole/fast-db:3.x-dev
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

$db = FastDb::getInstance();
$db->addDb(new Config([
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'user' => getenv('DB_USER') ?: 'app',
    'password' => (string) getenv('DB_PASSWORD'),
    'database' => getenv('DB_DATABASE') ?: 'app_db',
    'charset' => 'utf8mb4',
    'timeout' => 3.0,
    'maxConnectTime' => 5.0,
    'compress' => false,
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


## 3.x 迁移说明

- 使用 `easyswoole/mysqli` ^5.0，生产安装不再要求 `ext-mysqli`；开发测试仍需要该扩展。
- 删除配置中的 `useMysqli`，统一使用协程协议客户端。
- 将 `maxConnectTim` 改为 `maxConnectTime`，setter 改用 `setMaxConnectTime()`；旧字段不再映射到连接超时。
- `begin()`、`commit()`、`rollback()` 参数顺序统一为 `(client, flags, timeout)`。flags 必须使用对应枚举，不能传整数或按位组合值。
- `query()`、`rawQuery()` 和事务方法支持单次超时，`null` 使用配置的 `timeout`。
- 原始查询中的整数列按驱动类型解析为 PHP 整数；不要依赖旧版数字字符串返回值。超过 `PHP_INT_MAX` 的无符号整数保留为十进制字符串。

## 数据库和连接池配置

`EasySwoole\FastDb\Config` 继承 `EasySwoole\Pool\Config`，支持通过构造数组和对应 setter 设置参数。

### 数据库参数

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `host` | string | `127.0.0.1` | 数据库地址 |
| `port` | int | `3306` | 数据库端口 |
| `user` | string | 空字符串 | 登录用户，按实际账号配置 |
| `password` | string | 空字符串 | 登录密码 |
| `database` | string | 空字符串 | 数据库名，按业务配置 |
| `charset` | string | `utf8mb4` | 连接字符集 |
| `timeout` | float | `3.0` | 默认单次查询、开始事务、提交或回滚的超时，秒 |
| `maxConnectTime` | float | `5.0` | 完整连接过程的超时上限，秒 |
| `compress` | bool | `false` | 请求 MySQL 协议压缩，需服务端支持 |
| `name` | string | `default` | 连接池名称 |
| `isForceRollback` | bool | `false` | 每次归还连接都尝试回滚；该配置不会因一次回收被清除 |
| `autoPing` | int | `5` | 保留字段，不控制当前驱动的自动 ping 周期；连接检查由 `beforeUse()` / `intervalCheck()` 执行 |

配置类型直接由属性声明处理，例如整数 `timeout => 3` 会保存为 float。`timeout` 和 `maxConnectTime` 在实际操作时必须大于零；类型声明本身不校验正数。使用真正的布尔值设置 `compress`。

`getObjectTimeout` 限制连接池等待，`maxConnectTime` 限制建连过程，方法参数 `$timeout` 限制该次 SQL 操作。FastDb 在获取连接后才执行查询，单次 SQL 超时不包含此前的连接池等待和池内新建连接耗时；它也不是整个业务事务的累计时间限制。

`Config` 不支持 `maxIdleTime`、`loadAverageTime`，连接池负载阈值使用 `waitLoadAverageTime`。数据库配置 setter 返回 void，应逐条调用。

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

连接在最近 3 秒内成功建连、执行查询或探活后，`beforeUse()` / `intervalCheck()` 跳过 `select 1`；空闲达到 3 秒时恢复探活。查询失败或连接关闭后清除活跃记录，已知断开的连接不会因近期活跃而跳过检查。

### 常用配置操作

```php
$config = new Config([
    'host' => '127.0.0.1',
    'user' => 'app',
    'password' => (string) getenv('DB_PASSWORD'),
    'database' => 'app_db',
]);
$config->setName('default');
$config->setTimeout(3.0);
$config->setMaxConnectTime(5.0);
$config->setCompress(false);
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

对象类型的默认值（枚举除外）会在每个实体初始化时通过 `clone` 复制，复制深度遵循该类型自身的 `__clone()`；PHP 默认只做浅复制。需要隔离嵌套可变对象时，应由对应类型实现克隆逻辑。例如，包含 Bean 实例的自定义 `ItemList`，应在 `ItemList::__clone()` 中复制需要独立的元素；Bean 内更深层的对象也由其自身负责。数组赋值同样不会自动复制其中的对象元素。

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

`chunk($callback, $chunkSize = 10)` 按 OFFSET 分页读取，每次回调接收一条记录，不是一个数组块。请提供稳定排序；遍历中删除记录或修改筛选条件可能改变后续页的偏移，不适合需要稳定游标的迁移任务。 `chunk()` 不主动请求总数统计，按本页条数判断是否继续；调用前显式开启的总数统计选项仍保留。

回调返回严格的 `false` 时立即停止，不再交付本批剩余记录或查询后续批次；没有返回值或返回其他值时继续。`chunk()` 本身不返回累计处理条数。

#### 按稳定键分块

需要逐条更新待处理状态、删除记录或扫描较多数据时，可使用键集分页：

```php
$entity = new User();
$entity->queryLimit()->where('status', 0);
$processed = $entity->chunkById(function (User $user): void {
    $user->status = 1;
    $user->update();
}, 100);

echo $processed; // 已交付给回调的记录数。
```

`chunkById(callable $func, int $chunkSize = 10, ?string $column = null): int` 默认使用实体主键，也可通过第三个参数指定稳定、唯一、非 NULL 的整数或字符串列。`$column` 必须是实体已声明为 `Property` 的原始列名，不能填写 SQL 表达式或别名。无主键实体需要显式指定符合条件的列。该入口要求 mysqli 提供 `QueryBuilder::forPageAfterId()`。

始终按该列 ASC 排序，从首批开始，再用“游标列 > 上一批最后的游标值”读取下一批；已有排序及页码偏移不决定遍历顺序和起点。每次回调接收一条实体；启用 `returnAsArray()` 时接收一条数组，与 `chunk()` 一致。

回调返回严格的 `false` 时立即停止，返回的计数包含触发停止的这一条；没有返回值或返回其他值时继续。每页通过 FastDb 的统一执行入口查询，沿用 `all()` 的实体转换和输出规则，保留查询条件及查询回调，不自动开启事务或统计总数。方法结束或抛异常后清理本次查询配置。

使用 `fields()` 时必须显式选中原始游标列，例如 `fields(['id', 'name', 'status'])`，不能只把游标列选为 `id AS cursor` 这样的别名；库不会为遍历悄悄扩大字段白名单。

`hideFields()` 可以隐藏游标列，方法会在公开查询回调前从原始结果校验并保存游标和页大小，再转换实体或隐藏输出字段。因此隐藏游标、修改回调对象或在 `onQuery` 中改写交付数据，都不会改变翻页位置及原始页的结束判断。返回实体后持续隐藏字段仍沿用 `persistFieldLimit()` 的规则。游标缺失、为 NULL 或重复等无效结果会在该批业务回调前抛出 `UnexpectedValueException`。不要在数据库中修改用于本次遍历的游标列。

查询结果必须具有唯一游标；使用 JOIN 时，也要保证连接结果中该列不重复。分组、HAVING、DISTINCT、UNION、子查询 FROM、行锁及显式总数统计不受支持。需要限制扫描范围时，可预先增加游标上界；方法本身不提供跨页的一致性快照。

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

### 批量直接写入

`fastInsertAll(iterable $rows, ?string $tableName = null, ?callable $onQuery = null, int $batchSize = 500): int|string` 把数据库格式的关联数组分批插入：

```php
$affected = User::fastInsertAll([
    ['name' => 'Alice', 'status' => 1, 'score' => 10],
    ['score' => 20, 'name' => 'Bob', 'status' => 1], // 字段顺序可以不同。
], batchSize: 500);
```

输入可以是数组、Generator 或其他 iterable。每行必须是非空的数据库字段关联数组，全部输入跨批次也必须使用相同的字段集合；只重排键顺序可以，缺字段或多字段会报错，不会自动补 null。每行按迭代读取时的标量值进入缓冲，后续修改行内标量引用不会改写已读取的行。JSON 等属性应先转成数据库可写的值；不会对每行应用实体转换器、`Property::assignCall` / `toValue` 回调或插入钩子，也不回填自增 ID 或实体比较基准。

#### 使用生成器控制批次内存

```php
function userRows(): \Generator
{
    for ($i = 1; $i <= 2000; $i++) {
        yield ['name' => 'User-' . $i, 'status' => 1, 'score' => $i];
    }
}

$affected = User::fastInsertAll(userRows(), tableName: 'users', batchSize: 500);
```

框架只缓冲当前批次，不会先把整个生成器转成数组，也不逐行创建模型。`batchSize` 按行数控制，默认 500；小于或等于 0 会抛出 `InvalidArgumentException`。参数有效的空输入返回 0，不构造模型、不执行 SQL。非空有效输入省略表名时，在首次写入前仅初始化一个模型来取得表名，这个模型自身的默认初始化与 `OnInitialize` 仍会执行；显式给出有效表名则不构造模型。

#### 主键或唯一键冲突时更新

`fastUpsert(iterable $rows, array $updateColumns, ?string $tableName = null, ?callable $onQuery = null, int $batchSize = 500): int|string` 使用同样的分批规则。冲突由数据库已有的主键或唯一索引决定，不会自行选择业务字段作为唯一键：

```php
$affected = User::fastUpsert([
    ['id' => 1, 'name' => 'Alice', 'score' => 10],
    ['id' => 2, 'name' => 'Bob', 'score' => 20],
], ['name', 'score'], batchSize: 500);

// 列表项使用本次输入的对应列；关联项使用固定值或 mysqli Expression。
$affected = User::fastUpsert([
    ['id' => 1, 'name' => 'Alice', 'score' => 10],
], [
    'name',
    'status' => 1,
    'score' => QueryBuilder::rawExpression('`users`.`score` + ?', [5]),
], tableName: 'users', batchSize: 500);
```

`$updateColumns` 沿用 mysqli 的 `upsert()` 规则；列表列名需存在于每条输入行中。空更新列会抛出 `InvalidArgumentException`，即使 `$rows` 为空也不会接受。固定值及表达式应用于发生冲突的行，插入新行仍使用输入值或数据库默认值。需要逐实体转换、钩子和主键回填时，继续使用实体 `insert($updateDuplicateCols)`。

返回值是各批数据库 `affectedRows` 的合计，不是输入行数或自增 ID 列表。upsert 的插入、实际更新和未改变值可能产生不同的影响行数，应按数据库语义理解；合计超出 PHP 整数范围时返回十进制字符串，保留精度。

#### 回调、连接与失败处理

每批使用 FastDb 统一执行入口，触发全局查询日志及传入的 `$onQuery`；该回调收到本批的 `QueryResult`，不是整次批量操作的累计结果。整次操作固定入口选中的连接；默认表名需要模型初始化时，以该初始化和表名求值后选中的连接为准。生成器或回调里切换选库的可见副作用仍保留，但不会让后续批次改写其他数据库。

方法不自动开启事务，也不自动重试。后续批次的字段校验、生成器、SQL 或回调发生异常时，前面的批次可能已经成功；查询成功后的回调异常也不代表该批 SQL 已回滚。需要整次原子性时，使用同一连接的显式事务，例如：

```php
$db = FastDb::getInstance();
$db->begin();
$connection = $db->currentConnection();
try {
    $affected = User::fastInsertAll(userRows(), tableName: 'users', batchSize: 500);
    $db->commit($connection);
} catch (\Throwable $error) {
    $db->rollback($connection);
    throw $error;
}
```

显式保存连接可以保证生成器或回调改变当前选库后，仍对实际写入连接提交或回滚；提交结果不明时仍需按事务章节说明核实。若调用前已有事务，应由该事务的拥有者决定提交或回滚。需要更多 SQL 构建选项时，仍可通过 `FastDb::query()` 使用底层 `QueryBuilder::insertAll()` / `upsert()`。

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

`fields($fields = null, $returnAsArray = false)` 同时控制查询列和返回模式。`returnAsArray()` 也可单独开启数组模式；未启用关联预加载时，它返回数据库行，不执行实体转换回调。显式使用 `withRelations()` 时，为读取关系方法中的父字段，会额外构造临时父模型，详见批量预加载章节；最终数组仍保留数据库原始值。

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

#### Bean 列表

通过子类的 `itemClass()` 指定元素类型，支持 `SplBean` 及其子类（包括 `ConvertBean`）：

```php
class UserBean extends ConvertBean
{
    public string $name = '';
}

class UserList extends ConvertList
{
    protected static function itemClass(): ?string
    {
        return UserBean::class;
    }
}

// 在实体内声明字段。
#[Property(convertObject: UserList::class)]
public ?UserList $users;

// JSON 字段读取或 setData() 时，数组元素自动转换为 UserBean。
$model->setData(['users' => '[{"name":"张三"}]']);
$model->users->first()->name = '李四';
$model->users->append(['name' => '王五']);
$model->users->append(new UserBean(['name' => '赵六']));
$model->update(); // Bean 的修改会参与字段变更检测，保存为 JSON 数组。
```

`toArray()` 返回 Bean 元素数组；`toValue()` 返回 JSON 数组字符串，元素不会被重复编码成 JSON 字符串。初始化和 `append()` 接收元素数组或指定类型的 Bean，已有 Bean 保留原实例，其他元素类型抛出 `InvalidArgumentException`；`append(null)` 仍忽略。类型化列表删除元素后，`toValue()` 会重新排列键，保持 JSON 数组格式。普通 `ConvertList` 的行为保持不变。

#### Bean 列表内嵌套 Bean

Bean 内部的属性可以继续转换为另一个 Bean。实体字段使用 FastDb 的 `#[Property]`，Bean 内部属性使用 SPL 的 `#[ConvertBean]`；下面将 SPL 注解起别名为 `NestedBean`，避免与 FastDb 的 `ConvertBean` 基类重名。

```php
use EasySwoole\FastDb\AbstractInterface\ConvertBean;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\Spl\Attribute\ConvertBean as NestedBean;

class UserAddress extends ConvertBean
{
    public string $city = '';
}

class MemberBean extends ConvertBean
{
    public string $name = '';

    #[NestedBean(UserAddress::class)]
    public ?UserAddress $address;
}

class MemberList extends ConvertList
{
    protected static function itemClass(): ?string
    {
        return MemberBean::class;
    }
}
```

在实体中声明字段（数据库需有对应的 JSON 或文本列）：

```php
#[Property(convertObject: MemberList::class)]
public ?MemberList $members;
```

赋值或从数据库读取时，每个元素会转换为 `MemberBean`，其中的 `address` 会继续转换为 `UserAddress`：

```php
$model->setData(['members' => [
    ['name' => '张三', 'address' => ['city' => '杭州']],
]]);
// 同样支持 JSON 字符串：
// $model->setData(['members' => '[{"name":"张三","address":{"city":"杭州"}}]']);

$member = $model->members->first();
$member->address->city = '上海';
$model->update(); // 嵌套 Bean 的修改也参与变更检测。

$model->members->append([
    'name' => '李四',
    'address' => ['city' => '北京'],
]);
$model->update(); // 追加元素时也会转换嵌套的 address。
```

保存后的字段内容为 JSON 数组，嵌套 Bean 保持 JSON 对象结构：

```json
[
    {"name": "张三", "address": {"city": "上海"}},
    {"name": "李四", "address": {"city": "北京"}}
]
```

实体的 `toArray()` 对该字段仍返回 JSON 字符串；`$model->members->toArray()` 返回 Bean 元素数组，`$member->toArray()` 可获得包含嵌套地址数组的数据。此示例的插入、数据库读取还原、嵌套属性修改及追加保存已通过测试数据库验证。

### ConvertDateTime

`ConvertDateTime` 继承 PHP `DateTime` 并实现 `ConvertObjectInterface`，用于 MySQL `DATETIME` / `DATETIME(fsp)` 字段。

```php
use EasySwoole\FastDb\AbstractInterface\ConvertDateTime;
use EasySwoole\FastDb\Attributes\Property;

#[Property(convertObject: ConvertDateTime::class)]
public ?ConvertDateTime $createdAt;
```

```php
$user->setData(['createdAt' => '2026-10-07 12:34:56']);
$user->createdAt?->format('Y-m-d');
$user->createdAt?->modify('+1 day');
$user->update(); // 写入 2026-10-08 12:34:56。

$user->setData(['createdAt' => new DateTimeImmutable('2026-10-09 12:00:00')]);
$user->update();

$user->setData(['createdAt' => null]);
$user->update(); // nullable 字段清空为 SQL NULL。
```

模型需要声明对应属性并增加数据库字段。`toObject()` 接收日期时间字符串、`DateTimeInterface`、整数 Unix 时间戳；直接传 null 表示当前时间。实体 nullable 属性的 null 由实体保留为 null，不调用转换器。已有日期对象会复制，保留其时区和微秒，不共享可变对象。

`toValue()` 和实体序列化输出 `Y-m-d H:i:s`；微秒非零时输出 `Y-m-d H:i:s.u`，数据库字段应设置相应精度以保留小数秒。MySQL `DATETIME` 不存储时区，转换器保留当前时区下的日期时间，不自动转为 UTC；读取数据库字符串使用 PHP 默认时区，应用应统一时区配置。空字符串、不支持的输入类型和标准 MySQL 格式中的无效日期（含零日期）会被拒绝；其他字符串采用 PHP `DateTime` 的解析规则。

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

关联默认按需执行独立查询。使用 `queryLimit()->withRelations()` 可以显式批量预加载；未声明预加载时，不会额外查询关联表。

### 定义一对一和一对多关联

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

关联条件为“目标表的 `targetProperty` = 当前实体的 `selfProperty` 值”。上例中，查询用户 `id = 1` 的关联时，会自动附加目标表的 `userId = 1` 条件。属性名称必须与数据库列名一致，FastDb 不会自动转换驼峰和下划线命名。

### 加载关联和分页

```php
$user = RelatedUser::findRecord(1);
if ($user !== null) {
    $profile = $user->profile(); // 目标实体|null。
    $orders = $user->orders(); // ListResult。

    // 查询条件作用于目标订单表，并与自动生成的 userId 条件共同生效。
    $user->queryLimit()
        ->where('status', 1)
        ->orderBy('id', 'DESC')
        ->page(1, true, 20);
    $activeOrders = $user->orders();
    $list = $activeOrders->list(); // 订单实体数组。
    $total = $activeOrders->totalCount(); // 满足条件的总数，不限于当前页。

    $user->queryLimit()->returnAsArray();
    $profileRow = $user->profile(); // 原始数组|null。
}
```

一对一未命中时返回 `null`；一对多未命中时返回包含空列表的 `ListResult`。不启用总数计算时，`totalCount()` 返回 `null`。一对一查询若匹配多条记录会抛出 `RuntimeError`，应让数据满足预期的一对一关系。

关联查询正常执行后会清理当前实体的查询配置。下一次关联查询需要重新设置过滤、排序、分页和字段限制。

### 批量预加载关联

列表中需要访问多个父实体的同类关联时，在父查询上声明关系方法名：

```php
$entity = new RelatedUser();
$entity->queryLimit()->where('status', 1)->orderBy('id', 'ASC')
    ->withRelations(['profile', 'orders']);
$users = $entity->all();

foreach ($users as $user) {
    $profile = $user->profile(); // 实体|null，复用本次预加载结果。
    $orders = $user->orders();   // ListResult，复用本次预加载结果。
}
```

`withRelations(string|array $relations)` 接受单个方法名或方法名列表，不接受闭包配置或点分嵌套路径。方法名会去重并规范为声明时的大小写；再次调用会覆盖本次声明，`withRelations([])` 清除声明。关系方法须为标注了 `#[Relate]` 的 public、非 static 方法，且不能有必填参数。未标注的普通业务方法不会被当作关系方法执行。

实体模式会把结果注入各父实体的关联缓存，之后相同查询形状的关系 getter 可直接命中，即使 `Relate::cacheResult` 为 false 也有效。一对一仍返回实体或 null，匹配多条仍抛出 `RuntimeError`；一对多仍返回 `ListResult`。关系方法自己配置 `fields(..., true)` 时，继续按原有规则返回数组或包含数组的 `ListResult`。父实体的 `toArray()` 不会自动把关系变成字段，需要输出时显式调用 getter。

#### 返回数组并附加关联

```php
$entity = new RelatedUser();
$entity->queryLimit()->fields(['id', 'name'], true)->hideFields('id')
    ->withRelations(['profile', 'orders']);
$rows = $entity->all()->list();

foreach ($rows as $row) {
    $profileRow = $row['profile']; // 原始行数组|null。
    $orderRows = $row['orders'];   // 原始行数组列表；没有命中时为 []，不是 ListResult。
}
```

附加键使用关系方法的声明名；如果父数组已经有同名键，会明确报错，不覆盖原字段。数组模式下父行和关联行保持数据库表示，例如 JSON 字段仍是 JSON 字符串，不会把转换对象放进返回数组。

无论返回实体还是数组，父关联键 `selfProperty` 必须以原列名被 SELECT 选中；不能只选择别名，也不会自动扩大父字段白名单。实体模式不能用 `hideFields()` 隐藏该键，因为后续 getter 需要真实父键；数组模式可以隐藏，库使用隐藏前的原始行完成关联匹配。关系方法读取的其他 `$this` 字段也应由父查询选中。目标关系的 `fields()` 不必选中 `targetProperty`：内部匹配辅助列会被移除，不进入关联输出。

数组结果启用预加载时，会为每一条父行临时构造父模型，以便关系方法读取 `$this` 字段。因此会执行父模型初始化、输入赋值和相应转换；临时模型不进入返回结果。这部分开销只发生在显式 `withRelations()` 路径，未启用预加载的数组查询仍不会逐行构造实体。

#### 方法内配置与查询数量

关系入口前设置的 WHERE、ORDER、字段列表，以及显式传给 `relateOne()` / `relateMany()` 的 `Relate` 和表名会按实际调用捕获。例如：

```php
class PreloadUser extends RelatedUser
{
    #[Relate(targetEntity: Order::class, targetProperty: 'userId', selfProperty: 'id')]
    public function activeOrders()
    {
        $this->queryLimit()->where('status', 1)->orderBy('id', 'DESC')
            ->fields(['id', 'status'], true);
        return $this->relateMany();
    }
}

$entity = new PreloadUser();
$entity->queryLimit()->withRelations('activeOrders');
$users = $entity->all()->list();
```

库对每个父实体调用关系方法，在进入 `relateOne()` / `relateMany()` 时截获计划，不执行逐父关联 SQL。不会只采样第一条父记录：关系方法依赖父字段设置不同条件、目标表或连接时，会按实际计划分组。目标模型仍按普通关联规则初始化，默认 `tableName()` 或显式覆盖表名在该父对象的关联入口确定，连接名在目标初始化和表名求值后记录；支持依赖当时上下文的动态分表、分库，不会按类名跨父对象复用表名。相同连接、目标实体、关联类型、表名、条件参数、排序、字段和返回形式的计划可以合并批查。

常见固定关系、每种关系不超过 250 个唯一父关联键时，通常是 1 条父查询加每种关系 1 条批查询；例如 100 个父实体同时预加载 profile 和 orders，通常为 3 条 SQL。更大的结果按每个兼容计划、每 250 个去重父键分批；相同计划还可合并。若动态条件让每个父对象都产生不同计划，关联查询仍可能达到 N 条，不保证任意关系都固定为一条批 SQL。父分页的额外总数查询、业务方法或钩子自己执行的 SQL 另计；空父列表不执行关联 SQL。

方法应配置查询后直接返回关联结果，且不能捕获并吞掉关联入口异常。预加载取得的是 `relateOne()` / `relateMany()` 的原始关系结果；关系入口后的自定义结果转换不用于组装数组附加值。实体之后显式调用 getter 时，wrapper 自身的后处理仍按原方法执行。

首版不支持关系方法内的 limit/page、GROUP BY、HAVING、DISTINCT、UNION、子查询 FROM、行锁或总数统计；这些形状会明确报错，不能把全部父对象共用的 LIMIT 当作每个父对象的限制。父列表本身可以分页。`all()` 预加载当前列表，`chunk()` / `chunkById()` 只预加载当前批次，在逐条业务回调前完成。

预加载声明只作用于本次查询，执行后会被消费；查询模板或实体克隆会保留尚未消费的声明，并独立复制查询配置。实体缓存按连接、父键、实际 SQL、参数和返回模式匹配，更改条件、父键、目标表或实体/数组模式不会错误命中旧结果。`clearRelationCache()` 同时清除预加载结果和普通关联缓存，之后 getter 恢复查询；数组附加值已经是普通数组，不受该方法影响。`cacheResult: false` 只关闭按需查询缓存，不会禁用显式预加载的结果。缓存不会自动感知外部写入或事务结果，长生命周期实体应按需清理。

`AbstractEntity::__setRelationData(RelationPlan $plan, QueryBuilder $lookup, string $connectionName, mixed $data): static` 标记为 `@internal`，由预加载器注入与查询形状绑定的结果，不是应用层赋值入口；应用通过 `withRelations()` 使用预加载，无需直接构造这些参数或调用该方法。

### 字段选择与隐藏字段

只需要部分列时，在父实体上设置目标查询的字段列表。下面直接返回数组，避免未选择的字段在实体序列化时以默认值或 null 出现：

```php
$user = RelatedUser::findRecord(1);
if ($user !== null) {
    $user->queryLimit()->fields(['id', 'userId', 'status'], true);
    $rows = $user->orders()->list(); // 每项只有所选列。
}
```

当前关联查询不会把父实体上的 `hideFields()`、`persistFieldLimit()` 自动传给关联结果。需要隐藏字段时，应在返回的实体上设置：

```php
$user = RelatedUser::findRecord(1);
if ($user !== null) {
    $profile = $user->profile();
    if ($profile !== null) {
        $profile->queryLimit()->hideFields('privateNote');
    }

    $orders = $user->orders();
    foreach ($orders->list() as $order) {
        $order->queryLimit()->hideFields('internalRemark');
    }

    $json = json_encode([
        'profile' => $profile,
        'orders' => $orders->list(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
```

示例中的 `privateNote`、`internalRemark` 代表业务模型中需要隐藏的字段。返回数组时请使用 `fields(..., true)` 明确选择列，或自行移除字段。

### 修改关联实体

关联返回的实体会执行字段转换，并把加载数据作为更新比较基准。未修改时调用 `update()` 不发送 SQL；修改后只更新发生变化的字段：

```php
$user = RelatedUser::findRecord(1);
if ($user !== null) {
    $profile = $user->profile();
    if ($profile !== null) {
        $profile->setData(['nickname' => 'Alice'])->update();
    }
}
```

`nickname` 需要在目标模型中声明为 `Property`。需要更新关联实体时，应保留目标实体的主键列；数组结果不能直接调用实体的 `update()`。修改父实体不会自动保存或删除关联数据，应分别操作各个实体。

### 缓存关联查询结果

在 `Relate` 上设置 `cacheResult: true`，可以缓存当前实体实例的关联结果，重复执行相同关联查询时不再发送 SQL。默认值为 `false`。

```php
#[Relate(targetEntity: Profile::class, targetProperty: 'userId', cacheResult: true)]
public function profile()
{
    return $this->relateOne();
}

#[Relate(targetEntity: Order::class, targetProperty: 'userId', cacheResult: true)]
public function orders()
{
    return $this->relateMany();
}
```

```php
$user = RelatedUser::findRecord(1);
if ($user !== null) {
    $first = $user->orders(); // 首次查询数据库。
    $second = $user->orders(); // 复用同一个 ListResult。

    $user->clearRelationCache(); // 清空此实体的全部关联缓存。
    $fresh = $user->orders(); // 重新查询数据库。
}
```

缓存按当前连接名称、目标实体、关联类型、生成的 SQL、绑定参数及返回形式区分。改变关联字段值、过滤条件、排序、分页、选择列、目标表或实体/数组返回形式时，会使用不同缓存项。分页总数也随列表缓存；需要重复命中分页查询时，每次设置相同的分页和排序条件。

`null` 和空列表同样会缓存；查询异常及一对一匹配多条的异常不会缓存。命中缓存时仍清理本次查询配置，但不会触发查询日志回调。不同父实体实例不共享缓存。

返回的关联实体和 `ListResult` 复用同一对象，修改对象也会影响后续缓存返回值。缓存不会自动感知其他查询、关联写入、事务提交或回滚导致的数据变化；需要最新数据时调用 `clearRelationCache()`。不使用显式预加载时，也可保持 `cacheResult: false` 关闭按需查询缓存。长生命周期实体应按需清理缓存。

### 显式指定关联字段和目标表

`selfProperty` 省略时使用当前实体主键；`targetProperty` 和显式 `selfProperty` 必须是已映射属性。也可以显式传入 `Relate` 对象和目标表名：`relateOne($relate, $tableName)` / `relateMany($relate, $tableName)`，这两个方法是 protected，需要从实体方法调用。

```php
class RelatedUser extends User
{
    #[Relate(targetEntity: Order::class, targetProperty: 'userId', selfProperty: 'id')]
    public function archivedOrders()
    {
        return $this->relateMany(
            new Relate(
                targetEntity: Order::class,
                targetProperty: 'userId',
                selfProperty: 'id'
            ),
            tableName: 'archived_orders'
        );
    }
}
```

覆盖表名只改变查询目标表，不会切换数据库连接；目标表结构需要与目标实体字段匹配。

遍历大量父实体逐一加载关联会产生多次 SQL；可使用上述 `withRelations()` 批量预加载，复杂的自定义汇总仍可使用底层查询。

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
| `begin($client = null, $flags = TransactionStartFlags::None, $timeout = null)` | 默认获取当前连接并开始事务；协议 getter 已报告事务活动时直接返回 true |
| `commit($client = null, $flags = TransactionCompletionFlags::NoChainNoRelease, $timeout = null)` | 默认提交当前连接；没有连接/没有事务时返回 true |
| `rollback($client = null, $flags = TransactionCompletionFlags::NoChainNoRelease, $timeout = null)` | 默认回滚当前连接；没有连接/没有事务时返回 true |
| `isInTransaction($connection = null)` | 转发到当前或指定 Connection 的协议状态 getter |

上表描述手动事务入口；下面的 `transaction()` 只根据入口连接是否已有事务，决定本层是否负责开始、提交和异常回滚。

### 指定连接、flags 和超时

导入对应枚举：

```php
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
```

| 参数 | 类型 | 含义 |
| --- | --- | --- |
| `$client` | `?Connection` | 指定操作连接；null 使用当前选中连接，begin 会按需获取连接 |
| `$flags` | 对应事务枚举 | begin 使用 `TransactionStartFlags`；commit/rollback 使用 `TransactionCompletionFlags` |
| `$timeout` | `?float` | 单次事务 SQL 的超时秒数，必须大于零；null 使用配置 timeout |

```php
$db->invoke(function (Connection $client) use ($db): void {
    $db->begin($client, TransactionStartFlags::ReadWrite, 1.0);
    try {
        // 使用该连接执行本次业务 SQL。
        $db->rawQuery('UPDATE users SET score = score + 1 WHERE id = 1', 0.5);
    } catch (Throwable $error) {
        $db->rollback($client, TransactionCompletionFlags::NoChainNoRelease, 1.0);
        throw $error;
    }
    $db->commit($client, TransactionCompletionFlags::NoChainNoRelease, 0.5);
});

// 命名参数：在当前连接上以默认结束模式提交，最长等待 0.5 秒。
$db->commit(timeout: 0.5);
```

flags 不接受整数、枚举的 `value` 或错误的枚举类型；这些输入会在执行前抛出 `TypeError`。多次 begin 不增加嵌套层级，也不会建立 savepoint。

### 开始事务的枚举

以下 SQL 由 `TransactionStartFlags::toSql()` 生成：

| 枚举值 | SQL |
| --- | --- |
| `None` | `START TRANSACTION` |
| `ConsistentSnapshot` | `START TRANSACTION WITH CONSISTENT SNAPSHOT` |
| `ReadWrite` | `START TRANSACTION READ WRITE` |
| `ConsistentSnapshotReadWrite` | `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ WRITE` |
| `ReadOnly` | `START TRANSACTION READ ONLY` |
| `ConsistentSnapshotReadOnly` | `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` |

一致性快照行为取决于服务端隔离级别。ReadOnly 禁止修改非临时表。

### 提交和回滚的枚举

下表后缀由 `TransactionCompletionFlags::toSqlSuffix()` 生成，接在 `COMMIT` 或 `ROLLBACK` 后：

| 枚举值 | SQL 后缀 | 行为 |
| --- | --- | --- |
| `None` | 无 | 使用服务端 completion_type 默认行为 |
| `Chain` | `AND CHAIN` | 结束当前事务并开始新事务 |
| `NoChain` | `AND NO CHAIN` | 不开始新事务；连接释放行为取决于服务端默认值 |
| `Release` | `RELEASE` | 结束事务并关闭连接 |
| `NoRelease` | `NO RELEASE` | 保留连接；是否开始新事务取决于服务端默认值 |
| `NoChainRelease` | `AND NO CHAIN RELEASE` | 不开始新事务，关闭连接 |
| `ChainNoRelease` | `AND CHAIN NO RELEASE` | 开始新事务并保留连接 |
| `NoChainNoRelease` | `AND NO CHAIN NO RELEASE` | 不开始新事务，保留连接；FastDb 默认值 |

使用默认 `NoChainNoRelease` 可明确结束事务并保留池内连接。Chain / ChainNoRelease 会开始新事务，Release / NoChainRelease 会关闭连接；实际事务状态由 Connection 直接读取底层协议。需要明确的结束行为时，应显式选择对应 flags。

FastDb 查询和事务执行超时抛出 `EasySwoole\FastDb\Exception\TimeoutException`，驱动会关闭失步连接。提交超时可能发生在服务端已提交、客户端尚未收到响应时，结果需要结合业务记录核实，不能直接重试提交。事务日志和查询栈中的 SQL 包含 flags 对应的完整语句，例如 `START TRANSACTION READ ONLY`、`COMMIT AND NO CHAIN NO RELEASE`。

`queryLimit()->selectForUpdate()` 或 `all(true)` 也可生成锁定查询。锁应配合事务使用，并保持查询、更新、提交在同一协程和同一连接名内。

连接被回收时，未提交事务会被回滚。`invoke()` 只归还本次调用新获取的连接；复用已有连接或嵌套调用时，连接继续由外层作用域管理。

事务内成功执行 `update()` 后，模型立即接受本次写入字段的比较基准。之后提交、回滚、连接回收或服务端回滚，都不会自动修改模型当前属性、比较基准或已经返回的更新结果。回滚后直接对同一个未修改对象再次 `update()`，可能没有变化可写；应按业务需要重新查询、重建对象，或显式修改字段。

Connection 直接读取底层协议，不维护额外的本地事务标记，也不保存模型事务快照、归属代次或回滚标记。同一 FastDb 实例在每个协程内只登记一次连接退出清理；归还连接时清理未结束的数据库会话，避免下一次借用继承事务。此资源清理不负责恢复业务对象。

死锁、断线等对事务状态的影响以底层协议为准；异常继续向调用方抛出，不自动重试。普通 SQL 错误也不会自动撤销模型状态；需要回滚和重试哪些操作，由业务决定。

事务操作成功后，底层协议已读取服务端回复，再执行日志回调；回调中的事务 getter 读取当时的协议状态。日志回调异常仍向调用方传播；尤其是 `commit()` 的日志回调抛异常时，数据库已经提交，调用方不能据此认定提交失败或直接重试。

### 回调事务 transaction

`transaction(callable $callback, TransactionStartFlags $flags = TransactionStartFlags::None, ?float $timeout = null): mixed` 在入口选中的实际连接上管理回调事务。回调接收当前 `FastDb` 实例，也可使用无参数闭包；正常完成时返回回调的原始返回值，包括 false、null 或对象。

```php
$db = FastDb::getInstance();
$updated = $db->transaction(function (FastDb $db): bool {
    $user = User::findRecord(1, selectForUpdate: true);
    if ($user === null) {
        throw new RuntimeException('User not found');
    }
    $user->score += 10;
    if (!$user->update()) {
        throw new RuntimeException('Update failed');
    }
    return true;
}, flags: TransactionStartFlags::ReadWrite, timeout: 1.0);
```

入口连接没有事务时，本层先开始事务，回调正常返回后提交；开始或回调抛异常时，尝试回滚入口的同一个连接，再抛出原异常。开始失败不会执行回调。返回严格的 `false` 也是正常返回，会提交；需要让拥有层回滚时应抛出异常。

#### 同连接嵌套与已有手动事务

入口连接已经有事务时，本层只执行回调，不发送 BEGIN、COMMIT 或 ROLLBACK。此时传入的 flags 和 timeout 不会重新配置已有事务，也不会因使用非默认选项而拒绝加入；timeout 仍须满足有限正数的基本参数要求。

同连接的内层异常被外层业务捕获后，外层可以正常提交，包括内层在异常前已经执行的写入。内层异常若继续逃出最外层拥有者，才由该拥有者回滚：

```php
$db->transaction(function (FastDb $db): void {
    try {
        $db->transaction(function (FastDb $db): void {
            // 与外层使用同一个数据库事务。
            throw new RuntimeException('Inner operation failed');
        });
    } catch (RuntimeException $error) {
        // 已捕获异常；外层正常返回时仍会提交。
    }
});
```

事务原本由手工 `begin()` 开启时，`transaction()` 同样只加入并执行回调。无论回调返回还是抛异常，都由手工事务的调用方决定何时提交或回滚；框架不额外标记该事务。

#### 连接选择与 A → B → A

拥有层固定入口的实际 Connection，结束时始终操作该连接；回调改变选库，不会让 helper 提交其他连接。方法本身不恢复选库，也不会把回调中的全部 SQL 强制路由到入口连接。需要退出时恢复连接名，可配合 `withConnection()`。

A → B → A 时，A、B 各自尚无事务的外层分别开始一个事务，重新进入 A 的内层只加入 A：

- 内层 A 的异常被业务捕获后，A、B 都可以继续正常提交；没有自动的局部回滚。
- 异常逐层逃出时，各个拥有层分别回滚自己的连接。
- B 已经提交后，A 随后回滚不会撤销 B；两个连接没有分布式事务协调。

#### flags、超时和提交失败

拥有层使用 flags 开始事务，timeout 分别传给本层实际发送的 BEGIN、COMMIT、ROLLBACK。每条控制 SQL 都有独立的单次预算；这不是 PHP 回调或整个事务的累计期限，业务 SQL 仍使用各自查询参数或连接配置。连接池等待也不包含在控制 SQL 超时内。

开始或业务回调抛出的异常优先于回滚清理异常，回滚失败不会替换原异常。提交发生在业务回调正常返回之后：提交返回 false 会抛出 `RuntimeError`，提交本身或其日志回调抛出的异常直接向外传播，不自动重试，也不自动追加 ROLLBACK。尤其 COMMIT 已成功后的日志异常不能视为数据库未提交；提交超时、断线等结果不明时，由业务核实最终状态并处理。

helper 不解析 SQL 白名单，不管理 savepoint，不跟踪事务代次，也不阻止回调手工开始、提交、回滚或执行会隐式提交的 SQL。这些操作对事务边界及业务原子性的影响由调用方负责。

### 与 invoke 的区别

| 入口 | 回调参数 | 自动管理的范围 |
| --- | --- | --- |
| `withConnection()` | FastDb | 临时连接名，退出恢复；不借还连接，不开始或结束事务 |
| `transaction()` | FastDb | 入口实际连接的事务；同连接嵌套加入，不恢复回调选库 |
| `invoke()` | Connection | 仅归还本层自己借出的连接；不自动开始事务 |

`invoke()` 原有行为不变，也不因为增加了两个作用域入口而改变嵌套借还规则。

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

### 作用域选库 withConnection

`withConnection(string $name, callable $callback): mixed` 临时选择连接名，并在 try/finally 中恢复进入前的名字：正常返回、回调抛异常、内部又切换其他连接时都恢复。回调接收该 `FastDb` 实例，也可使用无参闭包，返回值原样返回，包括 false 和 null。

```php
$db = FastDb::getInstance();
$rows = $db->withConnection('reporting', function (FastDb $db): array {
    return User::findAll(['status' => 1], returnAsArray: true);
});
// 回到调用前的连接名。

$db->withConnection('primary', function (FastDb $db): void {
    $db->withConnection('reporting', function (FastDb $db): void {
        // 当前为 reporting。
    });
    // 内层退出后回到 primary。
}); // 外层退出后回到最初的连接名。
```

`$name` 必须有已注册的配置，或当前协程已持有该名称的连接；完全未知的名字会在执行回调前被拒绝。空字符串 `''` 和 `'0'` 不是合法作用域连接名。配置存在不代表已连接或数据库一定可用；withConnection 本身不进行连接探测。

该方法自己不借连接、不回收连接、不创建事务，也不提交或回滚已有事务。回调里的查询会按需使用现有连接池流程；只改变选库、不执行 SQL 的回调不会因此创建数据库连接。如果回调手工开始事务，退出选库作用域不会自动结束它；需要回调事务管理时显式调用 `transaction()`。

两个作用域可以组合，例如临时选择 primary，再执行其回调事务：

```php
$value = $db->withConnection('primary', function (FastDb $db): mixed {
    return $db->transaction(function (FastDb $db): mixed {
        return User::fastUpdate(1, ['status' => 1]);
    });
});
```

新事务的拥有层正常返回时提交，加入已有事务的层不提前提交；返回 false 不表示回滚。选库作用域在事务成功或失败后都恢复原名字。其他协程的连接选择不受影响。

### 提前归还

```php
$rows = $db->invoke(function (Connection $connection): array {
    $query = new QueryBuilder();
    $query->get('users', 10, ['id', 'name']);
    return $connection->query($query);
});
```

`invoke()` 返回回调返回值，finally 中归还由本次调用获取的连接。直接调用 `$connection->query()` / `rawQuery()` 不会经过 `FastDb` 的日志包装；需要全局日志时在回调中调用 `$db->query()` / `rawQuery()`。不要保存回调中的连接给后续协程使用。

```php
$db->currentConnection(); // 当前选中连接的 Connection|null，不主动创建连接。
$db->recycleContext(); // 归还当前协程的全部连接，未结束会话由连接池清理。
$db->reset(); // 重置连接池和定时器，用于生命周期管理。
```

`recycleContext(): void` 直接归还当前协程持有的全部连接，并清理其查询栈。未结束的数据库事务由连接池归还流程回滚，回滚失败的连接会被淘汰；模型属性及比较基准不因此改变。

`reset()` 重置全部连接池和定时器，用于关闭、测试或重新初始化阶段。调用前应先归还上下文连接，并避免其他协程仍在使用连接池。

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
$result = FastDb::getInstance()->query($query, timeout: 1.0);
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
$result = FastDb::getInstance()->rawQuery('SELECT CURRENT_TIMESTAMP AS now', timeout: 1.0);
$row = $result->getResultOne();
```

`rawQuery(string $sql, ?float $timeout = null)` 不支持单独的绑定参数。动态数据使用 `QueryBuilder::raw($sql, $params)`，不要拼接输入数据。

通过 `currentConnection()` 或 `invoke()` 取得的高级连接接口不提供连接级监听，调用 `Connection::onQuery()` 会抛出 `LogicException`。直接使用 Connection 执行 SQL 时，日志由业务代码自行记录；FastDb 的全局 `setOnQuery()` 及实体/单次查询回调继续支持。

### QueryResult

| 方法 | 返回内容 |
| --- | --- |
| `getResult()` | SELECT 等返回行数组，写入/DDL 通常返回 bool；查询失败回调中为 null |
| `getResultOne()` | 数组结果的第一行，否则 null |
| `getException(): ?Throwable` | 执行失败时的异常对象；成功或未设置时为 null |
| `getConnectionName()` | 本次查询的执行连接名快照（string），供慢日志和异常定位使用；执行前必须设置 |
| `getAffectedRows()` | 本次成功查询的影响行数快照；未取得结果或查询失败时为 null |
| `getInsertId()` | 本次成功查询的自增 ID 快照；未取得结果或查询失败时为 null |
| `getTotalCount(): ?int` | 请求分页总数后的统计结果；未读取总数时为 null |
| `getTotalResult(): ?self` | 总数查询的 QueryResult；未读取总数时为 null |
| `getQueryBuilder()` | 构建器查询的副本；原始 SQL 查询时 null |
| `getRawSql()` | 原始 SQL 文本；构建器查询时通常 null |
| `getDuration(): float` | 查询执行阶段耗时，秒，保持原有计时含义；不含获取连接及日志回调，实体失败替代结果见下文 |
| `getStartTime()` / `getEndTime()` | Unix 时间戳，用于日志定位；查询执行耗时使用 getDuration() |

写入结果的影响行数和 insert id 从 QueryResult 快照读取：

```php
$affected = $result->getAffectedRows();
$insertId = $result->getInsertId();
```

连接名在执行 SQL 前从实际连接记录，查询失败时也保留。查询栈统一读取该快照，不会因为构建器回调或日志回调切换连接而记错数据库。模型查询失败时的实体回调同样保留发起查询时的连接名。

影响行数和自增 ID 类型为 `int|string|null`，超大无符号值保留为字符串，未查询或查询失败时为 null。影响行数与自增 ID 在查询成功后、日志回调执行前记录为快照，不会被后续 SQL 覆盖。QueryResult 不再暴露 Connection。`query()` 和 `rawQuery()` 的可选 `$timeout` 已传递给 mysqli 5.x，控制本次查询超时。

分页总数及对应查询结果随主查询的 QueryResult 一起保存，可直接读取 `$result->getTotalCount()` 和 `$result->getTotalResult()`。FastDb 的 `getTotalCount($result)` / `getTotalResult($result)` 仅转发到结果对象，不另外保存总数映射。

`getDuration()` 保留原有查询执行阶段的计时含义，从取得连接后开始，不把获取连接或日志回调算进去。`query(callable)` 的工厂在该执行阶段内运行，因此也包含工厂构建耗时，不能把它等同于纯服务端 SQL 耗时。

连接获取耗时通过查询栈的独立 `connectionAcquire` 事件读取，不分摊给某条 SQL 或 QueryResult。计时覆盖连接池 `getObj()` 阶段，包含排队、按需新建连接、健康检查及重试；它不是纯粹的队列等待时间。业务端到端耗时还包含进入查询执行阶段前的构建和业务代码等阶段，需要另行计时。

### 超时异常定位

`FastDb::query()`、`rawQuery()` 及 `begin()` / `commit()` / `rollback()` 的执行超时抛出 `EasySwoole\FastDb\Exception\TimeoutException`。SQL 信息在创建异常后赋值给 public 属性 `rawSql` 和 `queryBuilder`，也可通过 getter 读取；构造函数仅接收标准的 message、code、previous。message 保持底层错误消息，不拼接 SQL 或参数。

| 方法 | 返回内容 |
| --- | --- |
| `getRawSql(): ?string` | 对应 SQL；预处理查询保留占位符，事务 SQL 包含 flags |
| `getQueryBuilder(): ?QueryBuilder` | 构建器查询的独立快照，可读取 SQL 和绑定参数；原始 SQL / 事务操作为 null |
| `getPrevious()` | 原始 mysqli 超时异常；错误码和 message 保持一致 |

```php
use EasySwoole\FastDb\Exception\TimeoutException;

$query = new QueryBuilder();
$query->raw('SELECT SLEEP(?)', [1]);
try {
    $db->query($query, timeout: 0.05);
} catch (TimeoutException $error) {
    $sql = $error->getRawSql(); // SELECT SLEEP(?)
    $builder = $error->getQueryBuilder();
    $params = $builder?->getLastBindParams() ?? []; // [1]
    $message = $error->getMessage(); // 原始超时消息。
    $cause = $error->getPrevious();
    // 将 message、sql、params 按需交给日志系统。
    throw $error;
}
```

原始查询及事务超时可直接使用 `getRawSql()` 定位，例如 `COMMIT AND NO CHAIN NO RELEASE`。构建器在异常创建时复制，之后重用原构建器不会改变异常中保存的 SQL 和参数。

FastDb 的超时异常继承 `EasySwoole\FastDb\Exception\Exception`。调用方可捕获 `EasySwoole\FastDb\Exception\TimeoutException`，或 FastDb 通用 `Exception`；它不继承 mysqli 异常，原始 mysqli 超时实例保存在 `getPrevious()` 中。

这里覆盖 FastDb Connection 的 query/rawQuery 执行路径；获取连接前的连接池等待或建连失败、直接使用依赖 Client/prepare 的异常遵循各自入口。记录绑定参数时应按业务需要保护敏感数据。提交超时的结果需要核实，不能据此直接认定回滚。

## 查询日志与查询栈

### 全局回调

```php
$db->setOnQuery(function (QueryResult $result): void {
    $durationMs = $result->getDuration() * 1000;
    $builder = $result->getQueryBuilder();
    $sql = $result->getRawSql() ?? $builder?->getLastPrepareQuery();
    $params = $builder?->getLastBindParams() ?? [];
    $exception = $result->getException();
    $errorMessage = $exception?->getMessage();
    $errorCode = $exception?->getCode();
    // 写入自己的日志系统：sql、params、durationMs、errorMessage、errorCode。
});
```

查询执行阶段的开始时间在获得连接之后记录，执行耗时不包含获取连接；获取阶段另记为独立查询栈事件。`query()` / `rawQuery()` 结束时间在执行结束或失败后、调用日志回调之前记录，不包含日志回调耗时；`query(callable)` 的工厂构建仍包含在原有执行计时内。begin/commit/rollback 实际执行后，无论成功、SQL 错误或超时，都进入日志回调和查询栈；直接返回且未执行 SQL 时不触发。事务 rawSql 与 flags 生成的执行 SQL 一致。全局 `onQuery` 只接收查询执行结果，连接获取成功或失败均不调用它。

`query()` / `rawQuery()` 和事务执行抛异常时，回调中的 `getException()` 返回该次执行抛出的同一异常对象，`getResult()` / `getResultOne()` 为 null。成功执行时 getException() 为 null。执行已失败时，日志回调另抛异常不会覆盖原始执行异常；执行成功时，回调异常正常抛出，不能据此认定数据库操作失败。执行异常在回调后继续向调用方抛出。

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

`findAll()`、`fastUpdate()`、`fastDelete()` 也有 `$onQuery` 参数。全局回调与实体/单次回调可以同时执行。实体查询在获得结果前失败时会创建替代 `QueryResult`，该结果提供时间、SQL 信息、执行异常及空结果，不保证拥有可用连接。

实体借用连接失败时，这份替代结果保留原有兼容计时，`getDuration()` 仍可能包含失败调用区间；此时不能把它当作实际 SQL 执行窗口。开启查询栈后的独立 `connectionAcquire` 失败事件能更准确地区分获取耗时和异常。

### 查询栈

```php
$db->isEnableQueryStack(true, maxStackSize: 2);
$db->rawQuery('SELECT 11'); // 首次借连接时先记 connectionAcquire，再记 query，共占两条。
$db->rawQuery('SELECT 22');
$db->rawQuery('SELECT 33');

$stack = $db->getQueryStack(); // 当前协程记录数组，未记录时 null。
$first = $db->getQueryStack(0); // SELECT 22，SELECT 11 已淘汰。
$last = $db->getQueryStack(-1); // SELECT 33。
$previous = $db->getQueryStack(-2); // SELECT 22。
$missing = $db->getQueryStack(100); // null。
$db->isEnableQueryStack(false, maxStackSize: 2); // 停止追加，保持相同容量下的已有记录。
```

`isEnableQueryStack(bool $bool, int $maxStackSize = 256): static` 的容量必须是正整数，配置属于该 FastDb 实例；每个协程分别保留最近 N 条事件，包含 `query` 和 `connectionAcquire`，达到容量时淘汰最旧项。返回数组始终按保留记录的先后顺序排列；正索引 0 表示当前保留的最旧项，-1 表示最新项，越界返回 null，原有索引规则不变。首次查询若需要借连接会产生两条事件，复用连接的后续查询只有 `query` 事件。

每次启停调用都会应用 `maxStackSize`，缩小容量会立即裁剪该实例所有已有协程栈，包括关闭时。关闭只停止追加，不主动清空，但 `isEnableQueryStack(false)` 也会应用默认容量 256：此前容量大于 256 时，已有记录会缩到最近 256 条。需要保留更大容量下的现有记录，可显式传原容量，例如 `isEnableQueryStack(false, maxStackSize: 1024)`。协程退出或回收上下文时清理其记录。

每项 `QueryStack` 的字段为：

| 字段 | 含义 |
| --- | --- |
| `type` | 普通 SQL 为 `query`，独立连接获取事件为 `connectionAcquire` |
| `connectionName` | 本次操作的连接名快照，失败时也可定位尝试借用的连接 |
| `query` / `rawQuery` | SQL 构造器快照或原始 SQL；连接获取事件两者均为 null |
| `duration` | 当前事件耗时，秒；query 为原查询执行阶段（含 query(callable) 工厂），connectionAcquire 为本次 getObj() 的获取总耗时 |
| `exception` | SQL 执行异常，或连接获取事件中实际向外抛出的借用异常；成功时为 null |
| `startTime` / `endTime` | 记录的起止 Unix 时间戳，用于日志定位；耗时字段使用单调时钟 |

普通 SQL 记录的异常来自该 SQL 执行结果，不把 SQL 成功后日志回调再抛出的错误改记为 SQL 失败。获取连接失败时，栈中的 `exception` 则保留实际向调用方抛出的那个异常。

#### 连接获取的独立事件

通过 FastDb 真正执行一次 `getObj()` 新借连接，无论由 `query()`、`rawQuery()`、直接 `begin()`，还是 `invoke()`、`transaction()` 触发，都生成一条独立的 `type = 'connectionAcquire'` 事件。复用已有连接不生成获取事件；向 `begin()` 显式传入已有 Connection 也不借连接。连接池内部的创建、健康检查与重试包含在这一条事件里，不拆成多条。配置校验、`testDb()` 和 `preConnect()` 不属于这段借用计时。

借用失败同样记录独立获取事件：`query` 和 `rawQuery` 为 null，`duration` 是获取历时，连接名及实际向外抛出的异常可见。没有执行 SQL 时，不生成 `query` 事件，也不调用全局 `onQuery`。

仅在借用入口已启用查询栈时启动获取计时，记录完成时若已关闭则丢弃事件；入口关闭、借用过程中才开启时，该次借用不补记获取事件，之后的查询执行可正常入栈。工厂中首次注册全局回调、开启查询栈，对当前查询的最终通知也会生效。回调重入可能使内层事件先于外层出现，不能假定获取事件与使用该连接的 SQL 总是相邻。嵌套事件历时可能互相包含，也不能把全部事件的 `duration` 相加当作业务端到端耗时。

```php
$db->isEnableQueryStack(true, maxStackSize: 256);
try {
    $db->rawQuery('SELECT 1');
} catch (Throwable $error) {
    $event = $db->getQueryStack(-1);
    if ($event !== null && $event->type === 'connectionAcquire') {
        $acquireMs = $event->duration * 1000;
        $connectionName = $event->connectionName;
        $sameException = $event->exception === $error;
    }
    throw $error;
}
```

## 记录慢查询日志

通过 `QueryResult::getDuration()` 获取耗时，单位为秒。下面以 **200 毫秒**为慢查询阈值，使用同一个日志方法记录 SQL、绑定参数、执行耗时和异常信息；可将 `error_log()` 替换为项目中的日志组件。

```php
use EasySwoole\FastDb\Mysql\QueryResult;

final class SlowQueryLogger
{
    public static function record(QueryResult $result): void
    {
        $durationMs = $result->getDuration() * 1000;
        if ($durationMs < 200) {
            return;
        }

        $builder = $result->getQueryBuilder();
        $exception = $result->getException();
        error_log(json_encode([
            'type' => 'slow_query',
            'sql' => $result->getRawSql() ?? $builder?->getLastPrepareQuery(),
            'params' => $builder?->getLastBindParams() ?? [],
            'durationMs' => $durationMs,
            'startTime' => $result->getStartTime(),
            'errorMessage' => $exception?->getMessage(),
            'errorCode' => $exception?->getCode(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
```

耗时使用 `hrtime()` 的单调时钟计算，系统时间调整不会影响耗时测量；`startTime` 仍为 Unix 时间戳，便于定位日志发生时间。示例仍按原有查询执行阶段达到 200 毫秒判定慢查询，包含 `query(callable)` 工厂构建，不把获取连接或日志回调算进该阈值。实体借用失败的替代结果按上一节说明处理。若需要发现查询执行很快、连接获取很慢的情况，应读取已开启的查询栈，对 `type === 'connectionAcquire'` 事件的 `duration` 设置独立阈值；获取事件不进入全局 `onQuery`。

### 方法一：FastDb 全局 onQuery

在应用初始化时注册，统一追踪通过该 FastDb 实例执行的查询，包括模型查询、直接调用 `query()` / `rawQuery()`，以及实际执行的 begin/commit/rollback。

```php
use EasySwoole\FastDb\FastDb;

FastDb::getInstance()->setOnQuery([SlowQueryLogger::class, 'record']);
```

`setOnQuery()` 会替换已有的全局回调。如果项目已经注册查询日志回调，应把慢查询判断加入现有回调，或在其中调用 `SlowQueryLogger::record($result)`。

### 方法二：在基础模型构造方法中设置 onQuery

需要按模型选择追踪范围时，可以定义一个基础模型，在构造方法中调用父类初始化，并注册实体级回调。需要记录慢查询日志的模型继承这个基础模型。

```php
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

abstract class SlowQueryModel extends AbstractEntity
{
    public function __construct(?array $data = null)
    {
        parent::__construct($data);
        $this->setOnQuery([SlowQueryLogger::class, 'record']);
    }
}

class ConfigModel extends SlowQueryModel
{
    #[Property(isPrimaryKey: true)]
    public int $configId;

    #[Property]
    public ?string $configContent;

    public function tableName(): string
    {
        return 'config';
    }
}

$config = new ConfigModel();
$config->queryLimit()->where('configId', 1);
$result = $config->all(); // 本次查询达到阈值时记录慢日志。
```

此方式覆盖实体实例调用的查询，例如 `all()`、`insert()`、`update()`、`delete()` 和关联查询。子类如果重写构造方法，需要调用 `parent::__construct($data)` 才能保留初始化及日志注册。

静态方法 `findRecord()`、`findAll()`、`fastUpdate()`、`fastDelete()` 使用各自的 `$onQuery` 参数，不会自动使用基础模型构造方法中设置的实体回调。需要追踪这些调用时，显式传入回调：

```php
$config = ConfigModel::findRecord(
    1,
    onQuery: [SlowQueryLogger::class, 'record']
);
```

全局回调与实体/单次回调可以同时触发。若两处都调用同一个慢日志方法，同一次执行可能记录两条日志，应按需要选择注册范围。

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
| `fastInsertAll(iterable $rows, ?string $tableName = null, ?callable $onQuery = null, int $batchSize = 500)` | 分批直接插入；合计影响行数 int\|string |
| `fastUpsert(iterable $rows, array $updateColumns, ?string $tableName = null, ?callable $onQuery = null, int $batchSize = 500)` | 主键/唯一键冲突时更新；合计影响行数 int\|string |
| `count($field = '*', $group = null)` | 整数或多列计数数组 |
| `sum/avg/max/min($cols, $group = null, $force = true)` | 数字或多列聚合数组 |
| `chunk(callable $func, int $chunkSize = 10)` | OFFSET 分页逐条回调；false 停止，不返回累计条数 |
| `chunkById(callable $func, int $chunkSize = 10, ?string $column = null): int` | 按稳定唯一列 ASC 键集分页；返回已交付条数 |
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
| `hideFields(array\|string $fields)` | 隐藏字段 |
| `persistFieldLimit(bool $enabled = true)` | all() 子实体输出限制传播 |
| `getFields()` / `getHideFields()` / `isPersistFieldLimit()` | 读取限制 |
| `withRelations(string\|array $relations): Query` | 本次 all/chunk/chunkById 批量预加载关系；空数组清除声明 |
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
| `withConnection(string $name, callable $callback): mixed` | 临时选库并恢复；回调接收 FastDb，不借还连接或改变事务 |
| `testDb(string $name = 'default')` | 检查连接，成功 true，失败异常 |
| `query(QueryBuilder\|callable $query, ?float $timeout = null)` | 构建器查询；QueryResult |
| `rawQuery(string $sql, ?float $timeout = null)` | 原始 SQL；QueryResult |
| `begin(?Connection $client = null, TransactionStartFlags $flags = TransactionStartFlags::None, ?float $timeout = null)` | 开始事务；bool |
| `commit(?Connection $client = null, TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null)` | 提交事务；bool |
| `rollback(?Connection $client = null, TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null)` | 回滚事务；bool |
| `transaction(callable $callback, TransactionStartFlags $flags = TransactionStartFlags::None, ?float $timeout = null): mixed` | 回调事务；已有事务只执行回调，拥有层负责结束；返回回调值 |
| `isInTransaction(?Connection $connection = null)` | 转发 Connection 的协议状态 getter；bool |
| `invoke(callable $call)` | 执行并归还连接；返回回调值 |
| `currentConnection()` | 当前 Connection 或 null |
| `recycleContext(): void` | 归还当前协程全部连接，未结束数据库事务由池清理 |
| `preConnect()` | 预热各连接池 |
| `reset()` | 重置全部连接池和定时器 |
| `setOnQuery(callable $call)` | 设置全局回调；返回管理器 |
| `isEnableQueryStack(bool $bool, int $maxStackSize = 256): static` | 每协程保留最近 N 条查询/获取连接事件；启停均应用容量，缩小时立即裁剪已有栈 |
| `getQueryStack(?int $index = null)` | 整栈、单项或 null；事件类型由 type 区分 |

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
php tests/run.php --filter TransactionTest --display-all-issues
php tests/run.php --filter ScopeIntegrationTest --display-all-issues
php tests/run.php --list-tests
php tests/run.php --log-junit ./test-results.xml
```

`tests/run.php` 使用 PHPUnit 13 的 `TextUI\Application` 并在 Swoole 协程内执行测试，信息查询命令在协程外运行。`vendor/bin/phpunit --testsuite unit` 可直接执行单元测试；数据库测试请使用协程入口。

配置位于 `phpunit.xml.dist`，分为 `unit` 与 `integration`。数据库用例结束后回收上下文、重置池并清理测试定时器。更多说明见 [tests/README.md](tests/README.md)。

## 使用边界与常见问题

### 查询后不能反复复用同一套条件吗？

`all()`、成功执行的更新/删除、聚合和关联查询会重置查询配置；`toArray()` 清理 SQL 条件，但保留字段输出限制。没有变化而直接返回的 `update()` 不重置限制。建议每次数据库操作明确设置所需条件。

### `chunk()` 与 `chunkById()` 应该怎么选？

只读遍历、需要保留自定义排序，且遍历期间筛选结果不会移动时，可以使用 `chunk()`。它按 OFFSET 分页；处理时会删除记录或修改筛选字段时，优先使用 `chunkById()`。深页的 OFFSET 还需要扫描并跳过前面的记录，而键集分页可以利用游标列索引从上次的位置继续。

例如有 `id = 1..6` 的六条记录，起初均为 `status = 0`，每次读取两条，并在回调中把状态更新为 1。`chunk()` 的查询过程是：

```sql
-- 首批返回 1、2，处理后这两条不再满足 status = 0。
SELECT * FROM users WHERE status = 0 ORDER BY id ASC LIMIT 0, 2;

-- 现在待处理记录为 3、4、5、6；OFFSET 2 跳过 3、4，返回 5、6。
SELECT * FROM users WHERE status = 0 ORDER BY id ASC LIMIT 2, 2;

-- 最后只剩 3、4，但 OFFSET 4 已无结果，遍历结束，3、4 被漏掉。
SELECT * FROM users WHERE status = 0 ORDER BY id ASC LIMIT 4, 2;
```

`chunkById()` 使用上一批末尾的游标，而不按当前结果集的位置跳过记录：

```sql
-- 首批返回 1、2。
SELECT * FROM users WHERE status = 0 ORDER BY id ASC LIMIT 2;

-- 1、2 的状态改变后，仍从 id > 2 继续，返回 3、4。
SELECT * FROM users WHERE (status = 0) AND id > 2 ORDER BY id ASC LIMIT 2;

-- 下一批返回 5、6。
SELECT * FROM users WHERE (status = 0) AND id > 4 ORDER BY id ASC LIMIT 2;
```

两者都逐条回调、只保留当前批次的数据，并保留实体或数组返回模式；回调返回严格的 `false` 都会提前停止，其他返回值或没有返回值时继续。`chunk()` 使用 OFFSET 分页，保留调用方排序，不返回累计条数；`chunkById()` 固定按稳定唯一列 ASC 键集分页，并返回已交付给回调的条数，包含触发停止的那一条。游标列应建立索引、保持唯一且非 NULL，遍历过程中不要修改该列；按其他非唯一列排序或对分组结果分块，应自行设计相应查询。

### 隐藏字段会不会改变数据库写入？

`insert()` / `update()` 使用 `fields()` 作为写入白名单；`hideFields()` 控制序列化输出，不会排除数据库写入字段。

### 查询少量字段后能直接把结果完整写回吗？

未加载字段仍可能有默认值/null，且对象比较基准不等于完整数据库记录。需要修改时只改已知字段，并使用 `fields()` 指定写入范围；需要完整状态时重新查询。

### 关联查询是否自动走其他数据库？

不会。关联和普通实体一样使用当前选中的单例连接。目标表名可以覆盖，连接需要由调用方选择。

### 列表里逐一调用关系方法，怎样避免 N+1 查询？

在父查询上使用 `queryLimit()->withRelations(['profile', 'orders'])`。实体列表仍通过原有 getter 读取关系；父查询启用 `returnAsArray()` 时，会把关系数组附加到同名键。没有 `withRelations()` 时，原有按需查询行为不变。

固定关系且每种关系不超过 250 个唯一父键时，通常从“1 条父查询 + 每个父对象的关联查询”变成“1 条父查询 + 每种关系 1 条批查询”。大列表会分批，动态条件不同的父对象按计划分组；不要把它理解为任何场景都只发一条关联 SQL。`chunk()` 和 `chunkById()` 会针对每个当前批次分别预加载。

父关联键必须被选中；实体模式不能隐藏该键，数组模式可以隐藏，但启用预加载后会临时构造父模型来捕获真实关系计划。关系方法内的每父 limit/page、分组和总数等形状不受支持，详见批量预加载章节。需要最新关联值时调用实体的 `clearRelationCache()`。

### 原始写入为什么没有执行钩子/转换？

`fastInsertAll()`、`fastUpsert()`、`fastUpdate()`、`fastDelete()`、`QueryBuilder` 与原始 SQL 是直接数据库操作。需要实体转换与钩子时通过实体 `insert()` / `update()` / `delete()`；单实体重复键更新可使用 `insert($updateDuplicateCols)`。

### 批量写入报错后，能否直接重试整个输入？

`fastInsertAll()` / `fastUpsert()` 不提供自动重试或自动事务。后批校验或执行失败时，前批可能已落库；回调抛异常时，本批 SQL 也可能已经成功。先核实哪些批次已经写入，再按业务唯一键、幂等规则或显式事务处理；生成器也可能已经被部分消费。

返回的合计是数据库影响行数，不能用它推断处理到第几个输入行；upsert 的更新影响行数与插入不同。需要全部成功或全部回滚时，在调用前为实际写入连接开启事务，并由事务拥有者提交或回滚。

### 如何批量查询 UUID 主键？

用数组/回调显式构建 `IN`。逗号主键字符串简写会调用 `intval()`，适用于数字主键。

### 事务中能使用 invoke() 吗？

可以。`invoke()` 复用当前连接时不会归还外层连接，嵌套调用也不会提前结束外层事务。手动事务仍由原调用方提交或回滚；使用 `transaction()` 时，只有入口原本没有事务的拥有层负责结束。手动重复 `begin()` 不提供 savepoint，`transaction()` 的同连接嵌套也不建立独立事务。

### withConnection 会自动提交或归还连接吗？

不会。它只临时选库并在退出时恢复原连接名；连接借还和事务生命周期没有变化。需要回调事务时，在其中调用 `transaction()`；需要本层借用结束后归还连接时，使用原有 `invoke()`。两个新 helper 的回调参数是 FastDb，`invoke()` 的回调参数仍是 Connection。

### transaction 返回 false，或捕获内层异常后，会提交吗？

false 是正常返回值，拥有层会提交并返回 false。同连接的加入层不单独提交或回滚；内层异常被外层捕获后，外层正常返回仍会提交全部已经执行的写入。需要整笔回滚时，让异常逃出拥有层，或由业务显式管理事务结束。

### 回滚后，为什么同一模型再 update() 没有写入？

模型在 SQL 成功时就接受了新的比较基准，数据库回滚不会恢复模型属性和基准。对象未发生新变化时，再次 `update()` 可能没有字段需要写入；请重新查询、重建对象，或按业务需要显式修改字段。之前 SQL 成功的更新返回值也不会因为后续回滚而改写。

### transaction 抛异常，是否一定代表所有写入都没有提交？

不能这样推断。开始或业务回调异常会使拥有层尝试回滚，但业务手工提交、DDL 隐式提交、COMMIT 已成功后的日志异常，以及提交超时或断线，都可能留下已提交或结果不明的状态。提交阶段失败不会自动追加 ROLLBACK；B 独立事务已经提交的内容也不会被 A 的回滚撤销。请按业务记录核实数据库结果，再决定后续处理。

### 目前没有哪些功能？

当前实体没有 migration/schema builder、save()、软删除、自动时间戳、复合主键映射、savepoint 嵌套事务、自动读写分离。需要这些能力时通过应用封装或底层 SQL 明确实现。批量数据库格式写入使用 `fastInsertAll()` / `fastUpsert()`，不提供逐实体生命周期的批量保存；关联批量加载需要显式声明 `withRelations()`，不会自动开启。

## 许可证

本项目采用 Apache License 2.0，详见 [LICENSE](LICENSE)。
