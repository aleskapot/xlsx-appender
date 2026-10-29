<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\StylesStore;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(StylesStore::class)]
final class StylesStoreTest extends TestCase
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function testFreshStoreBuildsMinimalValidStylesheet(): void
    {
        $store = StylesStore::withoutFile();

        self::assertFalse($store->stylesFileExisted());
        self::assertFalse($store->hasChanges());

        $index = $store->dateStyleIndex('DD.MM.YYYY');

        self::assertSame(1, $index);
        self::assertTrue($store->hasChanges());
        self::assertFalse($store->stylesFileExisted());

        $xml = $store->buildStylesXml();

        self::assertStringContainsString('xmlns="'.self::MAIN_NS.'"', $xml);
        self::assertStringContainsString('<numFmt numFmtId="164" formatCode="DD.MM.YYYY"/>', $xml);
        self::assertStringContainsString('<cellXfs count="2">', $xml);
        self::assertStringContainsString(
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            $xml,
        );
        self::assertMatchesRegularExpression(
            '/^<\?xml version="1.0" encoding="UTF-8" standalone="yes"\?><styleSheet\b/',
            $xml,
        );
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testDateStyleIndexIsCachedPerFormat(): void
    {
        $store = StylesStore::withoutFile();

        self::assertSame(1, $store->dateStyleIndex('DD.MM.YYYY'));
        self::assertSame(1, $store->dateStyleIndex('DD.MM.YYYY'));
        self::assertTrue($store->hasChanges());
    }

    public function testAddsNumFmtsContainerAndDateXfToExistingStyles(): void
    {
        $existing = self::existingStyles();
        $store = StylesStore::fromXml($existing);

        self::assertTrue($store->stylesFileExisted());

        $index = $store->dateStyleIndex('DD.MM.YYYY');

        self::assertSame(1, $index);
        self::assertTrue($store->hasChanges());

        $xml = $store->buildStylesXml();

        self::assertStringContainsString(
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD.MM.YYYY"/></numFmts><fonts',
            $xml,
        );
        self::assertStringContainsString('<cellXfs count="2">', $xml);
        self::assertStringContainsString(
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            $xml,
        );
        self::assertStringContainsString('<cellStyles count="1">', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testInsertsIntoExistingNumFmtsAndKeepsForeignBytes(): void
    {
        $existing = str_replace(
            '<fonts',
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="General"/></numFmts><fonts',
            self::existingStyles(),
        );
        $store = StylesStore::fromXml($existing);

        $index = $store->dateStyleIndex('DD.MM.YYYY');

        self::assertSame(1, $index);

        $xml = $store->buildStylesXml();

        self::assertStringContainsString('<numFmt numFmtId="164" formatCode="General"/>', $xml);
        self::assertStringContainsString('<numFmt numFmtId="165" formatCode="DD.MM.YYYY"/>', $xml);
        self::assertStringContainsString('<numFmts count="2">', $xml);
        self::assertStringContainsString('<fonts count="1">', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testExpandsSelfClosedNumFmtsContainer(): void
    {
        $existing = str_replace(
            '<fonts',
            '<numFmts count="0"/><fonts',
            self::existingStyles(),
        );
        $store = StylesStore::fromXml($existing);

        self::assertSame(1, $store->dateStyleIndex('DD.MM.YYYY'));

        $xml = $store->buildStylesXml();

        self::assertStringContainsString('<numFmts count="1"><numFmt numFmtId="164"', $xml);
        self::assertStringNotContainsString('<numFmts count="0"/>', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testExpandsSelfClosedCellXfs(): void
    {
        $existing = str_replace(
            '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>',
            '<cellXfs count="0"/>',
            self::existingStyles(),
        );
        $store = StylesStore::fromXml($existing);

        $index = $store->dateStyleIndex('DD.MM.YYYY');

        self::assertSame(0, $index);

        $xml = $store->buildStylesXml();

        self::assertStringContainsString('<cellXfs count="1">', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testReusesMatchingFormatAndStyleWithoutChanges(): void
    {
        $existing = str_replace(
            '<fonts',
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD.MM.YYYY"/></numFmts><fonts',
            self::existingStyles(),
        );
        $existing = str_replace(
            '</cellXfs>',
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>',
            $existing,
        );
        $existing = str_replace('<cellXfs count="1">', '<cellXfs count="2">', $existing);
        $store = StylesStore::fromXml($existing);

        $index = $store->dateStyleIndex('DD.MM.YYYY');

        self::assertSame(1, $index);
        self::assertFalse($store->hasChanges());
        self::assertSame($existing, $store->buildStylesXml());
    }

    public function testEscapedFormatCodeIsMatchedOnSubsequentRuns(): void
    {
        $store = StylesStore::withoutFile();
        $store->dateStyleIndex('& "quoted" format');

        $reparsed = StylesStore::fromXml($store->buildStylesXml());
        $index = $reparsed->dateStyleIndex('& "quoted" format');

        self::assertSame(1, $index);
        self::assertFalse($reparsed->hasChanges());
    }

    public function testNumFmtWithoutFormatCodeAttributeIsSkipped(): void
    {
        $existing = str_replace(
            '<fonts',
            '<numFmts count="2"><numFmt numFmtId="164"/><numFmt numFmtId="165" formatCode="DD.MM.YYYY"/></numFmts><fonts',
            self::existingStyles(),
        );
        $existing = str_replace(
            '</cellXfs>',
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>',
            $existing,
        );
        $existing = str_replace('<cellXfs count="1">', '<cellXfs count="2">', $existing);
        $store = StylesStore::fromXml($existing);

        self::assertSame(1, $store->dateStyleIndex('DD.MM.YYYY'));
        self::assertFalse($store->hasChanges());
    }

    public function testUnclosedNumFmtsOpenTagIsRejected(): void
    {
        $store = StylesStore::fromXml(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::MAIN_NS.'">'
            .'<numFmts count="1">'
            .'<fonts count="1"><font/></fonts>'
            .'</styleSheet>',
        );

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('no closing </numFmts> tag');

        $store->dateStyleIndex('DD.MM.YYYY');
    }

    public function testXfWithNonDefaultFontIsNotReused(): void
    {
        $existing = str_replace(
            '<fonts',
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD.MM.YYYY"/></numFmts><fonts',
            self::existingStyles(),
        );
        $existing = str_replace(
            '</cellXfs>',
            '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>',
            $existing,
        );
        $existing = str_replace('<cellXfs count="1">', '<cellXfs count="2">', $existing);
        $store = StylesStore::fromXml($existing);

        $index = $store->dateStyleIndex('DD.MM.YYYY');

        self::assertSame(2, $index);
        self::assertTrue($store->hasChanges());
        self::assertTrue(self::isWellFormed($store->buildStylesXml()));
    }

    public function testXfDisablingNumberFormatsIsNotReused(): void
    {
        $existing = str_replace(
            '<fonts',
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD.MM.YYYY"/></numFmts><fonts',
            self::existingStyles(),
        );
        $existing = str_replace(
            '</cellXfs>',
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="0"/></cellXfs>',
            $existing,
        );
        $existing = str_replace('<cellXfs count="1">', '<cellXfs count="2">', $existing);
        $store = StylesStore::fromXml($existing);

        self::assertSame(2, $store->dateStyleIndex('DD.MM.YYYY'));
        self::assertTrue($store->hasChanges());
    }

    public function testRejectsEmptyStylesPart(): void
    {
        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('styles part is empty');

        StylesStore::fromXml('');
    }

    public function testRejectsStylesWithoutCellXfs(): void
    {
        $store = StylesStore::fromXml(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::MAIN_NS.'"><fonts count="1"><font/></fonts></styleSheet>',
        );

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('no <cellXfs>');

        $store->dateStyleIndex('DD.MM.YYYY');
    }

    public function testRejectsStylesWithoutStyleSheetRoot(): void
    {
        $store = StylesStore::fromXml('<?xml version="1.0" encoding="UTF-8"?><broken/>');

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('no <styleSheet> root');

        $store->dateStyleIndex('DD.MM.YYYY');
    }

    public function testBuildWithoutInitializationIsRejected(): void
    {
        $store = StylesStore::withoutFile();

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('dateStyleIndex() must run');

        $store->buildStylesXml();
    }

    public function testContentTypePatchIsAddedOnlyOnce(): void
    {
        $store = StylesStore::withoutFile();
        $store->dateStyleIndex('DD.MM.YYYY');

        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>';
        $patched = $store->contentTypesXml($types);

        self::assertIsString($patched);
        self::assertStringContainsString('PartName="/xl/styles.xml"', $patched);
        self::assertNull($store->contentTypesXml($patched));
    }

    public function testRelationshipPatchIsAddedOnlyOnce(): void
    {
        $store = StylesStore::withoutFile();
        $store->dateStyleIndex('DD.MM.YYYY');

        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
        $patched = $store->relationshipsXml($rels);

        self::assertIsString($patched);
        self::assertStringContainsString('Id="rId2"', $patched);
        self::assertStringContainsString('Target="styles.xml"', $patched);
        self::assertNull($store->relationshipsXml($patched));
    }

    public function testContentTypePatchRejectsPartWithoutClosingTag(): void
    {
        $store = StylesStore::withoutFile();
        $store->dateStyleIndex('DD.MM.YYYY');

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('[Content_Types].xml');

        $store->contentTypesXml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">');
    }

    private static function existingStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::MAIN_NS.'">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private static function isWellFormed(string $xml): bool
    {
        $dom = new \DOMDocument();

        return $dom->loadXML($xml, \LIBXML_NONET);
    }
}
