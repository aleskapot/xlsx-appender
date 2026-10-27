<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Tests\Support\TestCase;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;
use XlsxFastAppender\WorkbookInspector;
use XlsxFastAppender\WorkbookSheetCreator;

#[CoversClass(WorkbookSheetCreator::class)]
final class WorkbookSheetCreatorTest extends TestCase
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function testBuildsSheetPartAndRegistrations(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['existing']]),
        ]);
        $zip = $this->openZip($path);

        $created = (new WorkbookSheetCreator($path, $zip))->create('New');

        self::assertSame('xl/worksheets/sheet2.xml', $created->entry);
        self::assertStringContainsString('<sheetData/>', $created->worksheetXml);
        self::assertStringContainsString(
            '<sheet name="New" sheetId="2" r:id="rId2"/>',
            $created->workbookXml,
        );
        self::assertStringContainsString(
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>',
            $created->relationshipsXml,
        );
        self::assertStringContainsString(
            '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
            $created->contentTypesXml,
        );
        self::assertSame(
            XlsxFixtureFactory::worksheet([['existing']]),
            $this->entry($zip, 'xl/worksheets/sheet1.xml'),
        );
        self::assertFalse($zip->locateName('xl/worksheets/sheet2.xml') !== false);
    }

    public function testChoosesUnusedPartNameAndIds(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            [
                'Data' => XlsxFixtureFactory::worksheet([['a']]),
                'Other' => XlsxFixtureFactory::worksheet([['b']]),
            ],
            ['xl/worksheets/sheet5.xml' => XlsxFixtureFactory::emptyWorksheet()],
        );
        $zip = $this->openZip($path);

        $created = (new WorkbookSheetCreator($path, $zip))->create('Third');

        self::assertSame('xl/worksheets/sheet6.xml', $created->entry);
        self::assertStringContainsString(
            '<sheet name="Third" sheetId="3" r:id="rId3"/>',
            $created->workbookXml,
        );
        self::assertStringContainsString(
            'Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet6.xml"',
            $created->relationshipsXml,
        );
    }

    public function testAddsTheRelationshipNamespaceWhenTheWorkbookDoesNotDeclareIt(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            [],
            [
                'xl/workbook.xml' => \sprintf(
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<workbook xmlns="%s"><sheets></sheets></workbook>',
                    self::MAIN_NS,
                ),
            ],
        );
        $zip = $this->openZip($path);

        $created = (new WorkbookSheetCreator($path, $zip))->create('First');

        self::assertStringContainsString('xmlns:r="'.self::DOC_REL_NS.'"', $created->workbookXml);
        self::assertStringContainsString('<sheet name="First" sheetId="1" r:id="rId1"/>', $created->workbookXml);

        $checkPath = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/workbook.xml' => $created->workbookXml,
            'xl/_rels/workbook.xml.rels' => $created->relationshipsXml,
            '[Content_Types].xml' => $created->contentTypesXml,
            'xl/worksheets/sheet1.xml' => $created->worksheetXml,
        ]);
        $inspector = new WorkbookInspector($checkPath, $this->openZip($checkPath));

        self::assertSame($created->entry, $inspector->resolveSheet('First'));
    }

    public function testUsesAnotherPrefixWhenRIsBoundToAnotherNamespace(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            [],
            [
                'xl/workbook.xml' => \sprintf(
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<workbook xmlns="%s" xmlns:r="urn:example:other"><sheets/></workbook>',
                    self::MAIN_NS,
                ),
            ],
        );
        $zip = $this->openZip($path);

        $created = (new WorkbookSheetCreator($path, $zip))->create('First');

        self::assertStringContainsString('xmlns:r1="'.self::DOC_REL_NS.'"', $created->workbookXml);
        self::assertStringContainsString('<sheet name="First" sheetId="1" r1:id="rId1"/>', $created->workbookXml);

        $checkPath = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/workbook.xml' => $created->workbookXml,
            'xl/_rels/workbook.xml.rels' => $created->relationshipsXml,
            '[Content_Types].xml' => $created->contentTypesXml,
            'xl/worksheets/sheet1.xml' => $created->worksheetXml,
        ]);
        $inspector = new WorkbookInspector($checkPath, $this->openZip($checkPath));

        self::assertSame($created->entry, $inspector->resolveSheet('First'));
    }

    public function testDoesNotReuseAPrefixDeclaredOnlyOnAChildElement(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            [],
            [
                'xl/workbook.xml' => \sprintf(
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<workbook xmlns="%s"><sheets/><extLst><ext xmlns:r="%s"/></extLst></workbook>',
                    self::MAIN_NS,
                    self::DOC_REL_NS,
                ),
            ],
        );
        $zip = $this->openZip($path);

        $created = (new WorkbookSheetCreator($path, $zip))->create('First');

        self::assertStringContainsString(
            '<workbook xmlns:r="'.self::DOC_REL_NS.'" xmlns="'.self::MAIN_NS.'">',
            $created->workbookXml,
        );
        self::assertStringContainsString('<sheet name="First" sheetId="1" r:id="rId1"/>', $created->workbookXml);
    }

    public function testReadsSingleQuotedRelationshipIds(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::worksheet([['existing']])],
            [
                'xl/_rels/workbook.xml.rels' => \sprintf(
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id=\'rId1\' Type="%s/worksheet" Target="worksheets/sheet1.xml"/>'
                    .'</Relationships>',
                    self::DOC_REL_NS,
                ),
            ],
        );
        $zip = $this->openZip($path);

        $created = (new WorkbookSheetCreator($path, $zip))->create('New');

        self::assertStringContainsString('Id="rId2"', $created->relationshipsXml);
    }

    public function testRejectsAnExistingSheetNameCaseInsensitively(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['existing']]),
        ]);
        $zip = $this->openZip($path);

        try {
            (new WorkbookSheetCreator($path, $zip))->create('data');
            self::fail('Expected InvalidOptionException.');
        } catch (InvalidOptionException $exception) {
            self::assertStringContainsString('case-insensitive', $exception->getMessage());
        }
    }

    public function testRejectsAMalformedWorkbook(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::worksheet([['existing']])],
            ['xl/workbook.xml' => '<workbook><sheets>'],
        );
        $zip = $this->openZip($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('not well-formed');

        (new WorkbookSheetCreator($path, $zip))->create('New');
    }

    public function testRejectsAWorkbookWithoutContentTypes(): void
    {
        $path = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/workbook.xml' => XlsxFixtureFactory::workbookXml(['Data']),
            'xl/_rels/workbook.xml.rels' => XlsxFixtureFactory::workbookRelationships(1),
            'xl/worksheets/sheet1.xml' => XlsxFixtureFactory::worksheet([['existing']]),
        ]);
        $zip = $this->openZip($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('[Content_Types].xml');

        (new WorkbookSheetCreator($path, $zip))->create('New');
    }

    private function entry(\ZipArchive $zip, string $entry): string
    {
        $contents = $zip->getFromName($entry);

        if ($contents === false) {
            self::fail(\sprintf('Entry "%s" is missing.', $entry));
        }

        return $contents;
    }
}
