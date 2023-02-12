<?php

namespace EasySwoole\FastDb;

use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Page;
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

    private $whereCall = null;

    final function __construct(?array $data = null,bool $realData = false){
        $info = ReflectionCache::getInstance()->entityReflection(static::class);
        $this->properties = $info->getProperties();
        $this->primaryKey = $info->getPrimaryKey();
        $this->propertyRelates = $info->getMethodRelates();
        if(!empty($data)){
            $this->data($data,$realData);
        }
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onInitialize()){
            call_user_func($ref->onInitialize()->callback,$this);
        }
    }

    abstract function tableName():string;

    function data(array $data,bool $realData = false):Entity
    {
        foreach ($this->properties as $property => $val){
            if(array_key_exists($property,$data)){
                $this->{$property} = $data[$property];
                if($realData){
                    $this->properties[$property] = $data[$property];
                }
            }
        }
        return $this;
    }

    function whereCall(?callable $call):static
    {
        $this->whereCall = $call;
        return $this;
    }


    static function getOne(callable $whereCall):?static
    {
        $queryBuilder = new QueryBuilder();
        call_user_func($whereCall,$queryBuilder);
        $mode = new static();
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

    function all():ListResult
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

        $query->get($this->tableName(),null,$fields);

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

    function chunk(callable $func,$pageSize = 10):void
    {
        $cache = $this->fields;
        $page = 1;
        $whereCall = $this->whereCall;
        while (true){
            $this->fields = $cache;
            $list = $this->page($page,false,$pageSize)->all($whereCall);
            $this->whereCall = $whereCall;
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

    function insert(?array $updateDuplicateCols = [],bool $reSync = false):bool
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onInsert()){
            $ret = call_user_func($ref->onInsert()->callback,$this);
            if($ret === false){
                return false;
            }
        }
        //插入的时候，null值一般无意义，default值在数据库层做。
        $data = $this->toArray(true);
        $query = new QueryBuilder();
        if(!empty($updateDuplicateCols)){
            $query->onDuplicate($updateDuplicateCols);
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
                $this->data($info->getResult());
            }
            //同步properties;
            $this->properties = $this->toArray();
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

    function update(?array $data = null,?callable $whereCall = null)
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onUpdate()){
            $ret = call_user_func($ref->onUpdate()->callback,$this);
            if($ret === false){
                return false;
            }
        }

        if($whereCall == null && !isset($this->{$this->primaryKey})){
            throw new RuntimeError("can not update data without primaryKey or whereCall set in ".static::class);
        }
        $finalData = [];
        if($data != null){
            foreach ($data as $key => $datum){
                if(isset($this->properties[$key]) && $this->{$key} !== $datum){
                    $finalData[$key] = $datum;
                }
            }
        }else{
            foreach ($this->properties as $key => $property){
                if(isset($this->{$key})){
                    if($property !== $this->{$key}){
                        $finalData[$key] = $this->{$key};
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

        $query = new QueryBuilder();

        $singleRecord = false;
        //当主键有值的时候，不执行wherecall，因为pk可以确定唯一记录，再wherecall无意义
        if($this->primaryKey != null && $this->{$this->primaryKey} !== null){
            $query->where($this->primaryKey,$this->{$this->primaryKey});
            $singleRecord = true;
        }else{
            if($whereCall){
                call_user_func($whereCall,$query);
            }
        }

        $query->update($this->tableName(),$finalData);

        $queryResult = FastDb::getInstance()->query($query);
        $affectRows = $queryResult->getConnection()->mysqlClient()->affected_rows;

        if($singleRecord && $affectRows == 1){
            $this->data($finalData,true);
        }
        //affect rows num
        return $affectRows;
    }

    function delete(?callable $whereCall = null)
    {
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onDelete()){
            $ret = call_user_func($ref->onDelete()->callback,$this);
            if($ret === false){
                return false;
            }
        }

        if($whereCall == null && !isset($this->{$this->primaryKey})){
            throw new RuntimeError("can not delete data without primaryKey or whereCall set in ".static::class);
        }

        $query = new QueryBuilder();
        if(isset($this->{$this->primaryKey})){
            $query->where($this->primaryKey,$this->{$this->primaryKey});
        }else{
            call_user_func($whereCall,$query);
        }
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
                $temp[$key] = $this->{$key};
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

    function sum($cols,?callable $whereCall = null)
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
        if($whereCall){
            call_user_func($whereCall,$query);
        }

        $query->fields($str);
        $query->get($this->tableName());

        return FastDb::getInstance()->query($query)->getResult()[0];
    }

    function count(?callable $whereCall = null)
    {
        $query = new QueryBuilder();
        if($whereCall){
            call_user_func($whereCall,$query);
        }
        $query->get($this->tableName(),null,"count(*) as count");
        return FastDb::getInstance()->query($query)->getResult()[0]['count'];
    }


    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    protected function relateOne(?Relate $relate = null)
    {
        $class = static::class;
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT,2);
        $method = $trace[1]['function'];
        if(!$relate){
            $relates = ReflectionCache::getInstance()->entityReflection($class)->getMethodRelates();
            if(isset($relates[$method])){
                $relate = $relates[$method];
            }
        }else{
            throw new RuntimeError("not relation defined in class {$class} method {$method}");
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

        $this->fields = null;
        $this->whereCall = null;

        $query->where($relate->targetProperty,$this->{$relate->selfProperty})
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
            return null;
        }
    }

    protected function relateMore(?Relate $relate = null)
    {
        $class = static::class;
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT,2);
        $method = $trace[1]['function'];
        if(!$relate){
            $relates = ReflectionCache::getInstance()->entityReflection($class)->getMethodRelates();
            if(isset($relates[$method])){
                $relate = $relates[$method];
            }
        }else{
            throw new RuntimeError("not relation defined in class {$class} method {$method}");
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



        if($this->page != null){
            $query->limit(...$this->page->toLimitArray());
            if($this->page->isWithTotalCount()){
                $query->withTotalCount();
            }
        }

        $this->fields = null;
        $this->whereCall = null;


        $query->where($relate->targetProperty,$this->{$relate->selfProperty})
            ->get($temp->tableName(),null,$fields);
        $ret = FastDb::getInstance()->query($query);

        $total = null;
        if($this->page && $this->page->isWithTotalCount()){
            $info = FastDb::getInstance()->rawQuery('SELECT FOUND_ROWS() as count')->getResult();
            if(isset($info[0]['count'])){
                $total = $info[0]['count'];
            }
        }

        $list = [];
        $this->page = null;

        if(!empty($ret->getResult())){
            if($relate->returnAsTargetEntity && !$returnAsArray){
                foreach ($ret->getResult() as $item){
                    $list[] = new $relate->targetEntity($item);
                }
            }else{
                $list = $ret->getResult();
            }

            if($relate->allowCache){
                $this->relateValues[$relateKey] = $list;
            }

            return new ListResult($list,$total);
        }
        return $list;
    }
}