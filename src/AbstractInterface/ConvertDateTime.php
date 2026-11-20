<?php

namespace EasySwoole\FastDb\AbstractInterface;


class ConvertDateTime extends \DateTime implements ConvertObjectInterface
{
    public static function toObject(mixed $data): static
    {
        if($data instanceof \DateTimeInterface){
            // 直接复制原时间点，避免夏令时回拨时重新解析到另一个时间点。
            return static::createFromInterface($data);
        }
        if(is_int($data)){
            $date = new static();
            $date->setTimestamp($data);
            return $date;
        }
        if($data === null){
            return new static();
        }
        if(!is_string($data) || trim($data) === ''){
            throw new \InvalidArgumentException('ConvertDateTime expects a date/time string, DateTimeInterface, integer timestamp or null');
        }

        // 按 MySQL 格式解析并检查日期纠正警告，校验成功后直接复用解析实例。
        if(preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D', $data, $matches)){
            $format = isset($matches[2]) ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
            $parsed = static::createFromFormat($format, $data);
            $errors = static::getLastErrors();
            if($parsed === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))){
                throw new \InvalidArgumentException('Invalid MySQL DATETIME value: '.$data);
            }
            return $parsed;
        }
        return new static($data);
    }

    public function toValue(): string
    {
        return $this->format('u') === '000000'
            ? $this->format('Y-m-d H:i:s')
            : $this->format('Y-m-d H:i:s.u');
    }
}
