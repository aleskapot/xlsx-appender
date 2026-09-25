<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\SharedStringsStore;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(SharedStringsStore::class)]
final class SharedStringsStoreTest extends TestCase
{
    public function testFreshStoreBuildsSstPartWithNamespaceAndCounts(): void
    {
        $store = SharedStringsStore::withoutFile();

        self::assertSame(0, $store->index('hello'));
        self::assertSame(1, $store->index('world'));
        self::assertSame(0, $store->index('hello'));
        self::assertTrue($store->hasNewReferences());
        self::assertFalse($store->sharedStringsFileExisted());

        $xml = $store->buildSstXml();

        self::assertStringContainsString('xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"', $xml);
        self::assertStringContainsString('count="3"', $xml);
        self::assertStringContainsString('uniqueCount="2"', $xml);
        self::assertStringContainsString('<si><t>hello</t></si>', $xml);
        self::assertStringContainsString('<si><t>world</t></si>', $xml);
        self::assertMatchesRegularExpression('/^<\?xml version="1.0" encoding="UTF-8" standalone="yes"\?><sst\b/', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testExistingPartIsParsedAndDuplicatesReuseTheFirstOrdinal(): void
    {
        $store = SharedStringsStore::fromXml(
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="2">'
            .'<si><t>a</t></si><si><t>b</t></si></sst>',
        );

        self::assertTrue($store->sharedStringsFileExisted());
        self::assertSame(0, $store->index('a'));
        self::assertSame(1, $store->index('b'));
        self::assertSame(0, $store->index('a'));
        self::assertSame(2, $store->index('c'));

        $xml = $store->buildSstXml();

        self::assertStringContainsString('count="7"', $xml);
        self::assertStringContainsString('uniqueCount="3"', $xml);
        self::assertSame(1, substr_count($xml, '<si><t>a</t></si>'));
        self::assertStringEndsWith('<si><t>c</t></si></sst>', $xml);
    }

    public function testStaleCountAndUniqueCountAttributesAreRefreshed(): void
    {
        $store = SharedStringsStore::fromXml(
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="999" uniqueCount="777">'
            .'<si><t>old</t></si></sst>',
        );

        $store->index('new');

        $xml = $store->buildSstXml();

        self::assertStringContainsString('count="1000"', $xml);
        self::assertStringNotContainsString('uniqueCount="777"', $xml);
        self::assertStringContainsString('uniqueCount="2"', $xml);
    }

    public function testRichTextRunsAreConcatenatedAndPhoneticRunsAreIgnored(): void
    {
        $store = SharedStringsStore::fromXml(
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
            .'<si><r><t>be</t></r><r><t>ta</t></r>'
            .'<rPh sb="0" eb="2"><t>フ</t></rPh></si></sst>',
        );

        self::assertSame(0, $store->index('beta'));

        $xml = $store->buildSstXml();

        self::assertStringNotContainsString('<t>beta</t>', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testEmptySiElementMapsToTheEmptyString(): void
    {
        $store = SharedStringsStore::fromXml(
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
            .'<si/></sst>',
        );

        self::assertSame(0, $store->index(''));
        self::assertSame(1, $store->index('x'));
    }

    public function testSelfClosedSstPartIsTurnedIntoAnOpenElement(): void
    {
        $store = SharedStringsStore::fromXml(
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"/>',
        );

        $store->index('first');

        $xml = $store->buildSstXml();

        self::assertStringContainsString('count="1"', $xml);
        self::assertStringContainsString('uniqueCount="1"', $xml);
        self::assertStringEndsWith('<si><t>first</t></si></sst>', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    public function testMissingClosingSstTagIsRejected(): void
    {
        $store = SharedStringsStore::fromXml('<sst><si><t>a</t></si>');

        $store->index('b');

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('no closing </sst>');

        $store->buildSstXml();
    }

    public function testEmptySstPartIsRejected(): void
    {
        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('empty');

        SharedStringsStore::fromXml('');
    }

    public function testEdgeSpacesArePreservedAndSpecialCharactersAreEscaped(): void
    {
        $store = SharedStringsStore::withoutFile();
        $store->index('  padded  ');
        $store->index('<a> & "b"');

        $xml = $store->buildSstXml();

        self::assertStringContainsString('<t xml:space="preserve">  padded  </t>', $xml);
        self::assertStringContainsString('<t>&lt;a&gt; &amp; &quot;b&quot;</t>', $xml);

        $dom = self::loadDom($xml);

        $texts = [];
        foreach ($dom->getElementsByTagName('t') as $node) {
            $texts[] = $node->textContent;
        }

        self::assertSame(['  padded  ', '<a> & "b"'], $texts);
    }

    public function testContentTypesRegistrationIsAddedBeforeTheClosingTag(): void
    {
        $store = SharedStringsStore::withoutFile();
        $current = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/></Types>';

        $patched = $store->contentTypesXml($current);

        self::assertNotNull($patched);
        self::assertStringContainsString(
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>',
            $patched,
        );
        self::assertStringEndsWith('</Types>', $patched);
        self::assertTrue(self::isWellFormed($patched));
        self::assertNull($store->contentTypesXml($patched));
    }

    public function testContentTypesWithoutClosingTagIsRejected(): void
    {
        $store = SharedStringsStore::withoutFile();

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('[Content_Types].xml');

        $store->contentTypesXml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">');
    }

    public function testRelationshipRegistrationUsesTheNextFreeRid(): void
    {
        $store = SharedStringsStore::withoutFile();
        $current = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/workbook" Target="workbook.xml"/>'
            .'<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $patched = $store->relationshipsXml($current);

        self::assertNotNull($patched);
        self::assertStringContainsString(
            '<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>',
            $patched,
        );
        self::assertTrue(self::isWellFormed($patched));
        self::assertNull($store->relationshipsXml($patched));
    }

    public function testRelationshipsWithoutClosingTagAreRejected(): void
    {
        $store = SharedStringsStore::withoutFile();

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('workbook.xml.rels');

        $store->relationshipsXml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">');
    }

    public function testNoReferencesMeansHasNewReferencesIsFalse(): void
    {
        $store = SharedStringsStore::fromXml(
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
            .'<si><t>a</t></si></sst>',
        );

        self::assertFalse($store->hasNewReferences());
    }

    private static function isWellFormed(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $valid = simplexml_load_string($xml) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid;
    }

    private static function loadDom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        return $dom;
    }
}
