# fast-db

以Php8注解的方式来定义数据库对象映射。

### 链接注册
```php
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;

$config = new Config(
    [
        "host"=>"127.0.0.1",
        "user"=>"root",
        "password"=>"password",
        "port"=>3306,
        "database"=>"test"
    ]
);

FastDb::getInstance()->addDb($config);
```

> 如需注册多个链接，请在配置项中加入 name 属性用于区分链接。

### 配置项解析

``` EasySwoole\FastDb\Config ``` 继承自 ```\EasySwoole\Pool\Config``` ，因此ORM
具备连接池的特性。

- autoPing
- intervalCheckTime
- maxIdleTime
- maxObjectNum
- minObjectNum

## 实体定义
### 实体规范

一、任何实体都必须继承 ```EasySwoole\FastDb\Entity``` 并实现```tableName()```
方法，该方法用于返回该实体表的表面。

二、任何实体都必须具有一个唯一主键，作为某个实体对象的唯一id.一般建议为int自增id.

三、对象的属性，也就是实体表对应的字段，请用```#[Property]```进行标记。

### 示例

有个表名为```student```的数据表，主要结构如下：
```sql
CREATE TABLE `student` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(45) COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

```

则对应的实体如下：

```php
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

class Student extends Entity
{
    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public string $name;

    function tableName(): string
    {
        return "student";
    }
}
```

## 基础方法

### getOne

### data

### toArray

### update

### delete

### insert

### all

### page

### chunk

### whereCall

### fields

### sum

### count

## 实体关联

### 一对一

### 一对多

### 高级关联





