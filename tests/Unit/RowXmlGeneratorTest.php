<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use XlsxFastAppender\DateCell;
use XlsxFastAppender\Exception\InvalidCellReferenceException;
use XlsxFastAppender\Exception\UnsupportedValueException;
use XlsxFastAppender\RowXmlGenerator;
use XlsxFastAppender\SharedStringsStore;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(RowXmlGenerator::class)]
#[CoversClass(DateCell::class)]
final class RowXmlGeneratorTest extends TestCase
{
    public function testEmitsInlineStringCell(): void
    {
        $xml = $this->generator()->rowXml(5, ['héllo']);

        self::assertSame(
            '<row r="5"><c r="A5" t="inlineStr"><is><t>héllo</t></is></c></row>',
            $xml,
        );
    }

    public function testMarksEdgeSpacesWithXmlSpacePreserve(): void
    {
        $withSpaces = $this->generator()->rowXml(1, [' padded ', "\ttab"]);

        self::assertStringContainsString('xml:space="preserve"', $withSpaces);

        $plain = $this->generator()->rowXml(1, ['mid dle']);

        self::assertStringNotContainsString('xml:space', $plain);
    }

    #[DataProvider('specialCharacterProvider')]
    public function testRoundTripsXmlSpecialCharactersAndEmoji(string $value): void
    {
        $xml = $this->generator()->rowXml(1, [$value]);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML('<root>'.$xml.'</root>'));

        $texts = $dom->getElementsByTagName('t');
        self::assertSame(1, $texts->length);
        self::assertSame($value, $texts->item(0)?->textContent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function specialCharacterProvider(): iterable
    {
        yield 'angle brackets and ampersand' => ['<tag> & "quoted"'];
        yield 'apostrophe' => ["it's fine"];
        yield 'newline and tab' => ["line1\nline2\ttabbed"];
        yield 'carriage return' => ["carriage\rreturn\r\nline"];
        yield 'emoji' => ['🚀 rocket 🎉'];
        yield 'edge spaces' => ['  padded  '];
        yield 'cyrillic' => ['Съешь же ещё этих мягких французских булок'];
    }

    public function testEmitsIntegerWithoutTypeAttribute(): void
    {
        $xml = $this->generator()->rowXml(1, [42]);

        self::assertSame('<row r="1"><c r="A1"><v>42</v></c></row>', $xml);
    }

    #[DataProvider('floatProvider')]
    public function testSerializesFloatsWithoutScientificNotation(float $value, string $expected): void
    {
        $xml = $this->generator()->rowXml(1, [$value]);

        self::assertStringContainsString('<v>'.$expected.'</v>', $xml);
        self::assertDoesNotMatchRegularExpression('/<v>[^<]*[eE]/', $xml);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function floatProvider(): iterable
    {
        yield 'fraction' => [199.99, '199.99'];
        yield 'rounding artefact' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'negative zero' => [-0.0, '-0'];
        yield 'small exponent' => [1.0E-300, \sprintf('0.%s10', str_repeat('0', 299))];
        yield 'large exponent' => [1.0E+300, \sprintf('1%s', str_repeat('0', 300))];
        yield 'negative fraction' => [-199.99, '-199.99'];
        yield 'negative small exponent' => [-1.0E-300, '-'.\sprintf('0.%s10', str_repeat('0', 299))];
        yield 'negative tiny exponent' => [-1.5E-10, '-0.'.str_repeat('0', 9).'15'];
    }

    public function testEmitsBooleansAsNumericCells(): void
    {
        $xml = $this->generator()->rowXml(1, [true, false]);

        self::assertSame(
            '<row r="1"><c r="A1" t="b"><v>1</v></c><c r="B1" t="b"><v>0</v></c></row>',
            $xml,
        );
    }

    public function testWritesSelfClosedRowWhenValuesListIsEmpty(): void
    {
        $xml = $this->generator(4)->rowXml(3, []);

        self::assertSame('<row r="3"/>', $xml);
    }

    public function testSkipsNullCellsByDefault(): void
    {
        $xml = $this->generator()->rowXml(1, [1, null, 'x']);

        self::assertSame(
            '<row r="1"><c r="A1"><v>1</v></c><c r="C1" t="inlineStr"><is><t>x</t></is></c></row>',
            $xml,
        );
        self::assertStringNotContainsString('r="B1"', $xml);
    }

    public function testWritesEmptyCellWhenNullsAreKept(): void
    {
        $xml = $this->generator(1, skipNulls: false)->rowXml(1, [null]);

        self::assertSame('<row r="1"><c r="A1"/></row>', $xml);
    }

    public function testRejectsUnsupportedTypeWithCellAddress(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Cell B7');

        $this->generator(2)->rowXml(7, [new \DateTimeImmutable()]);
    }

    #[DataProvider('nonFiniteProvider')]
    public function testRejectsNonFiniteFloats(float $value): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('INF and NAN');

        $this->generator()->rowXml(1, [$value]);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteProvider(): iterable
    {
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    public function testAcceptsStringAtExactLengthLimit(): void
    {
        $xml = $this->generator()->rowXml(1, [str_repeat('a', 32767)]);

        self::assertStringContainsString(str_repeat('a', 32767), $xml);
    }

    public function testRejectsStringBeyondLengthLimit(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('32768 characters long');

        $this->generator()->rowXml(1, [str_repeat('a', 32768)]);
    }

    #[DataProvider('forbiddenCharacterProvider')]
    public function testRejectsCharactersForbiddenByXml(string $value): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('not allowed in XML');

        $this->generator()->rowXml(1, [$value]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCharacterProvider(): iterable
    {
        yield 'null byte' => ["a\x00b"];
        yield 'vertical tab' => ["a\x0Bb"];
        yield 'form feed' => ["a\x0C b"];
        yield 'unit separator' => ["a\x1Fb"];
    }

    #[DataProvider('invalidRowProvider')]
    public function testRejectsRowOutsideExcelLimits(int $row): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('outside the Excel limits');

        $this->generator()->rowXml($row, []);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidRowProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'below zero' => [-5];
        yield 'above max' => [1048577];
    }

    public function testAcceptsHighestExcelRow(): void
    {
        $xml = $this->generator()->rowXml(1048576, ['last']);

        self::assertStringContainsString('r="1048576"', $xml);
    }

    public function testAcceptsCellValueAtLastColumn(): void
    {
        $xml = $this->generator(16384)->rowXml(1, ['a']);

        self::assertStringContainsString('r="XFD1"', $xml);
    }

    public function testRejectsColumnBeyondXfd(): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('exceeds the Excel limit');

        $this->generator(16384)->rowXml(1, ['a', 'b']);
    }

    public function testStartColumnAccessor(): void
    {
        self::assertSame(3, $this->generator(3)->startColumn());
        self::assertSame(1, $this->generator()->startColumn());
    }

    public function testEmitsSharedStringReferencesWhenStoreIsAttached(): void
    {
        $store = SharedStringsStore::withoutFile();

        $xml = $this->generator(1, true, $store)->rowXml(1, ['hello', 'hello', 'world']);

        self::assertSame(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>',
            $xml,
        );
        self::assertTrue($store->hasNewReferences());
    }

    public function testSharedStringModeStillRejectsUnsupportedValues(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('INF and NAN');

        $this->generator(1, true, SharedStringsStore::withoutFile())->rowXml(1, [\INF]);
    }

    public function testWritesDateTimeValuesWhenDateStyleIsConfigured(): void
    {
        $xml = $this->generator(dateStyle: 3)->rowXml(2, [new \DateTimeImmutable('2026-10-29 12:00:00')]);

        self::assertSame('<row r="2"><c r="A2" s="3"><v>46324.5</v></c></row>', $xml);
    }

    #[DataProvider('dateShapeProvider')]
    public function testWritesDateStringCellsWhenDateStyleIsConfigured(string $value): void
    {
        $xml = $this->generator(dateStyle: 2)->rowXml(7, [$value]);

        self::assertSame('<row r="7"><c r="A7" s="2"><v>46324</v></c></row>', $xml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dateShapeProvider(): iterable
    {
        yield 'iso date' => ['2026-10-29'];
        yield 'dotted european' => ['29.10.2026'];
    }

    public function testWritesTimeFractionForDatetimeStrings(): void
    {
        $xml = $this->generator(dateStyle: 4)->rowXml(1, ['2026-10-29 12:00:00']);

        self::assertStringContainsString('<v>46324.5</v>', $xml);
        self::assertStringContainsString('s="4"', $xml);
    }

    public function testKeepsUnrecognizedStringsAsTextWhenDatesAreEnabled(): void
    {
        $xml = $this->generator(dateStyle: 2)->rowXml(1, ['2026-10-29 14:30', 'not a date', '12345']);

        self::assertSame(
            '<row r="1">'
            .'<c r="A1" t="inlineStr"><is><t>2026-10-29 14:30</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>not a date</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>12345</t></is></c>'
            .'</row>',
            $xml,
        );
    }

    public function testWritesDateCellsAndPlainValuesInTheSameRow(): void
    {
        $xml = $this->generator(dateStyle: 1)->rowXml(5, ['2026-10-29', 42, null, 'x']);

        self::assertSame(
            '<row r="5">'
            .'<c r="A5" s="1"><v>46324</v></c>'
            .'<c r="B5"><v>42</v></c>'
            .'<c r="D5" t="inlineStr"><is><t>x</t></is></c>'
            .'</row>',
            $xml,
        );
    }

    public function testDateStringInSharedStringsModeDoesNotEnterTheStore(): void
    {
        $store = SharedStringsStore::withoutFile();

        $xml = $this->generator(1, true, $store, 5)->rowXml(1, ['2026-10-29']);

        self::assertSame('<row r="1"><c r="A1" s="5"><v>46324</v></c></row>', $xml);
        self::assertFalse($store->hasNewReferences());
    }

    public function testRejectsDatesOutsideTheExcelRangeWithCellAddress(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Cell B7: dates before 1900-01-01');

        $this->generator(2, dateStyle: 1)->rowXml(7, [new \DateTimeImmutable('1899-12-31')]);
    }

    public function testKeepsNumericStringsAsTextWhenOptionIsDisabled(): void
    {
        $xml = $this->generator(numericStrings: false)->rowXml(1, ['0', '.7', '42']);

        self::assertSame(
            '<row r="1">'
            .'<c r="A1" t="inlineStr"><is><t>0</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>.7</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>42</t></is></c>'
            .'</row>',
            $xml,
        );
    }

    #[DataProvider('convertibleNumericStringProvider')]
    public function testConvertsNumericStringsWhenOptionIsEnabled(string $value, string $expectedXml): void
    {
        $xml = $this->generator(numericStrings: true)->rowXml(1, [$value]);

        self::assertSame('<row r="1"><c r="A1">'.$expectedXml.'</c></row>', $xml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function convertibleNumericStringProvider(): iterable
    {
        yield 'zero' => ['0', '<v>0</v>'];
        yield 'negative zero' => ['-0', '<v>0</v>'];
        yield 'plus sign' => ['+7', '<v>7</v>'];
        yield 'integer' => ['42', '<v>42</v>'];
        yield 'leading dot fraction' => ['.7', '<v>0.7</v>'];
        yield 'negative leading dot' => ['-.7', '<v>-0.7</v>'];
        yield 'zero integer part' => ['0.7', '<v>0.7</v>'];
        yield 'trailing dot' => ['5.', '<v>5</v>'];
        yield 'exponent' => ['1e5', '<v>100000</v>'];
        yield 'negative exponent' => ['1E-5', '<v>0.000010</v>'];
        yield 'exponent zero' => ['0e0', '<v>0</v>'];
        yield 'dot zero' => ['.0', '<v>0</v>'];
        yield 'php int max' => ['9223372036854775807', '<v>9223372036854775807</v>'];
        yield 'php int min' => ['-9223372036854775808', '<v>-9223372036854775808</v>'];
        yield 'above int max becomes float' => ['9223372036854775808', '<v>9223372036854776000</v>'];
        yield 'below int min becomes float' => ['-9223372036854775809', '<v>-9223372036854776000</v>'];
        yield 'twenty digits becomes float' => ['12345678901234567890', '<v>12345678901234567000</v>'];
    }

    #[DataProvider('textualNumericStringProvider')]
    public function testKeepsAmbiguousNumericStringsAsTextWhenOptionIsEnabled(string $value): void
    {
        $xml = $this->generator(numericStrings: true)->rowXml(1, [$value]);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML('<root>'.$xml.'</root>'));

        $cells = $dom->getElementsByTagName('c');
        self::assertSame(1, $cells->length);
        self::assertSame('inlineStr', $cells->item(0)?->getAttribute('t'));

        $texts = $dom->getElementsByTagName('t');
        self::assertSame(1, $texts->length);
        self::assertSame($value, $texts->item(0)?->textContent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textualNumericStringProvider(): iterable
    {
        yield 'leading zero code' => ['007'];
        yield 'padded octal-like code' => ['0755'];
        yield 'negative leading zeros' => ['-001'];
        yield 'double zero' => ['00'];
        yield 'exponent with leading zeros' => ['00e5'];
        yield 'leading space' => [' 7'];
        yield 'trailing space' => ['7 '];
        yield 'surrounding spaces' => [' 7 '];
        yield 'underscore literal' => ['1_000'];
        yield 'hex literal' => ['0x1A'];
        yield 'decimal comma' => ['1,5'];
        yield 'INF word' => ['INF'];
        yield 'letters' => ['abc'];
        yield 'empty string' => [''];
    }

    public function testRejectsOverflowingExponentStringWithCellAddress(): void
    {
        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Cell C7: INF and NAN');

        $this->generator(2, numericStrings: true)->rowXml(7, ['x', '1e999']);
    }

    public function testConvertedNumericStringsDoNotEnterSharedStringsStore(): void
    {
        $store = SharedStringsStore::withoutFile();

        $xml = $this->generator(1, true, $store, null, true)->rowXml(1, ['42', 'x', 'x']);

        self::assertSame(
            '<row r="1">'
            .'<c r="A1"><v>42</v></c>'
            .'<c r="B1" t="s"><v>0</v></c>'
            .'<c r="C1" t="s"><v>0</v></c>'
            .'</row>',
            $xml,
        );
        self::assertTrue($store->hasNewReferences());
    }

    public function testDateShapesWinOverNumericStringsWhenBothOptionsAreEnabled(): void
    {
        $xml = $this->generator(dateStyle: 2, numericStrings: true)->rowXml(1, ['2026-10-29', '42']);

        self::assertSame(
            '<row r="1">'
            .'<c r="A1" s="2"><v>46324</v></c>'
            .'<c r="B1"><v>42</v></c>'
            .'</row>',
            $xml,
        );
    }

    public function testHeaderRowKeepsNumericStringsAsText(): void
    {
        $xml = $this->generator(numericStrings: true)->rowXml(1, ['2024', '0', '.7'], false);

        self::assertSame(
            '<row r="1">'
            .'<c r="A1" t="inlineStr"><is><t>2024</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>0</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>.7</t></is></c>'
            .'</row>',
            $xml,
        );
    }

    private function generator(
        int $startColumn = 1,
        bool $skipNulls = true,
        ?SharedStringsStore $strings = null,
        ?int $dateStyle = null,
        bool $numericStrings = false,
    ): RowXmlGenerator {
        return new RowXmlGenerator($startColumn, $skipNulls, $strings, $dateStyle, $numericStrings);
    }
}
