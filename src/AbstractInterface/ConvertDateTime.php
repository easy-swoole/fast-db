<?php

namespace EasySwoole\FastDb\AbstractInterface;


class ConvertDateTime extends \DateTime implements ConvertObjectInterface
{
    public static function toObject(mixed $data): static
    {
        if($data instanceof \DateTimeInterface){
            // Copy rather than sharing mutable state; DATETIME has no timezone.
            return new static($data->format('Y-m-d H:i:s.u'), $data->getTimezone());
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

        // DateTime otherwise silently normalizes impossible MySQL calendar dates.
        if(preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D', $data, $matches)){
            $format = isset($matches[2]) ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
            $parsed = \DateTimeImmutable::createFromFormat($format, $data);
            $errors = \DateTimeImmutable::getLastErrors();
            if($parsed === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))){
                throw new \InvalidArgumentException('Invalid MySQL DATETIME value: '.$data);
            }
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
