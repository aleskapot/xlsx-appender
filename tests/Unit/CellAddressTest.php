<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XlsxFastAppender\CellAddress;
use XlsxFastAppender\ColumnName;
use XlsxFastAppender\ExcelLimits;
use XlsxFastAppender\Exception\InvalidCellReferenceException;

#[CoversClass(CellAddress::class)]
final class CellAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function provideValidReferences(): iterable
    {
        yield 'origin' => ['A1', 1, 1];
        yield 'plain' => ['B2', 2, 2];
        yield 'lowercase' => ['c3', 3, 3];
        yield 'dollar anchors' => ['$C$5', 3, 5];
        yield 'last column and row' => ['XFD1048576', ExcelLimits::MAX_COLUMNS, ExcelLimits::MAX_ROWS];
        yield 'beyond Z' => ['AA10', 27, 10];
    }

    #[DataProvider('provideValidReferences')]
    public function testParseSplitsColumnAndRow(string $reference, int $column, int $row): void
    {
        $address = CellAddress::parse($reference);

        self::assertSame($column, $address->column);
        self::assertSame($row, $address->row);
    }

    #[DataProvider('provideValidReferences')]
    public function testToStringNormalisesReference(string $reference, int $column, int $row): void
    {
        $address = new CellAddress($column, $row);

        self::assertSame(ColumnName::of($column).$row, $address->toString());
        self::assertSame($column, CellAddress::parse($address->toString())->column);
        self::assertSame($row, CellAddress::parse($address->toString())->row);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'column only' => ['A'];
        yield 'row only' => ['1'];
        yield 'row zero' => ['A0'];
        yield 'zero padded row' => ['A01'];
        yield 'two cells' => ['A1B2'];
        yield 'leading space' => [' A1'];
        yield 'trailing space' => ['A1 '];
        yield 'too many letters' => ['AAAA1'];
        yield 'too many digits' => ['A10000000'];
        yield 'garbage' => ['Sheet1!A1'];
    }

    #[DataProvider('provideMalformedReferences')]
    public function testParseRejectsMalformedReference(string $reference): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('Invalid cell reference');

        CellAddress::parse($reference);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOutOfRangeReferences(): iterable
    {
        yield 'column past XFD' => ['ZZZ1'];
        yield 'row past 1048576' => ['A1999999'];
    }

    #[DataProvider('provideOutOfRangeReferences')]
    public function testParseRejectsOutOfRangeReference(string $reference): void
    {
        $this->expectException(InvalidCellReferenceException::class);

        CellAddress::parse($reference);
    }

    public function testConstructorRejectsZeroColumn(): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('Column 0 is out of range');

        new CellAddress(0, 1);
    }

    public function testConstructorRejectsColumnPastXfd(): void
    {
        $this->expectException(InvalidCellReferenceException::class);

        new CellAddress(ExcelLimits::MAX_COLUMNS + 1, 1);
    }

    public function testConstructorRejectsZeroRow(): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('Row 0 is out of range');

        new CellAddress(1, 0);
    }

    public function testConstructorRejectsRowPastLimit(): void
    {
        $this->expectException(InvalidCellReferenceException::class);

        new CellAddress(1, ExcelLimits::MAX_ROWS + 1);
    }
}
