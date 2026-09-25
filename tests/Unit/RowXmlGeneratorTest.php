<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use XlsxFastAppender\Exception\InvalidCellReferenceException;
use XlsxFastAppender\Exception\UnsupportedValueException;
use XlsxFastAppender\RowXmlGenerator;
use XlsxFastAppender\SharedStringsStore;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(RowXmlGenerator::class)]
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

    private function generator(int $startColumn = 1, bool $skipNulls = true, ?SharedStringsStore $strings = null): RowXmlGenerator
    {
        return new RowXmlGenerator($startColumn, $skipNulls, $strings);
    }
}
