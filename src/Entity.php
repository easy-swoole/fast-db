<?php

namespace EasySwoole\FastDb;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Utility\ListResult;
use EasySwoole\FastDb\Utility\Page;
use EasySwoole\Mysqli\QueryBuilder;

abstract class Entity implements \JsonSerializable
{
    const FILTER_NOT_NULL = 2;
    const FILTER_ASSOCIATE_RELATION = 4;

    /**
     * @var array
     * 用于存储对象成员，和做老数据diff
     */
    private array $properties = [];
    private array $relateValues = [];

    protected ?string $primaryKey = null;

    /**
     * @throws RuntimeError
     */
    final function __construct(?array $data = null){
        $this->reflection();
        foreach ($this->properties as $property => $val){
            if(isset($data[$property])){
                $this->{$property} = $data[$property];
            }
        }
        $this->initialize();
    }

    abstract function tableName():string;


    static function getOne(callable $whereCall):?static
    {
        $queryBuilder = new QueryBuilder();
        call_user_func($whereCall,$queryBuilder);
        $data = [];
        $mode = new static($data);
        return $mode;
    }

    function all(?callable $whereCall = null,?Page $page = null):ListResult
    {

    }

    function chunk(callable $func,?callable $whereCall = null,$pageSize = 10):void
    {

    }

    function insert(bool $reSync = false):bool
    {
        $data = $this->toArray();
        $query = new QueryBuilder();
        $query->insert($this->tableName(),$data);
        $ret = FastDb::getInstance()->query($query);
        if($ret->getResult()){
            $this->{$this->primaryKey} = $ret->getConnection()->mysqlClient()->insert_id;
            if($reSync){
                //当数据库有些字段设置了脚本或者是自动创建，需要重新get一次同步
            }
            return true;
        }else{
            return false;
        }
    }

    function update(?array $data = null,?callable $whereCall = null):int
    {
        if($whereCall == null && $this->primaryKey == null){
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
        if($whereCall == null && $this->primaryKey == null){
            throw new RuntimeError("can not delete data without primaryKey or whereCall set in ".static::class);
        }
    }

    function toArray($filter = null):array
    {
        $temp = [];
        foreach ($this->properties as $key => $property){
            if(isset($this->{$key})){
                $temp[$key] = $this->{$key};
            }else{
                $temp[$key] = null;
            }
        }

        if($filter == null){
            return $temp;
        }

        if($filter == 2 || $filter == 6){
            foreach ($temp as $key => $item){
                if($item === null){
                    unset($temp[$key]);
                }
            }
        }
        if($filter == 4 || $filter == 6){
            //做关联判定处理
        }
        return $temp;
    }

    protected function initialize(): void
    {

    }

    private function reflection(): void
    {
        $ref = new \ReflectionClass(static::class);
        $list = $ref->getProperties(\ReflectionProperty::IS_PUBLIC|\ReflectionProperty::IS_PROTECTED);
        foreach ($list as $property){
            $temp = $property->getAttributes(Property::class);
            if(!empty($temp)){
                $temp = $temp[0];
                $temp = new Property(...$temp->getArguments());
                $this->properties[$property->name] = $property->getDefaultValue();
                if($temp->isPrimaryKey){
                    if($this->primaryKey == null){
                        $this->primaryKey = $property->name;
                    }else{
                        throw new RuntimeError("can not redefine primaryKey in ".static::class);
                    }
                }
            }
        }
        if($this->primaryKey == null){
            throw new RuntimeError("primaryKey must be define in ".static::class);
        }
    }


    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}