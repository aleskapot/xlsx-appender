<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\Exception\AppenderSizeLimitException;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Exception\SheetNotFoundException;
use XlsxFastAppender\SheetDataScanner;
use XlsxFastAppender\Tests\Support\FailingZip;
use XlsxFastAppender\Tests\Support\TestCase;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;
use XlsxFastAppender\WorkbookInspector;

#[CoversClass(WorkbookInspector::class)]
#[CoversClass(SheetDataScanner::class)]
final class WorkbookInspectorTest extends TestCase
{
    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function testSelectsFirstSheetWhenNameIsOmitted(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['MARKER1']]),
            'Other' => XlsxFixtureFactory::worksheet([['MARKER2']]),
        ]);
        $inspector = $this->inspector($path);

        self::assertSame(['Data', 'Other'], $inspector->sheetNames());
        self::assertSame('xl/worksheets/sheet1.xml', $inspector->resolveSheet(null));
        self::assertStringContainsString('MARKER1', $inspector->readSheetXml($inspector->resolveSheet(null), \PHP_INT_MAX));
    }

    public function testResolvesSheetNamesWithSpacesCyrillicAndXmlSpecialCharacters(): void
    {
        $names = ['Data', 'My Sheet', 'Отчёты & планы', "O'Brien <raw>"];
        $sheets = [];

        foreach ($names as $index => $name) {
            $sheets[$name] = XlsxFixtureFactory::worksheet([['MARKER'.($index + 1)]]);
        }

        $path = XlsxFixtureFactory::create($this->tempFilePath(), $sheets);
        $inspector = $this->inspector($path);

        self::assertSame($names, $inspector->sheetNames());

        foreach ($names as $index => $name) {
            $entry = $inspector->resolveSheet($name);

            self::assertStringContainsString('MARKER'.($index + 1), $inspector->readSheetXml($entry, \PHP_INT_MAX));
        }
    }

    public function testUnknownSheetReportsAvailableSheetNames(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['x']]),
            'Second' => XlsxFixtureFactory::worksheet([['y']]),
        ]);
        $inspector = $this->inspector($path);

        try {
            $inspector->resolveSheet('Nope');
            self::fail('SheetNotFoundException was not thrown.');
        } catch (SheetNotFoundException $exception) {
            self::assertSame(['Data', 'Second'], $exception->availableSheets);
            self::assertStringContainsString('Sheet "Nope" not found', $exception->getMessage());
            self::assertStringContainsString('Data, Second', $exception->getMessage());
        }
    }

    public function testUnknownSheetInWorkbookWithoutSheetsReportsNoSheets(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), []);
        $inspector = $this->inspector($path);

        self::assertSame([], $inspector->sheetNames());

        try {
            $inspector->resolveSheet('Data');
            self::fail('SheetNotFoundException was not thrown.');
        } catch (SheetNotFoundException $exception) {
            self::assertSame([], $exception->availableSheets);
            self::assertStringContainsString('(none)', $exception->getMessage());
        }
    }

    public function testNullSheetNameInWorkbookWithoutSheetsIsInvalid(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), []);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('does not contain any sheets');

        $inspector->resolveSheet(null);
    }

    public function testEmptySheetNameIsRejected(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), ['' => XlsxFixtureFactory::worksheet([['x']])]);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('references relationship');

        $inspector->resolveSheet('');
    }

    public function testThrowsWhenWorkbookXmlIsMissing(): void
    {
        $path = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/_rels/workbook.xml.rels' => self::relationships('worksheets/sheet1.xml'),
            'xl/worksheets/sheet1.xml' => XlsxFixtureFactory::worksheet([['x']]),
        ]);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('xl/workbook.xml');

        $inspector->sheetNames();
    }

    public function testThrowsWhenWorkbookRelsAreMissing(): void
    {
        $path = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/workbook.xml' => XlsxFixtureFactory::workbookXml(['Data']),
            'xl/worksheets/sheet1.xml' => XlsxFixtureFactory::worksheet([['x']]),
        ]);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('xl/_rels/workbook.xml.rels');

        $inspector->sheetNames();
    }

    public function testThrowsWhenWorkbookXmlIsMalformed(): void
    {
        $path = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/workbook.xml' => '<workbook><sheets>',
            'xl/_rels/workbook.xml.rels' => self::relationships('worksheets/sheet1.xml'),
            'xl/worksheets/sheet1.xml' => XlsxFixtureFactory::worksheet([['x']]),
        ]);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('not well-formed');

        $inspector->sheetNames();
    }

    public function testThrowsWhenSheetRelationshipIsNotDefined(): void
    {
        $path = XlsxFixtureFactory::write($this->tempFilePath(), [
            'xl/workbook.xml' => XlsxFixtureFactory::workbookXml(['Data']),
            'xl/_rels/workbook.xml.rels' => self::relationships('worksheets/sheet1.xml', 'rId9'),
            'xl/worksheets/sheet1.xml' => XlsxFixtureFactory::worksheet([['x']]),
        ]);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('references relationship "rId1"');

        $inspector->sheetNames();
    }

    public function testResolvesAbsoluteRelationshipTarget(): void
    {
        $path = $this->workbookWithRelationshipTarget('/xl/worksheets/sheet1.xml');
        $inspector = $this->inspector($path);

        self::assertSame('xl/worksheets/sheet1.xml', $inspector->resolveSheet('Data'));
    }

    public function testResolvesRelationshipTargetAlreadyPrefixedWithXl(): void
    {
        $path = $this->workbookWithRelationshipTarget('xl/worksheets/sheet1.xml');
        $inspector = $this->inspector($path);

        self::assertSame('xl/worksheets/sheet1.xml', $inspector->resolveSheet('Data'));
    }

    public function testResolvesUrlEncodedRelationshipTarget(): void
    {
        $path = $this->workbookWithRelationshipTarget(
            'worksheets/sheet%20one.xml',
            ['xl/worksheets/sheet one.xml' => XlsxFixtureFactory::worksheet([['ENCODED']])],
        );
        $inspector = $this->inspector($path);

        self::assertSame('xl/worksheets/sheet one.xml', $inspector->resolveSheet('Data'));
    }

    public function testThrowsWhenResolvedWorksheetPartIsMissing(): void
    {
        $path = $this->workbookWithRelationshipTarget('worksheets/absent.xml');
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('is missing from');

        $inspector->resolveSheet('Data');
    }

    public function testScanFindsHighestRowAcrossGaps(): void
    {
        $sheetXml = XlsxFixtureFactory::wrapWorksheet(
            '<dimension ref="A1:A10"/>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>b</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>c</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>d</t></is></c></row>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>e</t></is></c></row>'
            .'<row r="10"><c r="A10" t="inlineStr"><is><t>j</t></is></c></row>',
        );
        $result = $this->inspectorPlaceholder()->scan($sheetXml);

        self::assertSame(10, $result->lastRow);
        self::assertSame(6, $result->rowCount);
        self::assertFalse($result->isEmpty());
    }

    public function testScanReportsEmptySelfClosedSheetData(): void
    {
        $result = $this->inspectorPlaceholder()->scan(XlsxFixtureFactory::emptyWorksheet());

        self::assertSame(0, $result->lastRow);
        self::assertSame(0, $result->rowCount);
        self::assertTrue($result->isEmpty());
    }

    public function testScanReportsEmptyExplicitlyClosedSheetData(): void
    {
        $result = $this->inspectorPlaceholder()->scan(XlsxFixtureFactory::wrapWorksheet(''));

        self::assertSame(0, $result->lastRow);
        self::assertTrue($result->isEmpty());
    }

    public function testScanNumbersRowsWithoutRowAttributesSequentially(): void
    {
        $sheetXml = XlsxFixtureFactory::wrapWorksheet(
            '<row><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>'
            .'<row><c r="A2" t="inlineStr"><is><t>b</t></is></c></row>'
            .'<row><c r="A3" t="inlineStr"><is><t>c</t></is></c></row>',
        );
        $result = $this->inspectorPlaceholder()->scan($sheetXml);

        self::assertSame(3, $result->lastRow);
        self::assertSame(3, $result->rowCount);
    }

    public function testScanRejectsWorksheetWithoutSheetData(): void
    {
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:A1"/></worksheet>';

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('no <sheetData>');

        $this->inspectorPlaceholder()->scan($sheetXml);
    }

    public function testScanRejectsUnclosedSheetData(): void
    {
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>';

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('is malformed');

        $this->inspectorPlaceholder()->scan($sheetXml);
    }

    public function testScanRejectsMalformedXml(): void
    {
        $sheetXml = '<?xml version="1.0"?><worksheet><sheetData></row></worksheet>';

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('is malformed');

        $this->inspectorPlaceholder()->scan($sheetXml);
    }

    public function testScanRejectsEmptyWorksheetXml(): void
    {
        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('is not well-formed');

        $this->inspectorPlaceholder()->scan('');
    }

    public function testReadSheetXmlRespectsTheConfiguredSizeLimit(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['payload']]),
        ]);
        $inspector = $this->inspector($path);

        $this->expectException(AppenderSizeLimitException::class);
        $this->expectExceptionMessage('max_sheet_xml_size');

        $inspector->readSheetXml('xl/worksheets/sheet1.xml', 16);
    }

    public function testReadSheetXmlFailsForUnknownPart(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['x']]),
        ]);
        $inspector = $this->inspector($path);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('missing from');

        $inspector->readSheetXml('xl/worksheets/absent.xml', \PHP_INT_MAX);
    }

    public function testReadSheetXmlFailsWhenArchiveReadFailsMidway(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['payload']]),
        ]);
        $zip = new FailingZip();

        if ($zip->open($path) !== true) {
            self::fail(\sprintf('Unable to open fixture "%s".', $path));
        }

        $this->trackZip($zip);
        $zip->failReads = true;
        $inspector = new WorkbookInspector($path, $zip);

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('Unable to read worksheet part');

        $inspector->readSheetXml('xl/worksheets/sheet1.xml', \PHP_INT_MAX);
    }

    public function testScansLibreOfficeStyleWorksheet(): void
    {
        $worksheet = XlsxFixtureFactory::wrapWorksheet(
            '<row r="1" spans="1:3" s="1" customFormat="1">'
            .'<c r="A1" s="1" t="inlineStr"><is><t>LO1</t></is></c></row>'
            .'<row r="2" spans="1:3"><c r="A2" s="1" t="inlineStr"><is><t>LO2</t></is></c></row>',
            '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/><pageSetUpPr/></sheetPr>'
            .'<sheetFormatPr defaultRowHeight="15"/>',
            '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape"/>'
            .'<legacyDrawing r:id="rId1"/>',
        );
        $path = XlsxFixtureFactory::create($this->tempFilePath(), ['Data' => $worksheet]);
        $inspector = $this->inspector($path);

        $xml = $inspector->readSheetXml($inspector->resolveSheet('Data'), \PHP_INT_MAX);
        $result = $inspector->scan($xml);

        self::assertStringContainsString('LO1', $xml);
        self::assertStringContainsString('<pageMargins', $xml);
        self::assertSame(2, $result->lastRow);
        self::assertSame(2, $result->rowCount);
    }

    public function testScansGoogleSheetsStyleWorksheet(): void
    {
        $worksheet = XlsxFixtureFactory::wrapWorksheet(
            '<row r="1" ht="21" customHeight="1">'
            .'<c r="A1" t="inlineStr"><is><t>GS1</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>GS2</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>GS3</t></is></c></row>',
            '<dimension ref="A1:C3"/><sheetViews><sheetView workbookViewId="0"/></sheetViews>',
            '<autoFilter ref="A1:C1"/>'
            .'<mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells>',
        );
        $path = XlsxFixtureFactory::create($this->tempFilePath(), ['Data' => $worksheet]);
        $inspector = $this->inspector($path);

        $xml = $inspector->readSheetXml($inspector->resolveSheet('Data'), \PHP_INT_MAX);
        $result = $inspector->scan($xml);

        self::assertStringContainsString('GS1', $xml);
        self::assertStringContainsString('<mergeCells', $xml);
        self::assertSame(3, $result->lastRow);
        self::assertSame(3, $result->rowCount);
    }

    public function testScansPhpSpreadsheetGeneratedFile(): void
    {
        $path = $this->createPhpSpreadsheetFile();
        $inspector = $this->inspector($path);

        self::assertSame(['Data'], $inspector->sheetNames());

        $xml = $inspector->readSheetXml($inspector->resolveSheet('Data'), \PHP_INT_MAX);
        $result = $inspector->scan($xml);

        self::assertSame(4, $result->lastRow);
        self::assertSame(4, $result->rowCount);
    }

    private function inspector(string $path): WorkbookInspector
    {
        return new WorkbookInspector($path, $this->openZip($path));
    }

    public function testReadSharedStringsReturnsNullWhenThePartIsMissing(): void
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), ['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        self::assertNull($this->inspector($path)->readSharedStrings(\PHP_INT_MAX));
    }

    public function testReadSharedStringsReturnsTheExistingPart(): void
    {
        $sst = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
            .'<si><t>a</t></si></sst>';
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::worksheet([['a']])],
            ['xl/sharedStrings.xml' => $sst],
        );

        self::assertSame($sst, $this->inspector($path)->readSharedStrings(\PHP_INT_MAX));
    }

    public function testReadSharedStringsEnforcesTheSizeLimit(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::worksheet([['a']])],
            ['xl/sharedStrings.xml' => str_repeat('x', 512)],
        );

        $this->expectException(AppenderSizeLimitException::class);
        $this->expectExceptionMessage('max_sheet_xml_size');

        $this->inspector($path)->readSharedStrings(100);
    }

    public function testReadSharedStringsRejectsAnUnreadablePart(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::worksheet([['a']])],
            ['xl/sharedStrings.xml' => '<sst/>'],
        );
        $zip = new FailingZip();
        self::assertTrue($zip->open($path));
        $zip->failReads = true;

        try {
            (new WorkbookInspector($path, $zip))->readSharedStrings(\PHP_INT_MAX);
            self::fail('Expected InvalidWorkbookException.');
        } catch (InvalidWorkbookException $exception) {
            self::assertStringContainsString('sharedStrings', $exception->getMessage());
        } finally {
            $zip->close();
        }
    }

    /**
     * Inspector over a throwaway archive for tests that only exercise scan().
     */
    private function inspectorPlaceholder(): WorkbookInspector
    {
        $path = XlsxFixtureFactory::create($this->tempFilePath(), [
            'Data' => XlsxFixtureFactory::worksheet([['placeholder']]),
        ]);

        return $this->inspector($path);
    }

    /**
     * @param array<string, string> $extraEntries
     */
    private function workbookWithRelationshipTarget(string $target, array $extraEntries = []): string
    {
        return XlsxFixtureFactory::write($this->tempFilePath(), array_merge([
            'xl/workbook.xml' => XlsxFixtureFactory::workbookXml(['Data']),
            'xl/_rels/workbook.xml.rels' => self::relationships($target),
            'xl/worksheets/sheet1.xml' => XlsxFixtureFactory::worksheet([['x']]),
        ], $extraEntries));
    }

    private function createPhpSpreadsheetFile(): string
    {
        $path = $this->tempFilePath();
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->fromArray(
            [
                ['id', 'name'],
                [1, 'Alpha'],
                [2, 'Beta'],
                [3, 'Gamma'],
            ],
            null,
            'A1',
        );

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private static function relationships(string $target, string $id = 'rId1'): string
    {
        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="%s">'
            .'<Relationship Id="%s" Type="%s/worksheet" Target="%s"/>'
            .'</Relationships>',
            self::PACKAGE_REL_NS,
            $id,
            self::DOC_REL_NS,
            $target,
        );
    }
}
