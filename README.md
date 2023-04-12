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

首先我们在 `EasySwoole` 框架的 `EasySwooleEvent` 事件（即框架根目录的 `EasySwooleEvent.php` 文件中）的 `initialize` 方法 或 `mainServerCreate`
方法中进行注册连接，如下所示

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
            'host'              => '127.0.0.1',  // 设置 数据库 host
            'user'              => 'easyswoole', // 设置 数据库 用户名
            'password'          => 'easyswoole', // 设置 数据库 用户密码
            'database'          => 'easyswoole', // 设置 数据库库名
            'port'              => 3306,         // 设置 数据库 端口
            'timeout'           => 5,            // 设置 数据库连接超时时间
            'charset'           => 'utf8',       // 设置 数据库字符编码，默认为 utf8
            'autoPing'          => 5,            // 设置 自动 ping 客户端链接的间隔
            'name'              => 'default',    // 设置 连接池名称，默认为 default
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
    }

    public static function mainServerCreate(EventRegister $register)
    {
        // 注册方式2：在 mainServerCreate 方法中注册连接
//        $config = new \EasySwoole\FastDb\Config([
//            'host'              => '127.0.0.1',  // 设置 数据库 host
//            'user'              => 'easyswoole', // 设置 数据库 用户名
//            'password'          => 'easyswoole', // 设置 数据库 用户密码
//            'database'          => 'easyswoole', // 设置 数据库库名
//            'port'              => 3306,         // 设置 数据库 端口
//            'timeout'           => 5,            // 设置 数据库连接超时时间
//            'charset'           => 'utf8',       // 设置 数据库字符编码，默认为 utf8
//            'autoPing'          => 5,            // 设置 自动 ping 客户端链接的间隔
//            'name'              => 'default',    // 设置 连接池名称，默认为 default
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

> 如需注册多个链接，请在配置项中加入 name 属性用于区分链接。

### 配置项解析

``` EasySwoole\FastDb\Config ``` 继承自 ```\EasySwoole\Pool\Config``` ，因此ORM 具备连接池的特性。

- autoPing
- intervalCheckTime
- maxIdleTime
- maxObjectNum
- minObjectNum

## 基于实体类的简单使用

### 定义实体类

#### 定义实体规范

1. 任何实体都必须继承 ```EasySwoole\FastDb\Entity``` 并实现```tableName()```
   方法，该方法用于返回该实体表的表面。

2. 任何实体都必须具有一个唯一主键，作为某个实体对象的唯一id.一般建议为int自增id.

3. 对象的属性，也就是实体表对应的字段，请用 ```#[Property]``` 进行标记。

#### 示例

例如，我们有个表名为 ```student``` 的数据表，表结构如下：

```sql
CREATE TABLE `student`
(
   `no`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'student number',
   `name`        varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'student name',
   `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT 'create_time',
   `update_time` int unsigned NOT NULL DEFAULT '0' COMMENT 'update_time',
   `is_deleted`  tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'whether it has been deleted: 1=deleted 0=not deleted',
   PRIMARY KEY (`no`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

则它对应的实体类如下：

```php
namespace App\Entity;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

class Student extends Entity
{
    #[Property(isPrimaryKey: true)]
    public int $no;
    
    #[Property]
    public string $name;
    
    #[Property]
    public int $create_time;
    
    #[Property]
    public int $update_time;
    
    #[Property]
    public int $is_deleted;

    function tableName(): string
    {
        return "student";
    }
}
```

### 添加数据

`insert()` 方法，返回值为 `bool` 类型的值，返回值为 `true` 表示添加成功，返回值为 `false` 表示添加失败。

#### 简单 insert

```php
<?php
/**
 * Created by PhpStorm.
 * User: EasySwoole-XueSi <1592328848@qq.com>
 * Date: 2023/3/15
 * Time: 9:42 下午
 */
declare(strict_types=1);

namespace App\HttpController;

use App\Entity\Student;
use EasySwoole\Http\AbstractInterface\Controller;

class TestEntity extends Controller
{
    public function testAdd()
    {
        $entity = new Student([
            'name' => 'XueSi'
        ]);
        $result = $entity->insert(); // 返回 bool 类型值，true=新增成功 false=新增失败
        // 相当于 sql: INSERT  INTO `student` (`name`)  VALUES ('XueSi')
        
        $entity1 = new Student();
        $entity->name = 'XueSi';
        $result = $entity->insert(); // 返回 bool 类型值，true=新增成功 false=新增失败
        // 相当于 sql: INSERT  INTO `student` (`name`)  VALUES ('XueSi')
        
        $this->writeJson(200, [
            'func'   => __METHOD__,
            'result' => $result
        ]);
    }
}
```

#### 复杂 insert

##### 使用 OnInsert 注解

先修改 `Student` 实体类，添加 `OnInsert` 注解 和 `onInsert` 方法，`onInsert` 方法用于对添加前的数据做一些处理。

Student.php

```php
<?php
/**
 * Created by PhpStorm.
 * User: hlh XueSi <1592328848@qq.com>
 * Date: 2023/3/15
 * Time: 9:41 下午
 */
declare(strict_types=1);

namespace App\Entity;

use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

#[OnInsert('onInsert')]
class Student extends Entity
{
    #[Property(isPrimaryKey: true)]
    public int $no;

    #[Property]
    public string $name;

    #[Property]
    public int $create_time;

    #[Property]
    public int $update_time;

    #[Property]
    public int $is_deleted;

    function tableName(): string
    {
        return "student";
    }

    public function onInsert()
    {
        if (empty($this->create_time)) {
            $this->create_time = time();
        }
        if (empty($this->update_time)) {
            $this->update_time = time();
        }
        if (empty($this->is_deleted)) {
            $this->is_deleted = 0;
        }
    }
}
```

然后再次执行插入

```php
<?php
/**
 * Created by PhpStorm.
 * User: EasySwoole-XueSi <1592328848@qq.com>
 * Date: 2023/3/15
 * Time: 9:42 下午
 */
declare(strict_types=1);

namespace App\HttpController;

use App\Entity\Student;
use EasySwoole\Http\AbstractInterface\Controller;

class TestEntity extends Controller
{
    public function testAdd()
    {
        $entity = new Student([
            'name' => 'XueSi'
        ]);
        $result = $entity->insert(); // 返回 bool 类型值，true=新增成功 false=新增失败
        // 相当于 sql: INSERT  INTO `student` (`name`, `create_time`, `update_time`, `is_deleted`)  VALUES ('XueSi', 1678897244, 1678897244, 0)
        $this->writeJson(200, [
            'func'   => __METHOD__,
            'result' => $result
        ]);
    }
}
```

##### ON DUPLICATE KEY UPDATE

```php
<?php
/**
 * Created by PhpStorm.
 * User: EasySwoole-XueSi <1592328848@qq.com>
 * Date: 2023/3/15
 * Time: 9:42 下午
 */
declare(strict_types=1);

namespace App\HttpController;

use App\Entity\Student;
use EasySwoole\Http\AbstractInterface\Controller;

class TestEntity extends Controller
{
    public function testAdd()
    {
        $entity = new Student(['name' => 'XueSi']);
        $result = $entity->insert(['name']);
        // 相当于 sql: INSERT  INTO `student` (`name`, `create_time`, `update_time`, `is_deleted`)  VALUES ('XueSi', 1678898775, 1678898775, 0) ON DUPLICATE KEY UPDATE `name` = 'XueSi'
        
        $entity = new Student(['name' => 'XueSi']);
        $result = $entity->insert(['name', 'no' => 2]);
        // 相当于 sql: INSERT  INTO `student` (`name`, `create_time`, `update_time`, `is_deleted`)  VALUES ('XueSi', 1678898805, 1678898805, 0) ON DUPLICATE KEY UPDATE `no` = 2, `name` = 'XueSi'
    }
}
```

### 更新数据

`update()` 方法，返回值为 `int` 类型的值，值表示更新影响的行数。

```php
<?php
// 1. 根据 主键id 更新数据1
$entity1 = new Student([
   'no' => 1, // 主键id
]);
$result1 = $entity1->update(['name' => 'XueSi-1']); // 返回更新影响的记录条数 int
// sql: UPDATE `student` SET `name` = 'XueSi-1' WHERE  `no` = 1

// 2. 根据 主键id 更新数据2
$entity2 = new Student();
$result2 = $entity2->whereCall(function (QueryBuilder $queryBuilder) {
   $queryBuilder->where('no', 1);
})->update(['name' => 'XueSi-2']); // 返回更新影响的记录条数 int
// sql: UPDATE `student` SET `name` = 'XueSi-2' WHERE  `no` = 1

// 3. 根据 条件 更新数据
$entity3 = new Student();
$result3 = $entity3->whereCall(function (QueryBuilder $queryBuilder) {
   $queryBuilder->where('name', 'XueSi');
})->update(['name' => 'XueSi-2']); // 返回更新影响的记录条数 int
// sql: UPDATE `student` SET `name` = 'XueSi-2' WHERE  `name` = 'XueSi'
```

### 删除数据

`delete()` 方法，返回值为 `int` 类型 / `bool` 的值，

- 删除成功时返回值为 `int` 类型的值，表示删除的行数
- 删除失败时返回值为 `false`

```php
<?php
/**
 * Created by PhpStorm.
 * User: EasySwoole-XueSi <1592328848@qq.com>
 * Date: 2023/3/15
 * Time: 9:42 下午
 */
declare(strict_types=1);

namespace App\HttpController;

use App\Entity\Student;
use EasySwoole\Http\AbstractInterface\Controller;

class TestEntity extends Controller
{
    public function testDelete()
    {
        // 1. 根据 主键id 删除数据
        $entity1 = new Student([
            'no' => 1, // 主键id
        ]);
        $result1 = $entity1->delete(); // 返回 bool/int 类型值，删除成功返回影响的行数，删除失败返回false
        // sql: DELETE FROM `student` WHERE  `no` = 1

        // 2. 根据 条件 删除数据
        $entity2 = new Student();
        $result2 = $entity2->whereCall(function (QueryBuilder $queryBuilder) {
            $queryBuilder->where('no', 2);
        })->delete(); // 返回 bool 类型值，true=删除成功 false=删除失败
        // sql: DELETE FROM `student` WHERE  `no` = 2

        $this->writeJson(200, [
            'func'    => __METHOD__,
            'result1' => $result1,
            'result2' => $result2
        ]);
    }
}
```

### 查询数据

#### 获取单个数据

获取单个数据的方法包括：

```php
<?php
// 取出主键为1的数据
$entity = new Student();
$student = $entity->getOne(3);
//sql: SELECT  * FROM `student` WHERE  `no` = 3  LIMIT 1
var_dump($student); // NULL or object(App\Entity\Student)#133 (12){}
echo $student->name;

// 使用数组查询
$entity = new Student();
$student = $entity->getOne(['name' => 'easyswoole']);
//sql: SELECT  * FROM `student` WHERE  `name` = 'easyswoole'  LIMIT 1
var_dump($student); // NULL or object(App\Entity\Student)#133 (12){}

// 使用闭包查询
$entity = new Student();
$student = $entity->getOne(function (QueryBuilder $queryBuilder) {
   $queryBuilder->where('name', 'easyswoole');
});
//sql: SELECT  * FROM `student` WHERE  `name` = 'easyswoole'  LIMIT 1
var_dump($student); // NULL or object(App\Entity\Student)#133 (12){}
```

> `getOne` 方法返回的是当前实体类的对象实例，可以使用实体类的方法。

#### 获取多个数据

取出多个数据：

```php
// 获取多个数据 不使用条件查询
$listResultObject = (new Student())->all();
// sql: SELECT  * FROM `student`
// var_dump($listResultObject); // 返回结果：\EasySwoole\FastDb\Beans\ListResult 类的对象
// 将结果对象转换为实体对象数组
/** @var \App\Entity\Student[] $objectArray */
$objectArray = $listResultObject->toArray();
// $objectArray = $listResultObject->list(); // 和 toArray() 方法等价，将结果对象转换为实体对象数组
foreach ($objectArray as $studentEntityObject) {
   echo $studentEntityObject->name; // 返回结果："EasySwoole"
}

// 获取多个数据 使用条件查询(闭包条件)
$listResultObject = (new Student())->whereCall(function (QueryBuilder $queryBuilder) {
   $queryBuilder->where('no', [2, 3], 'IN')->where('name', 'EasySwoole');
})->all(); // 返回结果：\EasySwoole\FastDb\Beans\ListResult 类的对象
// sql: SELECT  * FROM `student` WHERE  `no` IN ( 2, 3 ) AND `name` = 'EasySwoole'
foreach ($listResultObject as $studentEntityObject) {
   echo $studentEntityObject->name; // "EasySwoole"
}
```

#### 查询结果转换

```php
// 查询结果转换成功普通数组
$listResultObject = (new Student())->fields(['*'], true)->all();
// sql: SELECT  * FROM `student`
// var_dump($listResultObject); // 返回结果：\EasySwoole\FastDb\Beans\ListResult 类的对象
// 将结果对象转换为实体对象数组
/** @var array $studentList */
$studentList = $listResultObject->toArray();
// $studentList = $listResultObject->list(); // 和 toArray() 方法等价，将结果对象转换为普数组
foreach ($studentList as $student) {
   echo $student['name'] . "\n"; // 返回结果："EasySwoole"
}
```

#### 数据分批处理 chunk

todo::

#### 聚合查询

| 方法 | 说明  |
| :-------- | :----- |
| count | 统计数量 |
| max   | 待实现|

##### count

```php
$studentEntity = new Student();
$counts = $studentEntity->count(); // sql: SELECT  count(*) as count FROM `student`
var_dump($counts);

$studentEntity = new Student();
$counts = $studentEntity->whereCall(function (QueryBuilder $queryBuilder) {
   $queryBuilder->where('no', 10, '>');
})->count(); // sql: SELECT  count(*) as count FROM `student` WHERE  `no` > 10
var_dump($counts);
```

##### sum

todo::

#### 分页查询 page

- 方法说明：

```php 
function page(int $page, bool $withTotalCount = false, int $pageSize = 10): static
```

- 使用示例：

```php
// 使用条件的分页查询 不进行汇总 withTotalCount=false
// 查询 第1页 每页10条 page=1 pageSize=10
$studentEntity = new Student();
$resultObject = $studentEntity->page(1, false, 10)
   ->whereCall(function (QueryBuilder $queryBuilder) {
       $queryBuilder->where('no', 3, '>');
   })
   ->all();
// sql: SELECT  * FROM `student` WHERE  `no` > 3  LIMIT 0, 10
foreach ($resultObject as $studentEntity) {
   echo $studentEntity->name . "\n"; // "EasySwoole"
}

// 使用条件的分页查询 进行汇总 withTotalCount=true
// 查询 第1页 每页10条 page=1 pageSize=10
$studentEntity = new Student();
$resultObject = $studentEntity->page(1, true, 10)
   ->whereCall(function (QueryBuilder $queryBuilder) {
       $queryBuilder->where('no', 3, '>');
   })
   ->all();
// sql: SELECT  * FROM `student` WHERE  `no` > 3  LIMIT 0, 10
$total = $resultObject->totalCount(); // 汇总数量
$objectList = $resultObject->list();
foreach ($objectList as $studentEntity) {
   echo $studentEntity->name . "\n"; // "EasySwoole"
}
echo $total . "\n";
```

#### 关联查询

##### 一对一关联

todo::

##### 一对多关联

todo::

### 实体事件注解

#### 适用场景

实体事件类似于 `ThinkPHP` 框架模型的模型事件，可用于在数据写入数据库之前做一些预处理操作。

实体事件是指在进行实体的写入操作的时候触发的操作行为，包括调用实体对象的 `insert`、`delete`、`update` 方法以及对实体对象初始化时触发。

实体类支持 `OnInitialize`、`OnInsert`、`OnDelete`、`OnUpdate` 事件。

| 事件行为注解 | 描述  |
| :-------- | :----- |
| OnInitialize | 实体被实例化时触发 |
| OnInsert | 新增前 |
| OnDelete | 删除前 |
| OnUpdate| 更新前 |

#### 使用示例

- 声明事件注解

在实体类中可以通过注解及定义类方法来实现事件注解的声明，如下所示：

```php
<?php
/**
 * Created by PhpStorm.
 * User: EasySwoole-XueSi <1592328848@qq.com>
 * Date: 2023/3/15
 * Time: 9:41 下午
 */
declare(strict_types=1);

namespace App\Entity;

use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

#[OnInitialize('onInitialize')]
#[OnInsert('onInsert')]
#[OnDelete('onDelete')]
#[OnUpdate('onUpdate')]
class Student extends Entity
{
    #[Property(isPrimaryKey: true)]
    public int $no;

    #[Property]
    public string $name;

    #[Property]
    public int $create_time;

    #[Property]
    public int $update_time;

    #[Property]
    public int $is_deleted;

    function tableName(): string
    {
        return "student";
    }

    public function onInitialize()
    {
        // todo::
    }

    public function onInsert()
    {
        if ($this->no !== 1) {
            return false;
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
$entity = new Student(['name' => 'EasySwoole', 'no' => 1000]);
$result = $entity->insert();
var_dump($result); // false，返回 false，表示 insert 失败。
```

## 数据库管理类 FastDb

### 使用 QueryBuilder 进行查询

todo::

### 事务操作

使用事务处理的话，需要数据库引擎支持事务处理。比如 `MySQL` 的 `MyISAM` 不支持事务处理，需要使用 `InnoDB` 引擎。

> 注意在事务操作的时候，确保你的数据库连接是相同的。

#### 使用示例1:

```php
<?php
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;

try {
    // 启动事务
    FastDb::getInstance()->begin();
    $entity = new Student();

    // sql: SELECT  1 FROM `student` WHERE  `no` = 4  LIMIT 1
    $entity->getOne(function (QueryBuilder $queryBuilder) {
        $queryBuilder->where('no', 4);
    });

    // sql: DELETE FROM `student` WHERE  `no` = 5
    $entity->whereCall(function (QueryBuilder $queryBuilder) {
        $queryBuilder->where('no', 4);
    })->delete();
    // 提交事务
    FastDb::getInstance()->commit();
} catch (\Throwable $throwable) {
    // 回滚事务
    FastDb::getInstance()->rollback();
}
```

#### 使用示例2:

```php
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;

FastDb::getInstance()->invoke(function (Connection $connection) {
    try {
        // 启动事务
        FastDb::getInstance()->begin($connection);

        // sql: SELECT  1 FROM `student` WHERE  `no` = 5  LIMIT 1
        $connection->queryBuilder()
            ->where('no', 5)
            ->getOne('student', 1);
        echo $connection->queryBuilder()->getLastQuery() . "\n";
        $connection->execBuilder();

        // sql: DELETE FROM `student` WHERE  `no` = 5
        $connection->queryBuilder()
            ->where('no', 5)
            ->delete('student');
        echo $connection->queryBuilder()->getLastQuery() . "\n";
        $connection->execBuilder();

        // 提交事务
        FastDb::getInstance()->commit($connection);
    } catch (\Throwable $throwable) {
        // 回滚事务
        FastDb::getInstance()->rollback($connection);
    }
});
```


