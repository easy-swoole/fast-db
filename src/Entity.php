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
    const FILTER_NOT_NULL = 2;
    const FILTER_ASSOCIATE_RELATION = 4;

    /**
     * @var array
     * 用于存储对象成员，和做老数据diff
     */
    private array $properties = [];
    private array $relateValues = [];

    private array $propertyRelates = [];

    protected ?string $primaryKey = null;


    final function __construct(?array $data = null){
        $this->reflection();
        if(!empty($data)){
            $this->data($data);
        }
        $this->initialize();
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

    function insert(?array $updateDuplicateCols = [],bool $reSync = false):bool
    {
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
        $data = ReflectionCache::getInstance()->entityReflection(static::class);
        $this->properties = $data->getProperties();
        $this->primaryKey = $data->getPrimaryKey();
        $this->propertyRelates = $data->getMethodRelates();
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
                $temp->data($ret->getResult()[0]);
                if($relate->allowCache){
                    $this->relateValues[$relateKey] = $temp;
                }
                return $temp;
            }else{
                return null;
            }
        }else{
            $query->where($relate->targetProperty,$this->{$relate->selfProperty})
                ->get($temp->tableName());
            $ret = FastDb::getInstance()->query($query);
            $list = [];
            if(!empty($ret->getResult())){
                foreach ($ret->getResult() as $item){
                    /** @var Entity $new */
                    $new = new $relate->targetEntity();
                    $new->data($item);
                    $list[] = $new;
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