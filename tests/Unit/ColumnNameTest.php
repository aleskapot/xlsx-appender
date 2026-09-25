<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XlsxFastAppender\ColumnName;
use XlsxFastAppender\ExcelLimits;
use XlsxFastAppender\Exception\InvalidCellReferenceException;

#[CoversClass(ColumnName::class)]
#[CoversClass(ExcelLimits::class)]
final class ColumnNameTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideValidIndexes(): iterable
    {
        yield 'first' => [1, 'A'];
        yield 'middle of first block' => [15, 'O'];
        yield 'last single letter' => [26, 'Z'];
        yield 'first two letters' => [27, 'AA'];
        yield 'two letters end' => [52, 'AZ'];
        yield 'two letters rollover' => [53, 'BA'];
        yield 'last two letters' => [702, 'ZZ'];
        yield 'first three letters' => [703, 'AAA'];
        yield 'last column XFD' => [ExcelLimits::MAX_COLUMNS, 'XFD'];
    }

    #[DataProvider('provideValidIndexes')]
    public function testOfConvertsIndexToName(int $index, string $expected): void
    {
        self::assertSame($expected, ColumnName::of($index));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideOutOfRangeIndexes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'one past the end' => [ExcelLimits::MAX_COLUMNS + 1];
    }

    #[DataProvider('provideOutOfRangeIndexes')]
    public function testOfRejectsOutOfRangeIndex(int $index): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage(\sprintf('Column index %d is out of range', $index));

        ColumnName::of($index);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideValidNames(): iterable
    {
        yield 'lowercase is accepted' => ['a', 1];
        yield 'single letter' => ['Z', 26];
        yield 'two letters' => ['AA', 27];
        yield 'mixed case' => ['aZ', 52];
        yield 'three letters' => ['AAA', 703];
        yield 'last column' => ['XFD', ExcelLimits::MAX_COLUMNS];
    }

    #[DataProvider('provideValidNames')]
    public function testIndexOfConvertsNameToIndex(string $name, int $expected): void
    {
        self::assertSame($expected, ColumnName::indexOf($name));
    }

    #[DataProvider('provideValidIndexes')]
    public function testOfAndIndexOfRoundTrip(int $index, string $name): void
    {
        self::assertSame($index, ColumnName::indexOf(ColumnName::of($index)));
        self::assertSame($name, ColumnName::of(ColumnName::indexOf($name)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedNames(): iterable
    {
        yield 'empty' => [''];
        yield 'digits' => ['1'];
        yield 'four letters' => ['AAAA'];
        yield 'mixed with digits' => ['A1'];
        yield 'space' => [' A'];
        yield 'beyond XFD' => ['ZZZ'];
    }

    #[DataProvider('provideMalformedNames')]
    public function testIndexOfRejectsMalformedName(string $name): void
    {
        $this->expectException(InvalidCellReferenceException::class);

        ColumnName::indexOf($name);
    }
}
