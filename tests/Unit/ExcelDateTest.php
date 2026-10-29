<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use XlsxFastAppender\ExcelDate;
use XlsxFastAppender\Exception\UnsupportedValueException;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(ExcelDate::class)]
final class ExcelDateTest extends TestCase
{
    #[DataProvider('parseProvider')]
    public function testTryParseAcceptsCommonDateShapes(string $value, string $expected): void
    {
        $parsed = ExcelDate::tryParse($value);

        self::assertNotNull($parsed);
        self::assertSame($expected, $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parseProvider(): iterable
    {
        yield 'iso date' => ['2026-10-29', '2026-10-29 00:00:00'];
        yield 'iso datetime' => ['2026-10-29 14:30:00', '2026-10-29 14:30:00'];
        yield 'iso 8601 t separator' => ['2026-10-29T14:30:00', '2026-10-29 14:30:00'];
        yield 'dotted european date' => ['29.10.2026', '2026-10-29 00:00:00'];
        yield 'dotted european datetime' => ['29.10.2026 14:30:00', '2026-10-29 14:30:00'];
        yield 'leap day' => ['2024-02-29', '2024-02-29 00:00:00'];
        yield 'excel epoch day' => ['1900-01-01', '1900-01-01 00:00:00'];
    }

    #[DataProvider('rejectProvider')]
    public function testTryParseRejectsNonDates(string $value): void
    {
        self::assertNull(ExcelDate::tryParse($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectProvider(): iterable
    {
        yield 'february 31st rolls over' => ['2020-02-31'];
        yield 'month 13' => ['2020-13-01'];
        yield 'fictional 1900 leap day' => ['1900-02-29'];
        yield 'plain word' => ['abc'];
        yield 'plain number' => ['12345'];
        yield 'empty string' => [''];
        yield 'missing seconds' => ['2026-10-29 14:30'];
        yield 'iso missing seconds' => ['2026-10-29T14:30'];
        yield 'two-digit year' => ['29.10.26'];
        yield 'fractional seconds' => ['2026-10-29 14:30:00.5'];
        yield 'leading space' => [' 2026-10-29'];
        yield 'trailing newline' => ["2026-10-29\n"];
        yield 'unpadded month' => ['2026-1-29'];
        yield 'day first iso' => ['29-10-2026'];
        yield 'slash separated' => ['29/10/2026'];
        yield 'single digit hour' => ['2026-10-29 9:30:00'];
    }

    #[DataProvider('serialProvider')]
    public function testSerialOfConvertsDatesToExcelSerialNumbers(string $date, int|float $expected): void
    {
        self::assertSame($expected, ExcelDate::serialOf(new \DateTimeImmutable($date), 1, 1));
    }

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function serialProvider(): iterable
    {
        yield 'excel day one' => ['1900-01-01 00:00:00', 1];
        yield 'day before fictional leap day' => ['1900-02-28 00:00:00', 59];
        yield 'day after fictional leap day' => ['1900-03-01 00:00:00', 61];
        yield 'known modern date' => ['2023-10-14 00:00:00', 45213];
        yield 'date only' => ['2026-10-29 00:00:00', 46324];
        yield 'noon is a half' => ['2026-10-29 12:00:00', 46324.5];
        yield 'quarter day' => ['2026-10-29 06:00:00', 46324.25];
        yield 'last supported day' => ['9999-12-31 00:00:00', 2958465];
    }

    public function testSerialOfTruncatesSubSecondPrecision(): void
    {
        $serial = ExcelDate::serialOf(new \DateTimeImmutable('2026-10-29 12:00:00.750'), 1, 1);

        self::assertSame(46324.5, $serial);
    }

    public function testSerialOfDoesNotMutateMutableDateTime(): void
    {
        $date = new \DateTime('2026-10-29 14:30:00');

        self::assertSame(46324.6041667, ExcelDate::serialOf($date, 1, 1));
        self::assertSame('2026-10-29 14:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function testSerialOfRejectsDatesBefore1900WithCellAddress(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Cell B7: dates before 1900-01-01');

        ExcelDate::serialOf(new \DateTimeImmutable('1899-12-31 00:00:00'), 2, 7);
    }

    public function testSerialOfRejectsDatesAfterYear9999(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Cell A1: dates after 9999-12-31');

        ExcelDate::serialOf((new \DateTimeImmutable('9999-12-31 00:00:00'))->modify('+1 day'), 1, 1);
    }
}
