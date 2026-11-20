<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\ConvertDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConvertDateTimeTest extends TestCase
{
    public static function mysqlDateTimes(): array
    {
        return [
            ['2026-11-20 12:34:56', '2026-11-20 12:34:56', '000000'],
            ['2026-11-20 12:34:56.0', '2026-11-20 12:34:56', '000000'],
            ['2026-11-20 12:34:56.1', '2026-11-20 12:34:56.100000', '100000'],
            ['2026-11-20 12:34:56.123456', '2026-11-20 12:34:56.123456', '123456'],
        ];
    }

    #[DataProvider('mysqlDateTimes')]
    public function testMysqlStringPreservesItsTimeFractionAndDefaultTimezone(
        string $value, string $expected, string $microseconds
    ): void {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        try {
            $date = ConvertDateTime::toObject($value);
            $this->assertInstanceOf(ConvertDateTime::class, $date);
            $this->assertSame($expected, $date->toValue());
            $this->assertSame($microseconds, $date->format('u'));
            $this->assertSame('Asia/Shanghai', $date->getTimezone()->getName());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public static function invalidMysqlDateTimes(): array
    {
        return [['0000-00-00 00:00:00'], ['2026-02-29 12:00:00'], ['2026-11-20 25:00:00']];
    }

    #[DataProvider('invalidMysqlDateTimes')]
    public function testInvalidMysqlDatesAreRejectedInsteadOfBeingAdjusted(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConvertDateTime::toObject($value);
    }

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
