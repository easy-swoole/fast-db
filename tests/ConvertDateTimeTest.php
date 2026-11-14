<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\ConvertDateTime;
use PHPUnit\Framework\TestCase;

final class ConvertDateTimeTest extends TestCase
{
    public function testCopiesBothOccurrencesOfTheRepeatedHourWhenDaylightSavingEnds(): void
    {
        $timezone = new \DateTimeZone('America/New_York');
        $beforeFallback = (new \DateTimeImmutable('2025-11-02T05:30:00.123456Z'))->setTimezone($timezone);
        $afterFallback = (new \DateTimeImmutable('2025-11-02T06:30:00.123456Z'))->setTimezone($timezone);

        foreach ([$beforeFallback, $afterFallback] as $original) {
            $copy = ConvertDateTime::toObject($original);

            $this->assertSame($original->getTimestamp(), $copy->getTimestamp());
            $this->assertSame($original->getOffset(), $copy->getOffset());
            $this->assertSame($timezone->getName(), $copy->getTimezone()->getName());
            $this->assertSame('2025-11-02 01:30:00.123456', $copy->toValue());
        }
    }
}
