# fast-db

以Php8注解的方式来定义数据库对象映射。

## 链接注册
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

## 配置项解析

``` EasySwoole\FastDb\Config ``` 继承自 ```\EasySwoole\Pool\Config``` ，因此ORM
具备连接池的特性。
