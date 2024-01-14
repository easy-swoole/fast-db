# fast-db

`EasySwoole` 为了支持以 `PHP 8` 注解的方式来定义数据库对象映射，于是开发了 `fast-db` 这个数据库操作组件。

## 组件要求

- php: >= 8.1
- easyswoole/mysqli: ^3.0
- easyswoole/pool: ^2.0
- easyswoole/spl: ^2.0

## 安装方法

```bash
composer require easyswoole/fast-db
```

## 连接注册

### 在 EasySwoole 框架中使用

首先我们在 `EasySwoole` 框架的 `EasySwooleEvent` 事件（即框架根目录的 `EasySwooleEvent.php` 文件中）的 `initialize` 方法 或 `mainServerCreate`
方法中进行注册连接，如下所示：

EasySwooleEvent.php

```php
<?php

namespace EasySwoole\EasySwoole;

use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\FastDb\FastDb;

class EasySwooleEvent implements Event
{
    public static function initialize()
    {
        date_default_timezone_set('Asia/Shanghai');

        // 注册方式1：在 initialize 方法中注册连接
        $config = new \EasySwoole\FastDb\Config([
            'name'              => 'default',    // 设置 连接池名称，默认为 default
            'host'              => '127.0.0.1',  // 设置 数据库 host
            'user'              => 'easyswoole', // 设置 数据库 用户名
            'password'          => 'easyswoole', // 设置 数据库 用户密码
            'database'          => 'easyswoole', // 设置 数据库库名
            'port'              => 3306,         // 设置 数据库 端口
            'timeout'           => 5,            // 设置 数据库连接超时时间
            'charset'           => 'utf8',       // 设置 数据库字符编码，默认为 utf8
            'autoPing'          => 5,            // 设置 自动 ping 客户端链接的间隔
            'useMysqli'         => false,        // 设置 不使用 php mysqli 扩展连接数据库
            // 配置 数据库 连接池配置，配置详细说明请看连接池组件 https://www.easyswoole.com/Components/Pool/introduction.html
            // 下面的参数可使用组件提供的默认值
            'intervalCheckTime' => 15 * 1000,    // 设置 连接池定时器执行频率
            'maxIdleTime'       => 10,           // 设置 连接池对象最大闲置时间 (秒)
            'maxObjectNum'      => 20,           // 设置 连接池最大数量
            'minObjectNum'      => 5,            // 设置 连接池最小数量
            'getObjectTimeout'  => 3.0,          // 设置 获取连接池的超时时间
            'loadAverageTime'   => 0.001,        // 设置 负载阈值
        ]);
        // 或使用对象设置属性方式进行配置
        // $config->setName('default');
        // $config->setHost('127.0.0.1');
        FastDb::getInstance()->addDb($config);
        // 或在注册时指定连接池的名称
        // FastDb::getInstance()->addDb($config, $config['name']);
    }

    public static function mainServerCreate(EventRegister $register)
    {
        // 注册方式2：在 mainServerCreate 方法中注册连接
//        $config = new \EasySwoole\FastDb\Config([
//            'name'              => 'default',    // 设置 连接池名称，默认为 default
//            'host'              => '127.0.0.1',  // 设置 数据库 host
//            'user'              => 'easyswoole', // 设置 数据库 用户名
//            'password'          => 'easyswoole', // 设置 数据库 用户密码
//            'database'          => 'easyswoole', // 设置 数据库库名
//            'port'              => 3306,         // 设置 数据库 端口
//            'timeout'           => 5,            // 设置 数据库连接超时时间
//            'charset'           => 'utf8',       // 设置 数据库字符编码，默认为 utf8
//            'autoPing'          => 5,            // 设置 自动 ping 客户端链接的间隔
//            // 配置 数据库 连接池配置，配置详细说明请看连接池组件 https://www.easyswoole.com/Components/Pool/introduction.html
//            // 下面的参数可使用组件提供的默认值
//            'intervalCheckTime' => 15 * 1000,    // 设置 连接池定时器执行频率
//            'maxIdleTime'       => 10,           // 设置 连接池对象最大闲置时间 (秒)
//            'maxObjectNum'      => 20,           // 设置 连接池最大数量
//            'minObjectNum'      => 5,            // 设置 连接池最小数量
//            'getObjectTimeout'  => 3.0,          // 设置 获取连接池的超时时间
//            'loadAverageTime'   => 0.001,        // 设置 负载阈值
//        ]);
//        FastDb::getInstance()->addDb($config);
    }
}
```

> 上述2种注册方式注册结果是一样的。如需注册多个链接，请在配置项中加入 name 属性用于区分连接池。

### 在 其他框架中使用

```php
<?php
use EasySwoole\FastDb\FastDb;
$config = new \EasySwoole\FastDb\Config([
    'name'              => 'default',    // 设置 连接池名称，默认为 default
    'host'              => '127.0.0.1',  // 设置 数据库 host
    'user'              => 'easyswoole', // 设置 数据库 用户名
    'password'          => 'easyswoole', // 设置 数据库 用户密码
    'database'          => 'easyswoole', // 设置 数据库库名
    'port'              => 3306,         // 设置 数据库 端口
    'timeout'           => 5,            // 设置 数据库连接超时时间
    'charset'           => 'utf8',       // 设置 数据库字符编码，默认为 utf8
    'autoPing'          => 5,            // 设置 自动 ping 客户端链接的间隔
    'useMysqli'         => false,        // 设置 不使用 php mysqli 扩展连接数据库
    // 配置 数据库 连接池配置，配置详细说明请看连接池组件 https://www.easyswoole.com/Components/Pool/introduction.html
    // 下面的参数可使用组件提供的默认值
    'intervalCheckTime' => 15 * 1000,    // 设置 连接池定时器执行频率
    'maxIdleTime'       => 10,           // 设置 连接池对象最大闲置时间 (秒)
    'maxObjectNum'      => 20,           // 设置 连接池最大数量
    'minObjectNum'      => 5,            // 设置 连接池最小数量
    'getObjectTimeout'  => 3.0,          // 设置 获取连接池的超时时间
    'loadAverageTime'   => 0.001,        // 设置 负载阈值
]);
FastDb::getInstance()->addDb($config);
```

### 配置项解析

`\EasySwoole\FastDb\Config `继承自 `\EasySwoole\Pool\Config` ，因此 `ORM` 具备连接池的特性。

- autoPing
- intervalCheckTime
- maxIdleTime
- maxObjectNum
- minObjectNum

## AbstractEntity 使用

### 定义模型

#### 定义模型规范

1. 任何模型都必须继承 `\EasySwoole\FastDb\Entity` 并实现 `tableName()` 方法，该方法用于返回该数据表的表名。

2. 任何模型都必须具有一个唯一主键，作为某个模型对象的唯一id，一般建议为 `int` 类型的自增id。

3. 对象的属性，也就是数据表对应的字段，请用 `#[Property]` 进行标记。

#### 示例

例如，我们有个表名为 ```user``` 的数据表，表结构如下：

```sql
CREATE TABLE `easyswoole_user`
(
    `id`      int unsigned NOT NULL AUTO_INCREMENT COMMENT 'increment id',
    `name`    varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'name',
    `status`  tinyint unsigned DEFAULT '0' COMMENT 'status',
    `score`   int unsigned DEFAULT '0' COMMENT 'score',
    `sex`     tinyint unsigned DEFAULT '0' COMMENT 'sex',
    `address` json                                                          DEFAULT NULL COMMENT 'address',
    `email`   varchar(150) COLLATE utf8mb4_general_ci                       DEFAULT NULL COMMENT 'email',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

则它对应的实体类如下：

```php
<?php
declare(strict_types=1);

namespace App\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

/**
 * @property int $id increment id
 * @property string|null $name name
 * @property int|null $status status
 * @property int|null $score score
 * @property int|null $sex sex
 * @property string|null $address address
 * @property string|null $email email
 */
class EasySwooleUser extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public ?string $name;
    #[Property]
    public ?int $status;
    #[Property]
    public ?int $score;
    #[Property]
    public ?int $sex;
    #[Property]
    public ?string $address;
    #[Property]
    public ?string $email;

    public function tableName(): string
    {
        return 'easyswoole_user';
    }
}
```

### 模型创建脚本

`EasySwoole` 提供了创建模型的命令，您可以很方便的根据数据表创建对应模型。不过这个功能目前仅限在 `EasySwoole` 框架中使用

```
php easyswoole.php model gen -table={table_name}
```

在使用脚本之前需要先在 `EasySwoole` 框架中进行注册 `ORM` 连接池和注册创建脚本命令，修改 `EasySwoole` 框架根目录的 `bootstrap.php` 文件，如下：

```php
<?php
// bootstrap.php
// 全局bootstrap事件
date_default_timezone_set('Asia/Shanghai');

$argvArr = $argv;
array_shift($argvArr);
$command = $argvArr[0] ?? null;
if ($command === 'model') {
    \EasySwoole\EasySwoole\Core::getInstance()->initialize();
}
\EasySwoole\Command\CommandManager::getInstance()->addCommand(new \EasySwoole\FastDb\Commands\ModelCommand());
```

#### 创建模型

可选参数如下：

| 参数  | 类型 | 默认值 | 备注 |
| ------- | ------- | ------- | ------- |
| -db-connection | string | default | 连接池名称，脚本会根据当前连接池配置创建 |
| -path | string | App/Model | 模型路径 |
| -with-comments | bool | false | 是否增加字段属性注释 |

#### 创建示例

在数据库中先导入数据表 `DDL`，如：

```sql
CREATE TABLE `easyswoole_user`
(
    `id`      int unsigned NOT NULL AUTO_INCREMENT COMMENT 'increment id',
    `name`    varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'name',
    `status`  tinyint unsigned DEFAULT '0' COMMENT 'status',
    `score`   int unsigned DEFAULT '0' COMMENT 'score',
    `sex`     tinyint unsigned DEFAULT '0' COMMENT 'sex',
    `address` json                                                          DEFAULT NULL COMMENT 'address',
    `email`   varchar(150) COLLATE utf8mb4_general_ci                       DEFAULT NULL COMMENT 'email',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

或数据库已有上述数据表也可。

执行如下命令，创建模型：

```bash
php easyswoole.php model gen -table=easyswoole_user -with-comments
```

创建的模型如下：

```php
<?php

declare(strict_types=1);

namespace App\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

/**
 * @property int $id
 * @property string|null $name
 * @property int|null $status
 * @property int|null $score
 * @property int|null $sex
 * @property string|null $address
 * @property string|null $email
 */
class EasyswooleUser extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public ?string $name;
    #[Property]
    public ?int $status;
    #[Property]
    public ?int $score;
    #[Property]
    public ?int $sex;
    #[Property]
    public ?string $address;
    #[Property]
    public ?string $email;

    public function tableName(): string
    {
        return 'easyswoole_user';
    }
}
```

### 新增

#### 添加一条数据

> `insert()` 方法，返回值为 `bool` 类型的值，返回值为 `true` 表示添加成功，返回值为 `false` 表示添加失败。

第一种是实例化模型对象后赋值并保存：

```php
<?php
$user = new User();
$user->name = 'easyswoole';
$user->email = 'easyswoole@qq.com';
$user->insert();
// 相当于 sql: INSERT  INTO `easyswoole_user` (`name`, `email`)  VALUES ('easyswoole', 'easyswoole@qq.com')
```

也可以使用 `setData` 方法批量赋值：

```php
<?php
$user = new User();
$user->setData([
    'name'  => 'easyswoole',
    'email' => 'easyswoole@qq.com'
]);
$user->insert();
```

或者直接在实例化的时候传入数据

```php
<?php
$user = new User([
    'name'  => 'easyswoole',
    'email' => 'easyswoole@qq.com'
]);
$user->insert();
```

#### 获取自增ID

如果要获取新增数据的自增ID，可以使用下面的方式：

```php
<?php
$user = new User();
$user->name = 'easyswoole';
$user->email = 'easyswoole@qq.com';
$user->insert();
// 获取自增ID
echo $user->id;
```

注意这里其实是获取模型的主键，如果你的主键不是 `id`，而是 `user_id` 的话，其实获取自增ID就变成这样：

```php
<?php
$user = new User();
$user->name = 'easyswoole';
$user->email = 'easyswoole@qq.com';
$user->insert();
// 获取自增ID
echo $user->user_id;
```

#### 添加多条数据

> `insertAll()` 方法新增数据返回的是包含新增模型（带自增ID）的对象数组 或 普通数组。

> `insertAll()` 方法的返回类型受模型的 `queryLimit` 属性 的 `fields` 属性的 `returnAsArray` 属性影响（可能返回普通数组）。

支持批量新增，可以使用：

```php
<?php
$user = new User();
$list = [
    ['name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
    ['name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
];
$user->insertAll($list); // 结果为 对象数组

$user = new User();
$list = [
    ['name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
    ['name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
];
$user->queryLimit()->fields(null, true);
$user->insertAll($list); // 结果为 普通数组
```

`insertAll` 方法新增数据默认会自动识别数据是需要新增还是更新操作，当数据中存在主键的时候会认为是更新操作，如果你需要带主键数据批量新增，可以使用下面的方式：

```php
<?php
$user = new User;
$list = [
    ['id' => 1, 'name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
    ['id' => 2, 'name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
];
$user->insertAll($list, false);
```

#### onInsert注解

修改 `User` 模型类文件，添加 `OnInsert` 注解 和 `onInsert` 方法，`onInsert` 方法用于对添加前的数据做一些处理。

User.php

```php
<?php

declare(strict_types=1);

namespace App\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
// ...

/**
 * @property int    $id
 * @property string $name
 * @property int    $status
 * @property int    $create_time
 * @property string $email
 */
#[OnInsert('onInsert')]
class User extends AbstractEntity
{
    // ...
    
    public function onInsert()
    {
        if (empty($this->create_time)) {
            $this->create_time = time();
        }
        if (empty($this->status)) {
            $this->status = 1;
        }
    }
}
```

然后尝试新增数据

```php
<?php
$user = new User();
$user->name = 'easyswoole';
$user->email = 'easyswoole@qq.com';
$user->insert(); // INSERT  INTO `easyswoole_user` (`name`, `status`, `create_time`, `email`)  VALUES ('easyswoole', 1, 1704521166, 'easyswoole@qq.com')
```

#### ON DUPLICATE KEY UPDATE

```php
<?php
$user = new User();
$user->name = 'easyswoole100';
$updateDuplicateCols = ['name'];
$user->insert($updateDuplicateCols); // INSERT  INTO `easyswoole_user` (`name`, `status`, `create_time`)  VALUES ('easyswoole100', 1, 1704521621) ON DUPLICATE KEY UPDATE `name` = 'easyswoole100'

$user = new User();
$user->name = 'easyswoole100';
$updateDuplicateCols = ['name', 'id' => 1];
$user->insert($updateDuplicateCols); // INSERT  INTO `easyswoole_user` (`name`, `status`, `create_time`)  VALUES ('easyswoole100', 1, 1704521622) ON DUPLICATE KEY UPDATE `id` = 1, `name` = 'easyswoole100'
```

### 更新

> `update()` 方法，返回值为 `bool` 类型的值，值为 `true`时表示影响行数大于0的更新成功。

> `updateWithLimit()` 方法，返回值为 `int` 类型的值，值表示更新影响的行数。

> `fastUpdate` 方法，返回值为 `int` 类型的值，值表示更新影响的行数。

#### 查找并更新

在取出数据后，更改字段内容后更新数据。

```php
<?php
$user = User::findRecord(1);
$user->name = 'easyswoole111';
$user->email = 'easyswoole111@qq.com';
$user->update();
```

#### 直接更新数据

也可以直接带更新条件来更新数据

```php
$user = new User();
// updateWithLimit 方法第二个参数为更新条件
$user->updateWithLimit([
    'name'  => 'easyswoole112',
    'email' => 'easyswoole112@qq.com'
], ['id' => 1]);

// 调用静态方法
User::fastUpdate(['id' => 1], [
    'name'  => 'easyswoole112',
    'email' => 'easyswoole112@qq.com'
]);

User::fastUpdate(function (\EasySwoole\Mysqli\QueryBuilder $queryBuilder) {
  $queryBuilder->where('id', 1);
}, [
    'name'  => 'easyswoole112',
    'email' => 'easyswoole112@qq.com'
]);

User::fastUpdate(1, [
    'name'  => 'easyswoole112',
    'email' => 'easyswoole112@qq.com'
]);

User::fastUpdate('1,2', [
    'name'  => 'easyswoole112',
    'email' => 'easyswoole112@qq.com'
]);
```

必要的时候，你也可以使用 `Query` 对象来直接更新数据。

```php
<?php
$user = new User();
$user->queryLimit()->where('id', 1);
$user->updateWithLimit(['name' => 'easyswoole']);
```

#### 闭包更新

可以通过闭包函数使用更复杂的更新条件，例如：

```php
<?php
$user = new User();
$user->updateWithLimit(['name' => 'easyswoole'], function (\EasySwoole\FastDb\Beans\Query $query) {
    // 更新status值为1 并且id大于10的数据
    $query->where('status', 1)->where('id', 10, '>');
}); // UPDATE `easyswoole_user` SET `name` = 'easyswoole' WHERE  `status` = 1  AND `id` > 10
```

### 删除

> `delete()` 方法，返回值为 `bool` 类型的值，值为 `true`时表示影响行数大于0的删除成功。

> `fastDelete()` 方法返回值为 `int` 类型的值
> - 删除成功时返回值为 `int` 类型的值，表示删除操作影响的行数
> - 删除失败时返回值为 `null`

#### 查找并删除

在取出数据后，然后删除数据。

```php
<?php
$user = User::findRecord(1);
$user->delete();
```

#### 根据主键删除

直接调用静态方法

```php
User::fastDelete(1);
// 支持批量删除多个数据
User::fastDelete('1,2,3');
```

> 当 `fastDelete` 方法传入空值（包括空字符串和空数组）的时候不会做任何的数据删除操作，但传入0则是有效的。

#### 条件删除

使用数组进行条件删除，例如：

```php
<?php
// 删除状态为0的数据
User::fastDelete(['status' => 0]);
```

还支持使用闭包删除，例如：

```php
<?php
User::fastDelete(function (\EasySwoole\Mysqli\QueryBuilder $query) {
    $query->where('id', 10, '>');
});
```

### 查询

#### 获取单个数据

> `findRecord()` 方法，返回值为当前模型的对象实例，可以使用模型的方法。

> `find()` 方法，返回值为当前模型的对象实例，可以使用模型的方法。

获取单个数据的方法包括：

```php
<?php
// 取出主键为1的数据
$user = User::findRecord(1);
echo $user->name;

// 使用数组查询
$user = User::findRecord(['name' => 'easyswoole']);
echo $user->name;

// 使用闭包查询
$user = User::findRecord(function (\EasySwoole\Mysqli\QueryBuilder $query) {
    $query->where('name', 'easyswoole');
});
echo $user->name;
```

或者在实例化模型后调用查询方法

```php
$user = new User();
// 查询单个数据
$user->qyeryLimit()->where('name', 'easyswoole');
$userModel = $user->find();
echo $userModel->name;
```

#### 获取多个数据

> `findAll()` 方法返回的是一个包含模型对象的二维普通数组或者对象数组。返回的结果类型受参数 `returnAsArray` 的影响。

> `all()` 方法返回的是 `\EasySwoole\FastDb\Beans\ListResult` 类的对象。

```php
<?php
// 使用主键查询
$list = User::findAll('1,2');

// 使用数组查询
$list = User::findAll(['status' => 1]);

// 使用闭包查询
$list = User::findAll(function (\EasySwoole\Mysqli\QueryBuilder $query) {
    $query->where('status', 1)->limit(3)->orderBy('id', 'asc');
}, null, false);
foreach ($list as $key => $user) {
    echo $user->name;
}
```

> 数组方式和闭包方式的数据查询的区别在于，数组方式只能定义查询条件，闭包方式可以支持更多的连贯操作，包括排序、数量限制等。

```php
<?php
// 获取多个数据 不使用条件查询
/** @var User[] $users */
$users = (new User())->all(); // 返回结果：\EasySwoole\FastDb\Beans\ListResult 类的对象
foreach ($users as $user) {
    echo $user->name . "\n";
}

// 获取多个数据 使用条件查询(闭包条件)
$userModel = new User();
$userModel->queryLimit()->where('id', [401, 403], 'IN')->where('name', 'easyswoole-1');
$users = $userModel->all(); // 返回结果：\EasySwoole\FastDb\Beans\ListResult 类的对象
foreach ($users as $user) {
    echo $user->name . "\n";
}
```

#### 转换字段

例如我们有数据表 `student_info`，`DDL` 如下：

```php
CREATE TABLE `student_info` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
  `studentId` int unsigned NOT NULL DEFAULT '0' COMMENT 'student id',
  `address` json DEFAULT NULL COMMENT 'address',
  `note` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'note',
  `sex` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'sex:1=male 2=female 0=unknown',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

我们可以对 `address` 和 `sex` 字段做转换处理来满足业务开发需求，这里我们用到了 `php8` 的枚举特性。

定义为模型为：

```php
<?php
namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Tests\Model\Address;
use EasySwoole\FastDb\Tests\Model\SexEnum;

class StudentInfoModel extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property()]
    public int $studentId;

    #[Property(
        convertObject: Address::class
    )]
    public Address $address;

    #[Property]
    public ?string $note;

    #[Property(
        convertObject: SexEnum::class
    )]
    public SexEnum $sex;

    function tableName(): string
    {
        return "student_info";
    }
}
```

Address.php

```php
<?php
namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\ConvertJson;

class Address extends ConvertJson
{
    public $city;
    public $province;
}
```

SexEnum.php 使用枚举特性。

```php
<?php
namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;

enum SexEnum implements ConvertObjectInterface
{
    case UNKNUWN;
    case MALE;
    case FEMAILE;

    public static function toObject(mixed $data): object
    {
        switch ($data){
            case 1:{
                return self::MALE;
            }
            case 2:{
                return self::FEMAILE;
            }
            default:{
                return self::UNKNUWN;
            }
        }
    }

    function toValue()
    {
        return match ($this){
            self::MALE=>1,
            self::FEMAILE=>2,
            default=>0
        };
    }
}
```

转换字段使用示例：

```php
<?php
// 添加记录
$address = new \EasySwoole\FastDb\Tests\Model\Address();
$address->province = 'FuJian';
$address->city = 'XiaMen';
$sex = \EasySwoole\FastDb\Tests\Model\SexEnum::MALE;
$model = new StudentInfoModel([
    'studentId' => 1,
    'address'   => $address->toValue(),
    'sex'       => $sex->toValue(),
    'note'      => 'this is note',
]);
// or
// $model->address = $address;
// $model->sex = $sex;
$model->insert(); // INSERT  INTO `student_info` (`studentId`, `address`, `note`, `sex`)  VALUES (1, '{\"city\":\"XiaMen\",\"province\":\"FuJian\"}', 'this is note', 1)

// 查询一条数据
$studentInfo = StudentInfoModel::findRecord(1);
var_dump($studentInfo->address->city); // "XiaMen"
var_dump($studentInfo->address->province); // "FuJian"
var_dump($studentInfo->sex); // 枚举类型 enum(EasySwoole\FastDb\Tests\Model\SexEnum::MALE)
var_dump($studentInfo->toArray(false));

// 查询多条数据
$studentInfo = new StudentInfoModel();
$studentInfos = $studentInfo->all();
foreach ($studentInfos as $studentInfo) {
    var_dump($studentInfo->address->city);
    var_dump($studentInfo->address->province);
    var_dump($studentInfo->sex);
    var_dump($studentInfo->toArray(false));
}
```

#### 自定义返回结果类型

`findAll()` 方法的 `returnAsArray` 参数可以设置查询的返回对象的名称（默认是模型对象）。

```php
<?php
$returnAsArray = true;
(new User())->findAll(null, null, $returnAsArray);
```

`all()` 方法调用 `queryLimit()` 方法的 `fields()` 方法的 `returnAsArray` 参数可以设置查询的返回对象的名称（默认是模型对象）。

```php
<?php
$returnAsArray = true;
(new User())->queryLimit()->fields(null, $returnAsArray);
```

#### 数据分批处理 chunk

模型也支持对返回的数据分批处理。特别是如果你需要处理成千上百条数据库记录，可以考虑使用 `chunk` 方法，该方法一次获取结果集的一小块，然后填充每一小块数据到要处理的闭包，该方法在编写处理大量数据库记录的时候非常有用。

比如，我们可以全部用户表数据进行分批处理，每次处理 100 个用户记录：

```php
<?php
(new User())->chunk(function (User $user) {
    // 处理 user 模型对象
    $user->updateWithLimit(['status' => 1]);
}, 20);
```

#### 分页查询 page

- 方法说明：

```\EasySwoole\FastDb\Beans\Query::page``` 方法

```php
function page(?int $page,bool $withTotalCount = false,int $pageSize = 10): Query
```

- 使用示例：

```php
// 使用条件的分页查询 不进行汇总 withTotalCount=false
// 查询 第1页 每页10条 page=1 pageSize=10
$user = new User();
$user->queryLimit()->page(1, false, 10);
$resultObject = $user->all();
foreach ($resultObject as $oneUser) {
    var_dump($oneUser->name);
}

// 使用条件的分页查询 进行汇总 withTotalCount=true
// 查询 第1页 每页10条 page=1 pageSize=10
$user = new User();
$user->queryLimit()->page(1, true, 10)->where('id', 3, '>');
$resultObject = $user->all();
$total = $resultObject->totalCount(); // 汇总数量
foreach ($resultObject as $oneUser) {
    var_dump($oneUser->name);
}
var_dump($total);
```

### 聚合

在模型中也可以调用数据库的聚合方法进行查询，例如：

| 方法  | 说明                                     |
| ------- | ------------------------------------------ |
| count | 统计数量，参数是要统计的字段名（可选）   |
| max   | 获取最大值，参数是要统计的字段名（必须） |
| min   | 获取最小值，参数是要统计的字段名（必须） |
| avg   | 获取平均值，参数是要统计的字段名（必须） |
| sum   | 获取总分，参数是要统计的字段名（必须）   |

#### count

```php
<?php
$user = new User();
$user->count();
// SELECT  COUNT(*) as count FROM `easyswoole_user` LIMIT 1

$user->count('id', 'name');
// SELECT  COUNT(id) as count FROM `easyswoole_user` GROUP BY name  LIMIT 1

$user->queryLimit()->fields(['id', 'name']);
$user->count(null, 'name');
// SELECT  COUNT(`id`) as id, COUNT(`name`) as name FROM `easyswoole_user` GROUP BY name  LIMIT 1
```

#### max

```php
$user = new User();
$user->max('id');
// SELECT  MAX(`id`) as id FROM `easyswoole_user` LIMIT 1

$user->max('id', 'name');
// SELECT  MAX(`id`) as id FROM `easyswoole_user` GROUP BY name  LIMIT 1

$user->max(['id', 'name'], 'name');
// SELECT  MAX(`id`) as id , MAX(`name`) as name FROM `easyswoole_user` GROUP BY name  LIMIT 1
```

#### min

```php
<?php
$user = new User();
$user->min('id');
// SELECT  MIN(`id`) as id FROM `easyswoole_user` LIMIT 1

$user->min('id', 'name');
// SELECT  MIN(`id`) as id FROM `easyswoole_user` GROUP BY name  LIMIT 1

$user->min(['id', 'name'], 'name');
// SELECT  MIN(`id`) as id , MIN(`name`) as name FROM `easyswoole_user` GROUP BY name  LIMIT 1
```

#### avg

```php
<?php
$user = new User();
$user->avg('id');
// SELECT  AVG(`id`) as id FROM `easyswoole_user` LIMIT 1

$user->avg('id', 'name');
// SELECT  AVG(`id`) as id FROM `easyswoole_user` GROUP BY name  LIMIT 1

$user->avg(['id', 'name'], 'name');
// SELECT  AVG(`id`) as id , AVG(`name`) as name FROM `easyswoole_user` GROUP BY name  LIMIT 1
```

#### sum

```php
<?php
$user = new User();
$user->sum('id');
// SELECT  SUM(`id`) as id FROM `easyswoole_user` LIMIT 1

$user->sum('id', 'name');
// SELECT  SUM(`id`) as id FROM `easyswoole_user` GROUP BY name  LIMIT 1

$user->sum(['id', 'name'], 'name');
// SELECT  SUM(`id`) as id , SUM(`name`) as name FROM `easyswoole_user` GROUP BY name  LIMIT 1
```

### 数组访问和转换

转换为数组

可以使用 `toArray` 方法将当前的模型实例输出为数组，例如：

```php
<?php
$user = User::findRecord(1);
var_dump($user->toArray(false));

/** @var \EasySwoole\FastDb\Beans\ListResult $listResult */
$listResult = (new User)->all();
$objectArr = $listResult->toArray(); // 转换为 对象数组
```

### 事件注解

#### 适用场景

模型事件类似于 `ThinkPHP` 框架模型的模型事件，可用于在数据写入数据库之前做一些预处理操作。

模型事件是指在进行模型的写入操作的时候触发的操作行为，包括调用模型对象的 `insert`、`delete`、`update` 方法以及对实体对象初始化时触发。

模型类支持 `OnInitialize`、`OnInsert`、`OnDelete`、`OnUpdate` 事件。

| 事件行为注解  | 描述                                     |
| ------- | ------------------------------------------ |
| OnInitialize | 实体被实例化时触发 |
| OnInsert     | 新增前 |
| OnDelete     | 删除前 |
| OnUpdate     | 更新前 |

#### 使用示例

- 声明事件注解

在模型类中可以通过注解及定义类方法来实现事件注解的声明，如下所示：

```php
<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
// ...

/**
 * @property int    $id
 * @property string $name
 * @property int    $status
 * @property int    $score
 * @property int    $create_time
 */
#[OnInitialize('onInitialize')]
#[OnInsert('onInsert')]
#[OnDelete('onDelete')]
#[OnUpdate('onUpdate')]
class User extends AbstractEntity
{
    // ...
    
    public function onInitialize()
    {
        // todo::
    }

    public function onInsert()
    {
        if (empty($this->status)) {
            return false;
        }
        if (empty($this->create_time)) {
            $this->create_time = time();
        }
    }

    public function onDelete()
    {
        // todo::
    }

    public function onUpdate()
    {
        // todo::
    }
}
```

上面定义了 `OnInitialize`、`OnInsert`、`OnDelete`、`OnUpdate` 事件注解，并在注解中通过形如 `#[OnInitialize('onInitialize')]`
的方式给 `OnInitialize` 注解传入参数，给对应的事件行为设置事件被触发时执行的回调 `onInitialize`、`onInsert`
、`onDelete`、`onUpdate`。

设置的回调方法会自动传入一个参数（当前的模型对象实例），并且 `OnInsert`、`OnDelete`、`OnUpdate` 事件的回调方法(`onInsert`
、`onDelete`、`onUpdate`) 如果返回 `false`，则不会继续执行。

- 使用

```php
$user = new User(['name' => 'EasySwoole', 'id' => 1000]);
$result = $user->insert();
var_dump($result); // false，返回 false，表示 insert 失败。
```

### 关联

#### 一对一关联

##### 定义关联

定义一对一关联，例如，一个用户都有一个个人资料，我们定义 `User` 模型如下：

```php
<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Tests\Model\UserProfile;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 */
class User extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public ?string $name;
    #[Property]
    public ?string $email;

    public function tableName(): string
    {
        return 'easyswoole_user';
    }

    #[Relate(
        targetEntity: UserProfile::class,
        targetProperty: 'user_id' // 关联模型的数据表的主键
    )]
    public function profile()
    {
        return $this->relateOne();
    }
}
```

##### 关联查找

定义好关联之后，就可以使用下面的方法获取关联数据：

```php
<?php
$user = User::findRecord(1);
// 输出 UserProfile 关联模型的email属性
echo $user->profile()->email;
```

#### 一对多关联

##### 定义关联

```php
<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Tests\Model\UserCar;

/**
 * @property int    $id
 * @property string $name
 * @property int    $status
 * @property int    $score
 * @property string $email
 */
class User extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public ?string $name;
    #[Property]
    public ?int $status;
    #[Property]
    public ?int $score;
    #[Property]
    public ?int $create_time;
    #[Property]
    public ?string $info;
    #[Property]
    public ?string $foo;
    #[Property]
    public ?string $bar;
    #[Property]
    public ?int $login_time;
    #[Property]
    public ?int $login_times;
    #[Property]
    public ?int $read;
    #[Property]
    public ?string $title;
    #[Property]
    public ?string $content;
    #[Property]
    public ?string $email;

    public function tableName(): string
    {
        return 'easyswoole_user';
    }
    
    #[Relate(
        targetEntity: UserCar::class,
        targetProperty: 'user_id'
    )]
    public function cars()
    {
        return $this->relateMany();
    }
}
```

##### 关联查询

```php
<?php
$article = User::findRecord(1);
// 获取用户拥有的所有车辆品牌
$listResult = $article->cars();
foreach ($listResult as $userCar) {
    echo $userCar->car_name . "\n";
}
// or
$objectArr = $listResult->toArray(); // 转换为 对象数组
foreach ($objectArr as $userCar) {
    echo $userCar->car_name . "\n";
}
```

## FastDb 使用

### 调用方法说明

#### addDb

用于注册连接池。

```php
<?php
$config = new \EasySwoole\FastDb\Config([
    'name'              => 'default',    // 设置 连接池名称，默认为 default
    'host'              => '127.0.0.1',  // 设置 数据库 host
    'user'              => 'easyswoole', // 设置 数据库 用户名
    'password'          => 'easyswoole', // 设置 数据库 用户密码
    'database'          => 'easyswoole', // 设置 数据库库名
    'port'              => 3306,         // 设置 数据库 端口
    'timeout'           => 5,            // 设置 数据库连接超时时间
    'charset'           => 'utf8',       // 设置 数据库字符编码，默认为 utf8
    'autoPing'          => 5,            // 设置 自动 ping 客户端链接的间隔
    'useMysqli'         => false,        // 设置 不使用 php mysqli 扩展连接数据库
    // 配置 数据库 连接池配置，配置详细说明请看连接池组件 https://www.easyswoole.com/Components/Pool/introduction.html
    // 下面的参数可使用组件提供的默认值
    'intervalCheckTime' => 15 * 1000,    // 设置 连接池定时器执行频率
    'maxIdleTime'       => 10,           // 设置 连接池对象最大闲置时间 (秒)
    'maxObjectNum'      => 20,           // 设置 连接池最大数量
    'minObjectNum'      => 5,            // 设置 连接池最小数量
    'getObjectTimeout'  => 3.0,          // 设置 获取连接池的超时时间
    'loadAverageTime'   => 0.001,        // 设置 负载阈值
]);
// 或使用对象设置属性方式进行配置
// $config->setName('default');
// $config->setHost('127.0.0.1');
FastDb::getInstance()->addDb($config);
```

#### testDb

用于测试连接池的数据库配置是否可用。

```php
FastDb::getInstance()->testDb();
FastDb::getInstance()->testDb('read');
FastDb::getInstance()->testDb('write');
```

#### setOnQuery

设置连接池连接执行 `SQL` 查询时的回调，可用于监听 `SQL`，可查看监听 `SQL` 章节。

```php
<?php
FastDb::getInstance()->setOnQuery(function (\asySwoole\FastDb\Mysql\QueryResult $queryResult) {
    // 打印 sql
    if ($queryResult->getQueryBuilder()) {
        echo $queryResult->getQueryBuilder()->getLastQuery() . "\n";
    } else {
        echo $queryResult->getRawSql() . "\n";
    }
});
```

#### selectConnection

todo::

#### invoke

可用于执行数据库操作。

在高并发情况下，资源浪费的占用时间越短越好，可以提高程序的服务效率。

`ORM` 默认情况下都是使用 `defer` 方法获取 `pool` 内的连接资源，并在协程退出时自动归还，在此情况下，在带来便利的同时，会造成不必要资源的浪费。

我们可以使用 `invoke` 方式，让 `ORM` 查询结束后马上归还资源，可以提高资源的利用率。

```php
<?php
$builder = new \EasySwoole\Mysqli\QueryBuilder();
$builder->raw('select * from user');
$result = FastDb::getInstance()->invoke(function (\EasySwoole\FastDb\Mysql\Connection $connection) use ($builder) {
    $connection->query($builder);
    return $connection->rawQuery("select * from user");
});
```

#### begin

启动事务。

```php
FastDb::getInstance()->begin();
```

#### commit

提交事务。

```php
FastDb::getInstance()->commit();
```

#### rollback

回滚事务。

```php
FastDb::getInstance()->rollback();
```

#### query

自定义 `SQL` 执行。

```php
$builder = new \EasySwoole\Mysqli\QueryBuilder();
$builder->raw("select * from user where id = ?", [1]);
FastDb::getInstance()->query($builder);
```

> 原生 `SQL` 表达式将会被当做字符串注入到查询中，因此你应该小心使用，避免创建 `SQL` 注入的漏洞。

#### rawQuery

自定义 `SQL` 执行。

```php
FastDb::getInstance()->rawQuery('select * from user where id = 1');
```

> 原生 `SQL` 表达式将会被当做字符串注入到查询中，因此你应该小心使用，避免创建 `SQL` 注入的漏洞。

#### currentConnection

获取当前所用的连接。

```php
FastDb::getInstance()->currentConnection();
```

#### reset

销毁所有连接池。

```php
FastDb::getInstance()->reset();
```

#### preConnect

用于预热连接池。

为了避免连接空档期突如其来的高并发，我们可以对数据库连接预热，也就是 `Worker` 进程启动的时候，提前准备好数据库连接。

对连接进行预热使用实例如下所示：

```php
<?php

namespace EasySwoole\EasySwoole;

use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\FastDb\FastDb;

class EasySwooleEvent implements Event
{
    public static function initialize()
    {
        date_default_timezone_set('Asia/Shanghai');

        $mysqlArrayConfig = Config::getInstance()->getConf('MYSQL');
        $config = new \EasySwoole\FastDb\Config($mysqlArrayConfig);
        FastDb::getInstance()->addDb($config);
    }

    public static function mainServerCreate(EventRegister $register)
    {
        $register->add($register::onWorkerStart, function () {
            // 连接预热
            FastDb::getInstance()->preConnect();
        });
    }
}
```

#### isInTransaction

当前连接是否处于事务中。

```php
FastDb::getInstance()->isInTransaction();
```

#### getConfig

根据连接池名称获取当前连接池配置。

```php
FastDb::getInstance()->getConfig();
FastDb::getInstance()->getConfig('read');
```

### 事务操作

使用事务处理的话，需要数据库引擎支持事务处理。比如 `MySQL` 的 `MyISAM` 不支持事务处理，需要使用 `InnoDB` 引擎。

手动控制事务逻辑，如：

```php
<?php
try {
    // 启动事务
    FastDb::getInstance()->begin();
    $user = User::findRecord(1000);
    $user->delete();
    // 提交事务
    FastDb::getInstance()->commit();
} catch (\Throwable $throwable) {
    // 回滚事务
    FastDb::getInstance()->rollback();
}

// 或者使用 `invoke` 方法
FastDb::getInstance()->invoke(function (\EasySwoole\FastDb\Mysql\Connection $connection) {
    try {
        // 启动事务
        FastDb::getInstance()->begin($connection);
        $user = User::findRecord(1000);
        $user->delete();
        // 提交事务
        FastDb::getInstance()->commit($connection);
    } catch (\Throwable $throwable) {
        // 回滚事务
        FastDb::getInstance()->rollback($connection);
    }

    return true;
});
```

> 注意在事务操作的时候，确保你的数据库连接是同一个。确保在同一个协程环境下执行事务操作。

### 监听 SQL

如果你想对数据库执行的任何 `SQL` 操作进行监听，可以在注册连接池时设置 `onQuery` 回调函数，使用如下方法：

```php
<?php
$config = new \EasySwoole\FastDb\Config();
$config->setHost('127.0.0.1');
$config->setUser('easyswoole');
$config->setPassword('');
$config->setDatabase('easyswoole');
$config->setName('default');
FastDb::getInstance()->addDb($config);


// 设置 onQuery 回调函数
FastDb::getInstance()->setOnQuery(function (\asySwoole\FastDb\Mysql\QueryResult $queryResult) {
   // 打印 sql
    if ($queryResult->getQueryBuilder()) {
        echo $queryResult->getQueryBuilder()->getLastQuery() . "\n";
    } else {
        echo $queryResult->getRawSql() . "\n";
    }
});
```

### 存储过程

如果我们定义了一个数据库存储过程 `sp_query`，可以使用下面的方式调用：

```php
$result = FastDb::getInstance()->rawQuery('call sp_query(8)');
```

## 组件使用常见问题

### 1.Method Swoole\Coroutine\MySQL::__construct() is deprecated

如果在运行过程中出现类似 `PHP Deprecated: Method Swoole\Coroutine\MySQL::__construct() is deprecated in /demo/vendor/easyswoole/mysqli/src/Client.php on line 160` 这样的警告，请修改连接时使用的配置中的 `useMysqli` 为 `true` 选项，即可解决这个告警。
