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

    protected ?string $primaryKey = null;

    protected ?array $fields = null;


    final function __construct(?array $data = null){
        $this->reflection();
        if(!empty($data)){
            $this->data($data);
        }
        $ref = ReflectionCache::getInstance()->entityReflection(static::class);
        if($ref->onInitialize()){
            call_user_func($ref->onInitialize()->callback,$this);
        }
    }

    abstract function tableName():string;

    function data(array $data):Entity
    {
        foreach ($this->properties as $property => $val){
            if(isset($data[$property])){
                $this->{$property} = $data[$property];
            }
        }
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
            $mode->data($info[0]);
            return $mode;
        }else{
            return null;
        }
    }

    function getOneAsArray(callable $whereCall):?array
    {
        $queryBuilder = new QueryBuilder();
        call_user_func($whereCall,$queryBuilder);
        $fields = null;
        if(!empty($this->fields['fields'])){
            $fields = $this->fields['fields'];
        }
        $this->fields = null;
        $queryBuilder->getOne($this->tableName(),$fields);
        $info = FastDb::getInstance()->query($queryBuilder)->getResult();

        if(!empty($info)){
            return $info[0];
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

    function all(?callable $whereCall = null,?Page $page = null):ListResult
    {
        $query = new QueryBuilder();
        if(is_callable($whereCall)){
            call_user_func($whereCall,$query);
        }

        $total = null;

        if($page != null){
            $query->limit(...$page->toLimitArray());
            if($page->isWithTotalCount()){
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
        if($page && $page->isWithTotalCount()){
            $info = FastDb::getInstance()->rawQuery('SELECT FOUND_ROWS() as count')->getResult();
            if(isset($info[0]['count'])){
                $total = $info[0]['count'];
            }
        }
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

    function chunk(callable $func,?callable $whereCall = null,$pageSize = 10):void
    {
        $page = new Page(1,$pageSize);
        $cache = $this->fields;
        while (true){
            $this->fields = $cache;
            $list = $this->all($whereCall,$page);
            foreach ($list as $item){
                call_user_func($func,$item);
            }
            if(count($list) == $pageSize){
                $page = new Page($page->getPage() + 1,$pageSize);
            }else{
                break;
            }
        }
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
        $data = $this->toArray();
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
                if($property !== $this->{$key}){
                    $finalData[$key] = $this->{$key};
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
            $this->properties = $this->toArray();
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


    private function reflection(): void
    {
        $data = ReflectionCache::getInstance()->entityReflection(static::class);
        $this->properties = $data->getProperties();
        $this->primaryKey = $data->getPrimaryKey();
        $this->propertyRelates = $data->getMethodRelates();
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

    protected function relate(?Relate $relate = null,?callable $whereCall = null)
    {
        //一个ID属性可以关联到多个实体。比如一个学生可以有多个课程，也有一个自己的详细资料
        if($relate == null){
            //由于是debug trace,上层方法请直接调用，不要再放置到其他类或者是闭包等其他方法中。
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT,2);
            $trace = $trace[1];
            if(!isset( $trace['class'])){
                throw new RuntimeError("please call relate() in direct");
            }
            $class = $trace['class'];
            $method = $trace['function'];
            $relates = ReflectionCache::getInstance()->entityReflection($class)->getMethodRelates();

            if(isset($relates[$method])){
                $relate = $relates[$method];
            }else{
                throw new RuntimeError("not relation defined in class {$class} method {$method}");
            }
        }

        if($relate->selfProperty == null){
            $relate->selfProperty = $this->primaryKey;
        }

        $relateKey = md5($relate->targetEntity.$relate->selfProperty.$relate->targetProperty);

        if($relate->allowCache && isset($this->relateValues[$relateKey])){
            return $this->relateValues[$relateKey];
        }

        /** @var Entity $temp */
        $temp = new $relate->targetEntity();

        $query = new QueryBuilder();
        if($whereCall){
            call_user_func($whereCall,$query);
        }

        if($relate->relateType == Relate::RELATE_ONE_TO_NOE){
            $query->where($relate->targetProperty,$this->{$relate->selfProperty})
                ->getOne($temp->tableName());
            $ret = FastDb::getInstance()->query($query);
            if(!empty($ret->getResult())){
                $return = $ret->getResult()[0];
                if($relate->returnAsTargetEntity){
                    $return = new $relate->targetEntity($return);
                }
                if($relate->allowCache){
                    $this->relateValues[$relateKey] = $return;
                }
                return $return;
            }else{
                return null;
            }
        }else{
            $query->where($relate->targetProperty,$this->{$relate->selfProperty})
                ->get($temp->tableName());
            $ret = FastDb::getInstance()->query($query);
            $list = [];
            if(!empty($ret->getResult())){
                if($relate->returnAsTargetEntity){
                    foreach ($ret->getResult() as $item){
                        $list[] = new $relate->targetEntity($item);
                    }
                }else{
                    $list = $ret->getResult();
                }

                if($relate->allowCache){
                    $this->relateValues[$relateKey] = $list;
                }

                return $list;
            }
            return $list;
        }
    }
}