<?php

namespace EasySwoole\FastDb;

use EasySwoole\FastDb\Attributes\Beans\Json;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Page;
use EasySwoole\FastDb\Beans\SmartListResult;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\Mysqli\QueryBuilder;

abstract class Entity implements \JsonSerializable
{
    /**
     * @var array
     * 用于存储对象成员，和做老数据diff
     */
    private array $properties = [];
    private array $relateValues = [];

    private array $propertyRelates = [];

    private string $primaryKey;

    private ?array $fields = null;

    private ?Page $page = null;

    private ?array $orderBy = null;

    private $whereCall = null;

    final function __construct(?array $data = null,bool $realData = false){
        $info = ReflectionCache::getInstance()->entityReflection(static::class);
        /**
         * @var  $key
         * @var Property $property
         */
        foreach ($info->getProperties() as $key => $property){
            $ret = $this->convertJson($key,$property->getDefaultValue());
            if($ret){
                $this->properties[$key] = $ret->jsonSerialize();
                $this->{$key} = $ret;
            }else{
                $this->properties[$key] = $property->getDefaultValue();
                $default = $property->getDefaultValue();
                if($default !== null){
                    $this->{$key} = $property->getDefaultValue();
                }else if($property->isAllowNull()){
                    $this->{$key} = null;
                }
            }
        }
        //避免出现  property must not be accessed before initialization
        $this->primaryKey = $info->getPrimaryKey();
        $this->propertyRelates = $info->getMethodRelates();
        if(!empty($data)){
            $this->data($data,$realData);
        }
        if($info->onInitialize()){
            if(is_callable($info->onInitialize()->callback)){
                call_user_func($info->onInitialize()->callback,$this);
            }else{
                if(method_exists($this,$info->onInitialize()->callback)){
                    $call = $info->onInitialize()->callback;
                    $this->$call();
                }else{
                    throw new RuntimeError("{$info->onInitialize()->callback} no a method of class ".static::class);
                }
            }
        }
    }

    abstract function tableName():string;

    function data(array $data,bool $realData = false):Entity
    {
        foreach ($this->properties as $property => $val){
            if(array_key_exists($property,$data)){
                $val = $data[$property];
                $ret = $this->convertJson($property,$val);
                if(!$ret){
                    $this->{$property} = $val;
                }
                if($realData){
                    if($ret instanceof Json){
                        $this->properties[$property] = $ret->jsonSerialize();
                    }else{
                        $this->properties[$property] = $val;
                    }
                }
            }
        }
        return $this;
    }

    private function convertJson(string $property,$propertyValue):?Json
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);

        $allowNull = $ref->getProperty($property)->isAllowNull();

        if(empty($propertyValue) && $allowNull){
            return null;
        }
        /** @var Json $jsonInstance */
        $json = $ref->getPropertyConvertJson($property);
        if(!$json){
            return null;
        }
        $jsonInstance = new $json->className();
        $this->{$property} = $jsonInstance;
        $class = static::class;
        if(is_array($propertyValue)){
            $jsonInstance->restore($propertyValue);
        }else if(is_string($propertyValue)){
            $json = json_decode($propertyValue,true);
            if(!is_array($json)){
                throw new RuntimeError("data for property {$property} at class {$class} not a json format");
            }
            $jsonInstance->restore($json);
        }else{
            if($propertyValue !== null){
                throw new RuntimeError("data for property {$property} at class {$class} not a json format");
            }
        }
        return $jsonInstance;
    }

    function whereCall(?callable $call):static
    {
        $this->whereCall = $call;
        return $this;
    }

    function orderBy(string $colName,string  $orderByDirection = "DESC"):static
    {
        if(!is_array($this->orderBy)){
            $this->orderBy = [];
        }
        $this->orderBy[$colName] = $orderByDirection;
        return $this;
    }


    static function getOne(callable|string|int|array $whereCall):?static
    {
        $mode = new static();
        $queryBuilder = new QueryBuilder();
        if(is_callable($whereCall)){
            call_user_func($whereCall,$queryBuilder);
        }else if(is_array($whereCall)){
            foreach ($whereCall as $key => $value){
                $queryBuilder->where($key,$value);
            }
        }else{
            $primaryKey = ReflectionCache::getInstance()
                ->entityReflection(static::class)->getPrimaryKey();
            $queryBuilder->where($primaryKey,$whereCall);
        }
        $queryBuilder->getOne($mode->tableName());
        $info = FastDb::getInstance()->query($queryBuilder)->getResult();
        if(!empty($info)){
            $mode->data($info[0],true);
            return $mode;
        }else{
            return null;
        }
    }

    /**
     * fields 函数为一次性使用。
     * @param array|null $fields
     * @param bool $returnAsArray
     * @return $this
     */
    function fields(?array $fields,bool $returnAsArray = false):static
    {
        if($fields == null){
            $this->fields = null;
        }else{
            $this->fields = [
                'fields'=>$fields,
                'returnAsArray'=>$returnAsArray
            ];
        }
        return $this;
    }

    function page(int $page,bool $withTotalCount = false,int $pageSize = 10):static
    {
        $this->page = new Page($page,$withTotalCount,$pageSize);
        return $this;
    }

    function find(?array $data = null):static|array|null
    {
        $query = new QueryBuilder();
        if($data){
            foreach ($data as $key => $item){
                $query->where($key,$item);
            }
        }

        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }
        $this->whereCall = null;

        $fields = null;
        $returnAsArray = false;
        if(!empty($this->fields['fields'])){
            $fields = $this->fields['fields'];
            $returnAsArray = $this->fields['returnAsArray'];
        }
        $this->fields = null;
        $this->page = null;

        $query->getOne($this->tableName(),$fields);

        $info = FastDb::getInstance()->query($query)->getResult();
        if(!empty($info)){
            if($returnAsArray){
                return $info[0];
            }
            return new static($info[0],true);
        }else{
            return null;
        }
    }

    function all(?string $tableName = null):ListResult
    {
        $query = new QueryBuilder();
        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }
        $this->whereCall = null;

        $total = null;
        if($this->page != null){
            $query->limit(...$this->page->toLimitArray());
            if($this->page->isWithTotalCount()){
                $query->withTotalCount();
            }
        }

        $fields = null;
        $returnAsArray = false;
        if(!empty($this->fields['fields'])){
            $fields = $this->fields['fields'];
            $returnAsArray = $this->fields['returnAsArray'];
        }
        $this->fields = null;

        if($tableName == null){
            $tableName = $this->tableName();
        }

        if(is_array($this->orderBy)){
            foreach ($this->orderBy as $col => $op){
                $query->orderBy($col,$op);
            }
        }
        $this->orderBy = null;

        $query->get($tableName,null,$fields);

        $ret = FastDb::getInstance()->query($query);
        if($this->page && $this->page->isWithTotalCount()){
            $info = FastDb::getInstance()->rawQuery('SELECT FOUND_ROWS() as count')->getResult();
            if(isset($info[0]['count'])){
                $total = $info[0]['count'];
            }
        }
        $this->page = null;

        $list = [];
        if($returnAsArray){
            foreach ($ret->getResult() as $item){
                $list[] = $item;
            }
        }else{
            foreach ($ret->getResult() as $item){
                $list[] = new static($item);
            }
        }

        return new ListResult($list,$total);
    }

    function chunk(callable $func,$pageSize = 10,?string $tableName = null):void
    {
        $cache = $this->fields;
        $page = 1;
        //因为all会重置whereCall
        $whereCall = $this->whereCall;
        $orderBy = $this->orderBy;
        while (true){
            $this->fields = $cache;
            $list = $this->page($page,false,$pageSize)->all($tableName);
            $this->whereCall = $whereCall;
            $this->orderBy = $orderBy;
            foreach ($list as $item){
                call_user_func($func,$item);
            }
            if(count($list) == $pageSize){
                $page++;
            }else{
                break;
            }
        }
        $this->whereCall = null;
        $this->fields = null;
    }

    function insert(?array $updateDuplicateCols = null,bool $reSync = false):bool
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onInsert()){
            if(is_callable($ref->onInsert()->callback)){
                $ret = call_user_func($ref->onInsert()->callback,$this);
            }else{
                if(method_exists($this,$ref->onInsert()->callback)){
                    $call = $ref->onInsert()->callback;
                    $ret = $this->$call();
                }else{
                    throw new RuntimeError("{$ref->onInsert()->callback} no a method of class ".static::class);
                }
            }
            if($ret === false){
                return false;
            }
        }
        //插入的时候，null值一般无意义，default值在数据库层做。
        $data = $this->toArray(true);
        $jsonList = array_keys($ref->getAllPropertyConvertJson());
        foreach ($jsonList as $key){
            if(isset($data[$key])){
                $data[$key] = $this->convertJson($key,$data[$key]);
                if($data[$key]){
                    $data[$key] =  $data[$key]->__toString();
                }
            }
        }
        $query = new QueryBuilder();
        if($updateDuplicateCols !== null){
            if(!empty($updateDuplicateCols)){
                $query->onDuplicate($updateDuplicateCols);
            }else{
                $query->onDuplicate(array_keys($data));
            }
        }
        
        $query->insert($this->tableName(),$data);
        $ret = FastDb::getInstance()->query($query);
        if($ret->getResult()){
            $id = $ret->getConnection()->mysqlClient()->insert_id;
            //onDuplicate的时候，如果没有主键更改，则insert_id为0
            //或者主键为非int的时候，也是0
            if($id > 0){
                $this->{$this->primaryKey} = $id;
            }
            if($reSync){
                //当数据库有些字段设置了脚本或者是自动创建，需要重新get一次同步
                $query = new QueryBuilder();
                $query->where($this->primaryKey,$this->{$this->primaryKey})->getOne($this->tableName());
                $info = FastDb::getInstance()->query($query);
                $this->data($info->getResult()[0],true);
            }else{
                //同步properties
                $this->data($data,true);
            }

            return true;
        }else{
            return false;
        }
    }
    /*
     * 当实例为非从数据库读取创建，
     * 需要把某个数据重置为null的时候，请使用data参数。
     * 也就是 (new model())->update(["a"=>null])
     */

    function update(?array $data = null)
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onUpdate()){
            if(is_callable($ref->onUpdate()->callback)){
                $ret = call_user_func($ref->onUpdate()->callback,$this);
            }else{
                if(method_exists($this,$ref->onUpdate()->callback)){
                    $call = $ref->onUpdate()->callback;
                    $ret = $this->$call();
                }else{
                    throw new RuntimeError("{$ref->onUpdate()->callback} no a method of class ".static::class);
                }
            }
            if($ret === false){
                return false;
            }
        }
        $finalData = [];
        if($data != null){
            foreach ($data as $key => $datum){
                if(array_key_exists($key,$this->properties)){
                    if($ref->getPropertyConvertJson($key)){
                        $temp = $this->convertJson($key,$datum);
                        if($temp){
                            if($this->properties[$key] != $temp->jsonSerialize()){
                                $finalData[$key] = $temp->__toString();
                            }
                        }else{
                            $finalData[$key] = null;
                        }
                        continue;
                    }
                    $finalData[$key] = $datum;
                }
            }
        }else{
            foreach ($this->properties as $key => $propertyVal){
                $temp = new \ReflectionProperty(static::class,$key);
                if($temp->isInitialized($this)){
                    if($this->{$key} instanceof Json){
                        $compare = $this->{$key}->jsonSerialize();
                        if($propertyVal !== $compare){
                            $finalData[$key] = $this->{$key}->__toString();
                        }
                    }else{
                        $compare = $this->{$key};
                        if($propertyVal !== $compare){
                            $finalData[$key] = $compare;
                        }
                    }
                }
            }
        }


        if(!empty($this->fields['fields'])){
            $fields = $this->fields['fields'];
            foreach ($finalData as $key => $datum){
                if(!key_exists($key,$fields)){
                    unset($finalData[$key]);
                }
            }
        }


        if(empty($finalData)){
            return 0;
        }


        $jsonList = array_keys($ref->getAllPropertyConvertJson());
        foreach ($jsonList as $key){
            if(isset($finalData[$key]) && $finalData[$key] instanceof Json){
                $finalData[$key] = $finalData[$key]->__toString();
            }
        }

        $query = new QueryBuilder();

        if($this->whereCall == null && !isset($this->{$this->primaryKey}) && !isset($finalData[$this->primaryKey])){
            throw new RuntimeError("can not update data without primaryKey or whereCall set in ".static::class);
        }

        $singleRecord = false;
        //当主键有值的时候，不执行wherecall，因为pk可以确定唯一记录，再wherecall无意义
        if(isset($this->{$this->primaryKey})){
            $query->where($this->primaryKey,$this->{$this->primaryKey});
            $singleRecord = true;
        }else if(isset($finalData[$this->primaryKey])){
            $query->where($this->primaryKey,$finalData[$this->primaryKey]);
            $singleRecord = true;
        }
        //update 的whereCall为单独检测执行，用于版本模式update
        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }
        $this->whereCall = null;

        $query->update($this->tableName(),$finalData);

        $queryResult = FastDb::getInstance()->query($query);
        $affectRows = $queryResult->getConnection()->mysqlClient()->affected_rows;

        if($singleRecord && $affectRows == 1){
            $query = new QueryBuilder();
            if(!isset($this->{$this->primaryKey})){
                $pkval = $finalData[$this->primaryKey];
            }else{
                $pkval = $this->{$this->primaryKey};
            }
            $query->where($this->primaryKey,$pkval)->getOne($this->tableName());
            $info = FastDb::getInstance()->query($query);
            $this->data($info->getResult()[0]);
        }
        //affect rows num
        return $affectRows;
    }

    function delete()
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onDelete()){
            if(is_callable($ref->onDelete()->callback)){
                $ret = call_user_func($ref->onDelete()->callback,$this);
            }else{
                if(method_exists($this,$ref->onDelete()->callback)){
                    $call = $ref->onDelete()->callback;
                    $ret = $this->$call();
                }else{
                    throw new RuntimeError("{$ref->onDelete()->callback} no a method of class ".static::class);
                }
            }

            if($ret === false){
                return false;
            }
        }

        if($this->whereCall == null && !isset($this->{$this->primaryKey})){
            throw new RuntimeError("can not delete data without primaryKey or whereCall set in ".static::class);
        }

        $query = new QueryBuilder();
        if(isset($this->{$this->primaryKey})){
            $query->where($this->primaryKey,$this->{$this->primaryKey});
        }else if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }
        $this->whereCall = null;

        $query->delete($this->tableName());

        $ret = FastDb::getInstance()->query($query);
        if($ret->getResult()){
            return $ret->getConnection()->mysqlClient()->affected_rows;
        }
        return false;
    }

    function toArray(bool $filterNull = false):array
    {
        $temp = [];
        foreach ($this->properties as $key => $property){
            if(isset($this->{$key})){
                if($this->{$key} instanceof Json){
                    $temp[$key] = $this->{$key}->jsonSerialize();
                }else{
                    $temp[$key] = $this->{$key};
                }
            }else{
                $temp[$key] = null;
            }
        }

        if(!empty($this->fields['fields'])){
            $fList = [];
            foreach ($this->fields['fields'] as $field){
                if(key_exists($field,$temp)){
                    $fList[$field] = $temp[$field];
                }
            }
            $temp = $fList;
        }

        $this->fields = null;

        if(!$filterNull){
            return $temp;
        }

        foreach ($temp as $key => $item){
            if($item === null){
                unset($temp[$key]);
            }
        }

        return $temp;
    }

    function sum(string|array $cols):array
    {
        if(!is_array($cols)){
            $cols = [$cols];
        }
        $str = "";
        while ($item = array_shift($cols)){
            $str .= "sum({$item}) as {$item}";
            if(!empty($cols)){
                $str .= " , ";
            }
        }

        $query = new QueryBuilder();
        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }
        $this->whereCall = null;

        $query->fields($str);
        $query->get($this->tableName());

        $ret = FastDb::getInstance()->query($query)->getResult();
        if(!empty($ret)){
            return FastDb::getInstance()->query($query)->getResult()[0]['count'];
        }
        return [];
    }

    function count():int
    {
        $query = new QueryBuilder();
        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }
        $this->whereCall = null;
        $query->get($this->tableName(),null,"count(*) as count");
        $ret = FastDb::getInstance()->query($query)->getResult();
        if(!empty($ret)){
            return FastDb::getInstance()->query($query)->getResult()[0]['count'];
        }
        return 0;
    }


    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    protected function relateOne(?Relate $relate = null)
    {
        $relate = $this->parseRelate($relate);

        if($relate->smartCreate && $relate->returnAsTargetEntity){
            throw new RuntimeError("return as array is not allow when smart create mode enable");
        }

        $relateKey = md5($relate->targetEntity.$relate->selfProperty.$relate->targetProperty);
        if($relate->allowCache && isset($this->relateValues[$relateKey])){
            return $this->relateValues[$relateKey];
        }
        /** @var Entity $temp */
        $temp = new $relate->targetEntity();

        $query = new QueryBuilder();
        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }

        $fields = null;
        $returnAsArray = false;
        if(!empty($this->fields['fields'])){
            $fields = $this->fields['fields'];
            $returnAsArray = $this->fields['returnAsArray'];
        }

        if($relate->smartCreate && $returnAsArray){
            throw new RuntimeError("return as array is not allow when smart create mode enable");
        }

        $this->fields = null;
        $this->whereCall = null;

        if(isset($this->{$relate->selfProperty})){
            $selfValue = $this->{$relate->selfProperty};
        }else{
            $selfValue = null;
        }

        $query->where($relate->targetProperty,$selfValue)
            ->getOne($temp->tableName(),$fields);
        $ret = FastDb::getInstance()->query($query);
        if(!empty($ret->getResult())){
            $return = $ret->getResult()[0];
            if($relate->returnAsTargetEntity && (!$returnAsArray)){
                $return = new $relate->targetEntity($return);
            }
            if($relate->allowCache){
                $this->relateValues[$relateKey] = $return;
            }
            return $return;
        }else{
            if($relate->smartCreate){
                $temp->{$relate->targetProperty} = $selfValue;
                return $temp;
            }
            return null;
        }
    }

    protected function relateMany(?Relate $relate = null):SmartListResult
    {
        $relate = $this->parseRelate($relate);
        $relateKey = md5($relate->targetEntity.$relate->selfProperty.$relate->targetProperty);

        if($relate->allowCache && isset($this->relateValues[$relateKey])){
            return $this->relateValues[$relateKey];
        }
        /** @var Entity $temp */
        $temp = new $relate->targetEntity();

        $query = new QueryBuilder();
        if(is_callable($this->whereCall)){
            call_user_func($this->whereCall,$query);
        }

        $fields = null;
        $returnAsArray = false;
        if(!empty($this->fields['fields'])){
            $fields = $this->fields['fields'];
            $returnAsArray = $this->fields['returnAsArray'];
        }

        if($this->page != null){
            $query->limit(...$this->page->toLimitArray());
            if($this->page->isWithTotalCount()){
                $query->withTotalCount();
            }
        }

        $this->fields = null;
        $this->whereCall = null;

        if(isset($this->{$relate->selfProperty})){
            $selfValue = $this->{$relate->selfProperty};
        }else{
            $selfValue = null;
        }

        $query->where($relate->targetProperty,$selfValue)
            ->get($temp->tableName(),null,$fields);
        $ret = FastDb::getInstance()->query($query);

        $total = null;
        if($this->page && $this->page->isWithTotalCount()){
            $info = FastDb::getInstance()->rawQuery('SELECT FOUND_ROWS() as count')->getResult();
            if(isset($info[0]['count'])){
                $total = $info[0]['count'];
            }
        }


        $this->page = null;

        if(!empty($ret->getResult())){
            if($relate->returnAsTargetEntity && !$returnAsArray){
                $list = [];
                foreach ($ret->getResult() as $item){
                    $list[] = new $relate->targetEntity($item);
                }
            }else{
                $list = $ret->getResult();
            }

            $ret = new SmartListResult($list,$total);
            if($relate->smartCreate){
                $ret->__setRelate(clone $relate,$selfValue);
            }

            //结果不为空才判断缓存
            if($relate->allowCache){
                $this->relateValues[$relateKey] = $ret;
            }

            return $ret;
        }
        $ret = new SmartListResult([],$total);
        if($relate->smartCreate){
            $ret->__setRelate(clone $relate,$selfValue);
        }
        return $ret;
    }

    private function parseRelate(?Relate $relate):Relate
    {
        $class = static::class;
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT,3);
        $method = $trace[2]['function'];

        if(ReflectionCache::getInstance()->getMethodParsedRelate($class,$method)){
            return clone ReflectionCache::getInstance()->getMethodParsedRelate($class,$method);
        }

        if($relate == null){
            $relates = ReflectionCache::getInstance()->entityReflection($class)->getMethodRelates();
            if(isset($relates[$method])){
                /** @var Relate $relate */
                $relate = $relates[$method];
            }else{
                throw new RuntimeError("not relation defined in class {$class} method {$method}");
            }
        }

        //检查目标属性是否为合法entity
        try {
            $targetRef = ReflectionCache::getInstance()->entityReflection($relate->targetEntity);
        }catch (\Throwable $throwable){
            throw new RuntimeError("relation error in class {$class} method {$method} case {$throwable->getMessage()}");
        }
        //检查目标映射属性是否存在。
        if($relate->targetProperty != null){
            if(!key_exists($relate->targetProperty,$targetRef->getProperties())){
                throw new RuntimeError("relation error in class {$class} method {$method} case target property {$relate->targetProperty} is not define in class {$relate->targetEntity}");
            }
        }else{
            $relate->targetProperty = $targetRef->getPrimaryKey();
        }

        //检查自身属性是否设置
        if($relate->selfProperty == null){
            $relate->selfProperty = $this->primaryKey;
        }

        //检查冲突性
        if($relate->allowCache && $relate->smartCreate){
            throw new RuntimeError("relation error in class {$class} method {$method} case cache relate result is not allow when smart create mode enable");
        }
        //设置缓存
        ReflectionCache::getInstance()->setMethodParsedRelate($class,$method,$relate);

        return clone $relate;

    }
}