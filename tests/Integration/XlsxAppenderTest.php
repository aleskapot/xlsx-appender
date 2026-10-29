<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\ConflictMode;
use XlsxFastAppender\Exception\AppenderSizeLimitException;
use XlsxFastAppender\Exception\ColumnCountMismatchException;
use XlsxFastAppender\Exception\HeaderConflictException;
use XlsxFastAppender\Exception\InvalidCellReferenceException;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Exception\LockTimeoutException;
use XlsxFastAppender\Exception\SheetNotFoundException;
use XlsxFastAppender\Exception\StartCellConflictException;
use XlsxFastAppender\Exception\UnsupportedArchiveException;
use XlsxFastAppender\Exception\UnsupportedValueException;
use XlsxFastAppender\Exception\WriteFailedException;
use XlsxFastAppender\SharedStringsStore;
use XlsxFastAppender\StringMode;
use XlsxFastAppender\StylesStore;
use XlsxFastAppender\Tests\Support\FailingZip;
use XlsxFastAppender\Tests\Support\TestCase;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;
use XlsxFastAppender\XlsxAppender;

#[CoversClass(XlsxAppender::class)]
final class XlsxAppenderTest extends TestCase
{
    private const SHEET_ENTRY = 'xl/worksheets/sheet1.xml';

    public function testAppendsRowsBelowExistingData(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a', 'b'], ['c', 'd']])]);
        $workbookBefore = $this->readEntry($path, 'xl/workbook.xml');

        $written = (new XlsxAppender($path, ['start_cell' => 'A3']))->append([
            ['x', 1],
            ['y', 2],
        ]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="3">', $sheet);
        self::assertStringContainsString('<row r="4">', $sheet);
        self::assertStringContainsString('ref="A1:B4"', $sheet);
        self::assertSame(4, preg_match_all('/<row\b/', $sheet));
        self::assertSame($workbookBefore, $this->readEntry($path, 'xl/workbook.xml'));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testAppendsToEmptySheetAtCellA1(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path))->append([['first'], ['second']]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="1">', $sheet);
        self::assertStringContainsString('<row r="2">', $sheet);
        self::assertSame(2, preg_match_all('/<row\b/', $sheet));
    }

    public function testStartCellInsideExistingDataThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([
            ['1'], ['2'], ['3'], ['4'], ['5'],
        ])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A3']))->append([['x']]);
            self::fail('Expected StartCellConflictException.');
        } catch (StartCellConflictException $exception) {
            self::assertStringContainsString('already contains data up to row 5', $exception->getMessage());
            self::assertStringContainsString('A6', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testStartCellAtExistingLastRowThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['1'], ['2'], ['3']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A3']))->append([['x']]);
            self::fail('Expected StartCellConflictException.');
        } catch (StartCellConflictException $exception) {
            self::assertStringContainsString('up to row 3', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testStartInsideARowGapThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::wrapWorksheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>1</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>2</t></is></c></row>'
            .'<row r="10"><c r="A10" t="inlineStr"><is><t>10</t></is></c></row>',
            '<dimension ref="A1:A10"/>',
        )]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A6']))->append([['x']]);
            self::fail('Expected StartCellConflictException.');
        } catch (StartCellConflictException $exception) {
            self::assertStringContainsString('up to row 10', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testStartsAfterAGapAtTheNextFreeRow(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::wrapWorksheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>1</t></is></c></row>'
            .'<row r="10"><c r="A10" t="inlineStr"><is><t>10</t></is></c></row>',
            '<dimension ref="A1:A10"/>',
        )]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A11']))->append([['after-gap']]);

        self::assertSame(1, $written);
        self::assertStringContainsString('<row r="11">', $this->readEntry($path, self::SHEET_ENTRY));
    }

    public function testConflictModeClearRemovesRowsFromStartRowToTheEnd(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([
            ['1'], ['2'], ['3'], ['4'], ['5'],
        ])]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A3',
            'conflict_mode' => 'clear',
        ]))->append([['x'], ['y']]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(['1', '2', '3', '4'], $this->rowNumbers($sheet));
        self::assertStringContainsString('<t>1</t>', $sheet);
        self::assertStringNotContainsString('<t>5</t>', $sheet);
        self::assertStringContainsString('<t>x</t>', $sheet);
        self::assertStringContainsString('<t>y</t>', $sheet);
        self::assertStringContainsString('ref="A1:A4"', $sheet);
        self::assertFileDoesNotExist($path.'.tmp');
        self::assertFileDoesNotExist($path.'.tmp.rows');
    }

    public function testConflictModeClearBelowExistingDataActsLikeAppend(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['1'], ['2']])]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A3',
            'conflict_mode' => 'clear',
        ]))->append([['x']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(['1', '2', '3'], $this->rowNumbers($sheet));
        self::assertStringContainsString('<t>1</t>', $sheet);
        self::assertStringContainsString('ref="A1:A3"', $sheet);
    }

    public function testConflictModeClearWithNoRecordsLeavesFileByteIdentical(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['1'], ['2'], ['3']])]);
        $before = file_get_contents($path);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'conflict_mode' => 'clear',
        ]))->append([]);

        self::assertSame(0, $written);
        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testConflictModeOverwriteReplacesCoveredRowsAndKeepsTheRest(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([
            ['1'], ['2'], ['3'], ['4'], ['5'],
        ])]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A3',
            'conflict_mode' => 'overwrite',
        ]))->append([['x']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(['1', '2', '3', '4', '5'], $this->rowNumbers($sheet));
        self::assertStringNotContainsString('<t>3</t>', $sheet);
        self::assertStringContainsString('<t>x</t>', $sheet);
        self::assertStringContainsString('<t>4</t>', $sheet);
        self::assertStringContainsString('<t>5</t>', $sheet);
        self::assertStringContainsString('ref="A1:A5"', $sheet);
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testConflictModeOverwriteExtendsPastExistingData(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['1'], ['2'], ['3']])]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'conflict_mode' => 'overwrite',
        ]))->append([['a'], ['b'], ['c'], ['d']]);

        self::assertSame(4, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(['1', '2', '3', '4', '5'], $this->rowNumbers($sheet));
        self::assertStringContainsString('<t>1</t>', $sheet);
        self::assertStringContainsString('<t>d</t>', $sheet);
        self::assertStringContainsString('ref="A1:A5"', $sheet);
    }

    public function testConflictModeOverwriteInsideARowGapKeepsLaterRows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::wrapWorksheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>1</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>2</t></is></c></row>'
            .'<row r="10"><c r="A10" t="inlineStr"><is><t>10</t></is></c></row>',
            '<dimension ref="A1:A10"/>',
        )]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A6',
            'conflict_mode' => 'overwrite',
        ]))->append([['x']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(['1', '2', '6', '10'], $this->rowNumbers($sheet));
        self::assertStringContainsString('<t>10</t>', $sheet);
        self::assertStringContainsString('ref="A1:A10"', $sheet);
    }

    public function testConflictModeEnumBehavesLikeItsStringValue(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([
            ['1'], ['2'], ['3'], ['4'], ['5'],
        ])]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A3',
            'conflict_mode' => ConflictMode::Clear,
        ]))->append([['x'], ['y']]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(['1', '2', '3', '4'], $this->rowNumbers($sheet));
        self::assertStringNotContainsString('<t>5</t>', $sheet);
        self::assertStringContainsString('<t>x</t>', $sheet);
        self::assertStringContainsString('<t>y</t>', $sheet);
        self::assertStringContainsString('ref="A1:A4"', $sheet);
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testEmptyIterableReturnsZeroAndKeepsFileUntouched(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['keep']])]);
        $before = file_get_contents($path);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2']))->append([]);

        self::assertSame(0, $written);
        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testWriteHeaderInitializesEmptySheetWithData(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'columns' => ['name' => 'Имя', 'amount' => 'Сумма'],
            'write_header' => true,
        ]))->append([
            ['Значение', 10],
            ['Ещё', 20],
        ]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="1">', $sheet);
        self::assertStringContainsString('Имя', $sheet);
        self::assertStringContainsString('Сумма', $sheet);
        self::assertStringContainsString('<row r="3">', $sheet);
        self::assertStringContainsString('ref="A1:B3"', $sheet);
        self::assertSame(3, preg_match_all('/<row\b/', $sheet));
    }

    public function testWriteHeaderOnlyRunReturnsZeroButWritesHeader(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'columns' => ['id', 'name'],
            'write_header' => true,
        ]))->append([]);

        self::assertSame(0, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="1">', $sheet);
        self::assertStringContainsString('id', $sheet);
        self::assertSame(1, preg_match_all('/<row\b/', $sheet));
    }

    public function testWriteHeaderOnNonEmptySheetThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, [
                'start_cell' => 'A2',
                'columns' => ['a'],
                'write_header' => true,
            ]))->append([['x']]);
            self::fail('Expected HeaderConflictException.');
        } catch (HeaderConflictException $exception) {
            self::assertStringContainsString('C23', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testWriteHeaderAtRowOneThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('C22');

        (new XlsxAppender($path, [
            'start_cell' => 'A1',
            'columns' => ['a'],
            'write_header' => true,
        ]))->append([['x']]);
    }

    public function testWriteHeaderWithoutColumnsThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('requires the "columns" option');

        (new XlsxAppender($path, ['start_cell' => 'A2', 'write_header' => true]))->append([['x']]);
    }

    public function testSecondAppendUsesAFreshScan(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['first-old']])]);

        $written1 = (new XlsxAppender($path, ['start_cell' => 'A2']))->append([['a'], ['b']]);
        $written2 = (new XlsxAppender($path, ['start_cell' => 'A4']))->append([['c']]);

        self::assertSame(2, $written1);
        self::assertSame(1, $written2);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(4, preg_match_all('/<row\b/', $sheet));
        self::assertStringContainsString('<row r="4">', $sheet);
    }

    public function testColumnWidthOverflowThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('C26');

        (new XlsxAppender($path, [
            'start_cell' => 'XFD1',
            'columns' => ['a', 'b'],
        ]))->append([['x', 'y']]);
    }

    public function testWorksheetSizeLimitThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, [
                'start_cell' => 'A2',
                'max_sheet_xml_size' => 64,
            ]))->append([['x']]);
            self::fail('Expected AppenderSizeLimitException.');
        } catch (AppenderSizeLimitException) {
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testMissingFileThrowsInvalidWorkbook(): void
    {
        $path = $this->tempFilePath();

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('does not exist');

        (new XlsxAppender($path))->append([['x']]);
    }

    public function testNotAZipFileThrowsInvalidWorkbook(): void
    {
        $path = $this->tempFilePath();
        file_put_contents($path, 'this is not a zip archive');

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('Unable to open');

        (new XlsxAppender($path))->append([['x']]);
    }

    public function testUnknownSheetNameThrowsSheetNotFound(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $this->expectException(SheetNotFoundException::class);

        (new XlsxAppender($path, ['sheet' => 'Missing']))->append([['x']]);
    }

    public function testCreateSheetIfMissingCreatesSheetAndAppendsRows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);
        $originalSheet = $this->readEntry($path, self::SHEET_ENTRY);

        $written = (new XlsxAppender($path, [
            'sheet' => 'New Sheet',
            'create_sheet_if_missing' => true,
        ]))->append([['created', 42]]);

        self::assertSame(1, $written);

        $newSheet = $this->readEntry($path, 'xl/worksheets/sheet2.xml');
        self::assertStringContainsString('<row r="1">', $newSheet);
        self::assertStringContainsString('<t>created</t>', $newSheet);
        self::assertStringContainsString('<v>42</v>', $newSheet);
        self::assertSame($originalSheet, $this->readEntry($path, self::SHEET_ENTRY));

        $workbook = $this->readEntry($path, 'xl/workbook.xml');
        self::assertStringContainsString('<sheet name="New Sheet" sheetId="2" r:id="rId2"/>', $workbook);

        $relationships = $this->readEntry($path, 'xl/_rels/workbook.xml.rels');
        self::assertStringContainsString('Id="rId2"', $relationships);
        self::assertStringContainsString('Target="worksheets/sheet2.xml"', $relationships);

        $contentTypes = $this->readEntry($path, '[Content_Types].xml');
        self::assertStringContainsString('PartName="/xl/worksheets/sheet2.xml"', $contentTypes);
        self::assertStringContainsString('spreadsheetml.worksheet+xml', $contentTypes);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        try {
            self::assertSame(['Data', 'New Sheet'], $spreadsheet->getSheetNames());
            $sheet = $spreadsheet->getSheetByName('New Sheet');
            self::assertNotNull($sheet);
            self::assertSame('created', $sheet->getCell('A1')->getFormattedValue());
            self::assertSame('42', $sheet->getCell('B1')->getFormattedValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $written = (new XlsxAppender($path, ['sheet' => 'New Sheet', 'start_cell' => 'A2']))
            ->append([['second']]);

        self::assertSame(1, $written);
        self::assertStringContainsString('<row r="2">', $this->readEntry($path, 'xl/worksheets/sheet2.xml'));
    }

    public function testCreateSheetIfMissingCreatesFirstSheetInWorkbookWithoutSheets(): void
    {
        $path = $this->fixture([]);

        $written = (new XlsxAppender($path, [
            'sheet' => 'First',
            'create_sheet_if_missing' => true,
        ]))->append([['x']]);

        self::assertSame(1, $written);
        self::assertStringContainsString('<row r="1">', $this->readEntry($path, 'xl/worksheets/sheet1.xml'));
        self::assertStringContainsString('<sheet name="First" sheetId="1" r:id="rId1"/>', $this->readEntry($path, 'xl/workbook.xml'));
    }

    public function testCreateSheetIfMissingKeepsEmptyBatchAsANoOp(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);
        $before = file_get_contents($path);

        $written = (new XlsxAppender($path, [
            'sheet' => 'Missing',
            'create_sheet_if_missing' => true,
        ]))->append([]);

        self::assertSame(0, $written);
        self::assertSame($before, file_get_contents($path));
    }

    public function testCreateSheetIfMissingRollsBackWhenRegistrationFails(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);
        $before = file_get_contents($path);

        $appender = new XlsxAppender(
            $path,
            ['sheet' => 'New', 'create_sheet_if_missing' => true],
            static function (): FailingZip {
                $zip = new FailingZip();
                $zip->failAddFileEntry = 'xl/_rels/workbook.xml.rels';

                return $zip;
            },
        );

        try {
            $appender->append([['x']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('workbook relationships part', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));

        $zip = $this->openZip($path);
        self::assertFalse($zip->locateName('xl/worksheets/sheet2.xml') !== false);
    }

    public function testCreateSheetIfMissingSupportsHeaderAndSharedStrings(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);

        $written = (new XlsxAppender($path, [
            'sheet' => 'Report',
            'create_sheet_if_missing' => true,
            'start_cell' => 'A2',
            'columns' => ['name' => 'Name'],
            'write_header' => true,
            'mode' => 'shared_strings',
        ]))->append([['name' => 'Ada']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, 'xl/worksheets/sheet2.xml');
        self::assertStringContainsString('<row r="1">', $sheet);
        self::assertStringContainsString('<row r="2">', $sheet);

        $sst = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('<si><t>Name</t></si>', $sst);
        self::assertStringContainsString('<si><t>Ada</t></si>', $sst);

        $relationships = $this->readEntry($path, 'xl/_rels/workbook.xml.rels');
        self::assertStringContainsString('/relationships/worksheet', $relationships);
        self::assertStringContainsString('/relationships/sharedStrings', $relationships);

        $contentTypes = $this->readEntry($path, '[Content_Types].xml');
        self::assertStringContainsString('PartName="/xl/worksheets/sheet2.xml"', $contentTypes);
        self::assertStringContainsString('PartName="/xl/sharedStrings.xml"', $contentTypes);
    }

    public function testCreateSheetIfMissingRejectsCaseInsensitiveDuplicate(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, [
                'sheet' => 'data',
                'create_sheet_if_missing' => true,
            ]))->append([['x']]);
            self::fail('Expected InvalidOptionException.');
        } catch (InvalidOptionException $exception) {
            self::assertStringContainsString('case-insensitive', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCreatedSheetNameProvider')]
    public function testCreateSheetIfMissingRejectsInvalidSheetNames(array $options, string $expectedMessage): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['existing']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, $options + ['create_sheet_if_missing' => true]))->append([['x']]);
            self::fail('Expected InvalidOptionException.');
        } catch (InvalidOptionException $exception) {
            self::assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreatedSheetNameProvider(): iterable
    {
        yield 'empty' => [['sheet' => ''], 'must not be empty'];
        yield 'too long' => [['sheet' => str_repeat('a', 32)], 'limited to 31'];
        yield 'forbidden character' => [['sheet' => 'bad/name'], 'cannot contain'];
        yield 'control character' => [['sheet' => "bad\x01name"], 'control characters'];
        yield 'leading apostrophe' => [['sheet' => "'quoted"], 'apostrophe'];
        yield 'trailing apostrophe' => [['sheet' => "quoted'"], 'apostrophe'];
    }

    public function testReadOnlyFileThrowsWriteFailed(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);
        chmod($path, 0o444);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([['x']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('is not writable', $exception->getMessage());
        } finally {
            chmod($path, 0o666);
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testReadOnlyDirectoryThrowsWriteFailed(): void
    {
        $directory = sys_get_temp_dir().'/xla_ro_'.bin2hex(random_bytes(4));

        if (!mkdir($directory)) {
            self::markTestSkipped('Unable to create a probe directory.');
        }

        $path = $directory.'/book.xlsx';
        XlsxFixtureFactory::create($path, ['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);
        chmod($directory, 0o555);
        $after = null;

        try {
            if (is_writable($directory)) {
                self::markTestSkipped('Directory permissions are not enforced on this platform.');
            }

            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([['x']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('is not writable', $exception->getMessage());
            $after = file_get_contents($path);
        } finally {
            chmod($directory, 0o777);
            @unlink($path);
            @rmdir($directory);
        }

        self::assertSame($before, $after);
    }

    public function testDateTimeValueThrowsAndFileStaysUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([[new \DateTimeImmutable()]]);
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException $exception) {
            self::assertStringContainsString('DateTimeImmutable', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testInfValueThrowsAndFileStaysUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([[INF]]);
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException $exception) {
            self::assertStringContainsString('INF and NAN', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testMapCallbackTransformsRecords(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2']))
            ->map(static function (mixed $record): array {
                if (!\is_array($record)) {
                    throw new \InvalidArgumentException('Expected an array record.');
                }

                return [$record['name'], $record['id']];
            })
            ->append([
                ['id' => 1, 'name' => 'ada'],
                ['id' => 2, 'name' => 'bob'],
            ]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<t>ada</t>', $sheet);
        self::assertStringContainsString('<v>1</v>', $sheet);
        self::assertStringContainsString('<t>bob</t>', $sheet);
        self::assertStringContainsString('<v>2</v>', $sheet);
    }

    public function testStrictColumnsRejectsExtraKeys(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, [
                'start_cell' => 'A2',
                'columns' => ['name'],
                'strict_columns' => true,
            ]))->append([['name' => 'Ada', 'extra' => 1]]);
            self::fail('Expected ColumnCountMismatchException.');
        } catch (ColumnCountMismatchException $exception) {
            self::assertStringContainsString('"extra"', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testPositionalArityMismatchThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $this->expectException(ColumnCountMismatchException::class);
        $this->expectExceptionMessage('has 1 values but 2 columns');

        (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'columns' => ['a', 'b'],
        ]))->append([['only-one']]);
    }

    public function testSpecialCharactersAndEmojiRoundTrip(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);
        $values = ['<tag> & "quoted" \'apostrophe\'', "line1\nline2\ttabbed", '🚀 rocket', '  padded  '];

        $written = (new XlsxAppender($path))->append([$values]);

        self::assertSame(1, $written);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($this->readEntry($path, self::SHEET_ENTRY)));

        $texts = $dom->getElementsByTagName('t');
        self::assertSame(\count($values), $texts->length);

        for ($index = 0; $index < $texts->length; $index++) {
            self::assertSame($values[$index], $texts->item($index)?->textContent);
        }
    }

    public function testNumericEdgeValuesAreWrittenPlainly(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        (new XlsxAppender($path))->append([[-0.0, \PHP_INT_MAX, 1.0E-300]]);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);

        self::assertMatchesRegularExpression('/<c r="A1"><v>-0<\/v><\/c>/', $sheet);
        self::assertStringContainsString('<v>9223372036854775807</v>', $sheet);

        self::assertSame(1, preg_match('/<c r="C1"><v>([^<]*)<\/v><\/c>/', $sheet, $matches));
        self::assertStringNotContainsStringIgnoringCase('e', $matches[1]);
        self::assertSame(1.0E-300, (float) $matches[1]);
    }

    public function testNullInTheMiddleIsSkippedByDefault(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        (new XlsxAppender($path))->append([[1, null, 'x']]);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);

        self::assertStringContainsString('<c r="A1">', $sheet);
        self::assertStringNotContainsString('r="B1"', $sheet);
        self::assertStringContainsString('r="C1"', $sheet);
    }

    public function testNullInTheMiddleIsWrittenWhenSkipNullsIsDisabled(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        (new XlsxAppender($path, ['skip_nulls' => false]))->append([[1, null, 'x']]);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);

        self::assertStringContainsString('<c r="B1"/>', $sheet);
    }

    public function testPassThroughPreservesGeneratorSpecificMarkup(): void
    {
        $sheetXml = XlsxFixtureFactory::wrapWorksheet(
            '<row r="1" customFormat="1" ht="15.75" customHeight="1">'
            .'<c r="A1" s="2" t="inlineStr"><is><t>OLD</t></is></c></row>',
            '<sheetViews><sheetView workbookViewId="0" zoomScale="90"/></sheetViews>',
            '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>',
        );
        $path = $this->fixture(['Data' => $sheetXml]);

        (new XlsxAppender($path, ['start_cell' => 'A2']))->append([['NEW']]);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);

        self::assertStringContainsString('customFormat="1"', $sheet);
        self::assertStringContainsString('<sheetViews>', $sheet);
        self::assertStringContainsString('<pageMargins', $sheet);
        self::assertStringContainsString('<t>OLD</t>', $sheet);
        self::assertStringContainsString('<row r="2">', $sheet);
        self::assertStringContainsString('<t>NEW</t>', $sheet);
    }

    public function testCloseFailureLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        $appender = new XlsxAppender($path, ['start_cell' => 'A2'], static function () use ($path): FailingZip {
            $zip = new FailingZip();
            $zip->armCloseFailure($path);

            return $zip;
        });

        try {
            $appender->append([['x']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('failed to save', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testAddFileFailureLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        $appender = new XlsxAppender($path, ['start_cell' => 'A2'], static function (): FailingZip {
            $zip = new FailingZip();
            $zip->failAddFile = true;

            return $zip;
        });

        try {
            $appender->append([['x']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('Unable to write the spliced worksheet', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testFailingIteratorLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        $records = (static function (): \Generator {
            yield ['first'];
            throw new \RuntimeException('cursor failed midway');
        })();

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append($records);
            self::fail('Expected \RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame('cursor failed midway', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
        self::assertFileDoesNotExist($path.'.tmp.rows');
    }

    public function testLongStringThrowsAndFileStaysUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([[str_repeat('x', 32768)]]);
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException $exception) {
            self::assertStringContainsString('32768 characters long', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown option(s): typo_option');

        new XlsxAppender($this->tempFilePath(), ['typo_option' => 1]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptionProvider')]
    public function testInvalidOptionTypesAreRejected(array $options, string $expectedMessage): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage($expectedMessage);

        new XlsxAppender($this->tempFilePath(), $options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptionProvider(): iterable
    {
        yield 'sheet not a string' => [['sheet' => 42], 'Option "sheet"'];
        yield 'create_sheet_if_missing not a bool' => [['create_sheet_if_missing' => 'yes'], 'Option "create_sheet_if_missing"'];
        yield 'start_cell not a string' => [['start_cell' => 1], 'Option "start_cell"'];
        yield 'conflict_mode unknown' => [['conflict_mode' => 'wipe'], 'Option "conflict_mode"'];
        yield 'conflict_mode not a string' => [['conflict_mode' => 1], 'Option "conflict_mode"'];
        yield 'conflict_mode wrong enum' => [['conflict_mode' => StringMode::InlineStr], 'Option "conflict_mode"'];
        yield 'mode unknown' => [['mode' => 'fast'], 'Option "mode"'];
        yield 'mode wrong enum' => [['mode' => ConflictMode::Error], 'Option "mode"'];
        yield 'size not an int' => [['max_sheet_xml_size' => '1mb'], 'Option "max_sheet_xml_size"'];
        yield 'size not positive' => [['max_sheet_xml_size' => 0], 'Option "max_sheet_xml_size"'];
        yield 'use_lock not a bool' => [['use_lock' => 'yes'], 'Option "use_lock"'];
        yield 'lock_timeout not a number' => [['lock_timeout' => '30'], 'Option "lock_timeout"'];
        yield 'lock_timeout not positive' => [['lock_timeout' => 0], 'Option "lock_timeout"'];
        yield 'skip_nulls not a bool' => [['skip_nulls' => 1], 'Option "skip_nulls"'];
        yield 'columns not an array' => [['columns' => 'name'], 'Option "columns"'];
        yield 'write_header not a bool' => [['write_header' => 'true'], 'Option "write_header"'];
        yield 'strict_columns not a bool' => [['strict_columns' => 'no'], 'Option "strict_columns"'];
        yield 'date_format not a string' => [['date_format' => 123], 'Option "date_format"'];
        yield 'date_format empty' => [['date_format' => ''], 'Option "date_format"'];
        yield 'date_format whitespace only' => [['date_format' => '   '], 'Option "date_format"'];
        yield 'date_format control character' => [['date_format' => "DD\x01MM"], 'Option "date_format"'];
        yield 'numeric_strings not a bool' => [['numeric_strings' => 'yes'], 'Option "numeric_strings"'];
    }

    public function testMalformedStartCellIsRejected(): void
    {
        $this->expectException(InvalidCellReferenceException::class);
        $this->expectExceptionMessage('Invalid cell reference');

        new XlsxAppender($this->tempFilePath(), ['start_cell' => 'ZZ']);
    }

    public function testSharedStringsModeCreatesThePartAndItsRegistrations(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2', 'mode' => 'shared_strings']))
            ->append([['hello'], ['hello'], ['world']]);

        self::assertSame(3, $written);

        $sst = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('count="3"', $sst);
        self::assertStringContainsString('uniqueCount="2"', $sst);
        self::assertStringContainsString('<si><t>hello</t></si>', $sst);
        self::assertStringContainsString('<si><t>world</t></si>', $sst);
        self::assertTrue(simplexml_load_string($sst) !== false);

        $contentTypes = $this->readEntry($path, SharedStringsStore::CONTENT_TYPES_ENTRY);
        self::assertStringContainsString('<Override PartName="/xl/sharedStrings.xml"', $contentTypes);

        $relationships = $this->readEntry($path, SharedStringsStore::RELATIONSHIPS_ENTRY);
        self::assertStringContainsString(
            'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"',
            $relationships,
        );
        self::assertStringContainsString('Target="sharedStrings.xml"', $relationships);

        $sheet = $this->readEntry($path, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<c r="A2" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A3" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A4" t="s"><v>1</v></c>', $sheet);
    }

    public function testStringModeEnumBehavesLikeItsStringValue(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2', 'mode' => StringMode::SharedStrings]))
            ->append([['hello'], ['hello']]);

        self::assertSame(2, $written);

        $sst = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('count="2"', $sst);
        self::assertStringContainsString('uniqueCount="1"', $sst);

        $sheet = $this->readEntry($path, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<c r="A2" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A3" t="s"><v>0</v></c>', $sheet);
    }

    public function testSharedStringsModeReusesExistingPartAndRefreshesStaleCounts(): void
    {
        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="999" uniqueCount="777">'
            .'<si><t>old-a</t></si><si><t>old-b</t></si></sst>';
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::wrapWorksheet(
                '<row r="1"><c r="A1" t="s"><v>0</v></c></row>',
                '<dimension ref="A1:A1"/>',
            )],
            [SharedStringsStore::SST_ENTRY => $sst],
        );

        $contentTypesBefore = $this->readEntry($path, SharedStringsStore::CONTENT_TYPES_ENTRY);
        $relationshipsBefore = $this->readEntry($path, SharedStringsStore::RELATIONSHIPS_ENTRY);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2', 'mode' => 'shared_strings']))
            ->append([['old-a'], ['fresh']]);

        self::assertSame(2, $written);

        $rebuilt = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('count="1001"', $rebuilt);
        self::assertStringContainsString('uniqueCount="3"', $rebuilt);
        self::assertSame(1, substr_count($rebuilt, '<si><t>old-a</t></si>'));
        self::assertStringEndsWith('<si><t>fresh</t></si></sst>', $rebuilt);
        self::assertTrue(simplexml_load_string($rebuilt) !== false);

        self::assertStringNotContainsString('sharedStrings', $this->readEntry($path, SharedStringsStore::CONTENT_TYPES_ENTRY));
        self::assertSame($relationshipsBefore, $this->readEntry($path, SharedStringsStore::RELATIONSHIPS_ENTRY));
        self::assertSame($contentTypesBefore, $this->readEntry($path, SharedStringsStore::CONTENT_TYPES_ENTRY));

        $sheet = $this->readEntry($path, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<c r="A2" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A3" t="s"><v>2</v></c>', $sheet);
    }

    public function testSharedStringsHeaderLabelSharesTheIndexWithTheSameDataValue(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'mode' => 'shared_strings',
            'columns' => ['n' => 'Label'],
            'write_header' => true,
        ]))->append([['Label'], ['Other']]);

        self::assertSame(2, $written);

        $sst = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('count="3"', $sst);
        self::assertStringContainsString('uniqueCount="2"', $sst);
        self::assertSame(1, substr_count($sst, '<si><t>Label</t></si>'));

        $sheet = $this->readEntry($path, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<c r="A1" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A2" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A3" t="s"><v>1</v></c>', $sheet);
    }

    public function testSharedStringsModeFailureLeavesEveryEntryByteIdentical(): void
    {
        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
            .'<si><t>old</t></si></sst>';
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::worksheet([['a']])],
            [SharedStringsStore::SST_ENTRY => $sst],
        );
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2', 'mode' => 'shared_strings']))
                ->append([['ok'], [\INF]]);

            self::fail('Expected UnsupportedValueException was not thrown.');
        } catch (UnsupportedValueException) {
            // expected
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame(
            $sst,
            $this->readEntry($path, SharedStringsStore::SST_ENTRY),
        );
    }

    public function testSharedStringsWriteFailureRaisesWriteFailedAndLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);

        $appender = new XlsxAppender(
            $path,
            ['start_cell' => 'A2', 'mode' => 'shared_strings'],
            static function (): FailingZip {
                $zip = new FailingZip();
                $zip->failAddFileEntry = SharedStringsStore::SST_ENTRY;

                return $zip;
            },
        );

        try {
            $appender->append([['x']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('shared strings part', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp');
        self::assertFileDoesNotExist($path.'.tmp.sst');
    }

    public function testSharedStringsModeEmptyBatchDoesNotCreateThePart(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2', 'mode' => 'shared_strings']))
            ->append([]);

        self::assertSame(0, $written);

        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            self::fail(\sprintf('Unable to open zip "%s".', $path));
        }

        $exists = $zip->locateName(SharedStringsStore::SST_ENTRY);
        $zip->close();

        self::assertFalse($exists !== false);
    }

    public function testLockTimeoutRaisesLockTimeoutExceptionAndLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $before = file_get_contents($path);
        $foreign = fopen($path.'.lock', 'c');
        self::assertIsResource($foreign);
        self::assertTrue(flock($foreign, \LOCK_EX | \LOCK_NB));

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2', 'lock_timeout' => 0.05]))
                ->append([['x']]);

            self::fail('Expected LockTimeoutException.');
        } catch (LockTimeoutException $exception) {
            self::assertStringContainsString('Could not acquire a lock', $exception->getMessage());
            self::assertStringContainsString('(C12)', $exception->getMessage());
        } finally {
            flock($foreign, \LOCK_UN);
            fclose($foreign);
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testLockIsReleasedAfterSuccessfulAppend(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2']))->append([['x']]);
        self::assertSame(1, $written);

        $probe = fopen($path.'.lock', 'c');
        self::assertIsResource($probe);
        self::assertTrue(flock($probe, \LOCK_EX | \LOCK_NB));
        flock($probe, \LOCK_UN);
        fclose($probe);
    }

    public function testLockIsReleasedWhenAppendThrows(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([[\INF]]);
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException) {
            // expected
        }

        $probe = fopen($path.'.lock', 'c');
        self::assertIsResource($probe);
        self::assertTrue(flock($probe, \LOCK_EX | \LOCK_NB));
        flock($probe, \LOCK_UN);
        fclose($probe);
    }

    public function testUseLockFalseAppendsWhileAForeignLockIsHeld(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::worksheet([['a']])]);
        $foreign = fopen($path.'.lock', 'c');
        self::assertIsResource($foreign);
        self::assertTrue(flock($foreign, \LOCK_EX | \LOCK_NB));

        try {
            $written = (new XlsxAppender($path, [
                'start_cell' => 'A2',
                'use_lock' => false,
            ]))->append([['x']]);
        } finally {
            flock($foreign, \LOCK_UN);
            fclose($foreign);
        }

        self::assertSame(1, $written);
        self::assertStringContainsString(
            '<row r="2">',
            $this->readEntry($path, 'xl/worksheets/sheet1.xml'),
        );
    }

    public function testArchivesLargerThanFourGigabytesAreRejected(): void
    {
        $path = $this->tempFilePath();
        $handle = fopen($path, 'wb');
        $created = $handle !== false && ftruncate($handle, 4294967300);

        if (\is_resource($handle)) {
            fclose($handle);
        }

        clearstatcache(true, $path);

        if (!$created || !is_file($path) || filesize($path) <= 4294967295) {
            self::markTestSkipped('Unable to create a file larger than 4 GB on this filesystem.');
        }

        try {
            (new XlsxAppender($path, ['start_cell' => 'A2']))->append([['x']]);

            self::fail('Expected UnsupportedArchiveException.');
        } catch (UnsupportedArchiveException $exception) {
            self::assertStringContainsString('4 GB', $exception->getMessage());
            self::assertStringContainsString('(C20)', $exception->getMessage());
        }
    }

    public function testDateFormatCreatesStylesPartAndRegistrations(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, ['date_format' => 'DD.MM.YYYY']))->append([
            ['2026-10-29'],
            [new \DateTimeImmutable('2026-10-29 12:00:00')],
            ['29.10.2026'],
            ['not a date'],
            [42],
        ]);

        self::assertSame(5, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="A1" s="1"><v>46324</v></c>', $sheet);
        self::assertStringContainsString('<c r="A2" s="1"><v>46324.5</v></c>', $sheet);
        self::assertStringContainsString('<c r="A3" s="1"><v>46324</v></c>', $sheet);
        self::assertStringContainsString('<c r="A4" t="inlineStr"><is><t>not a date</t></is></c>', $sheet);
        self::assertStringContainsString('<c r="A5"><v>42</v></c>', $sheet);

        $styles = $this->readEntry($path, StylesStore::STYLES_ENTRY);
        self::assertStringContainsString('<numFmt numFmtId="164" formatCode="DD.MM.YYYY"/>', $styles);
        self::assertStringContainsString('<cellXfs count="2">', $styles);
        self::assertStringContainsString(
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            $styles,
        );

        $contentTypes = $this->readEntry($path, StylesStore::CONTENT_TYPES_ENTRY);
        self::assertStringContainsString('<Override PartName="/xl/styles.xml"', $contentTypes);

        $relationships = $this->readEntry($path, StylesStore::RELATIONSHIPS_ENTRY);
        self::assertStringContainsString('Id="rId2"', $relationships);
        self::assertStringContainsString('/relationships/styles', $relationships);
        self::assertStringContainsString('Target="styles.xml"', $relationships);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        try {
            $loaded = $spreadsheet->getActiveSheet();
            self::assertSame('29.10.2026', $loaded->getCell('A1')->getFormattedValue());
            self::assertSame('29.10.2026', $loaded->getCell('A2')->getFormattedValue());
            self::assertSame('29.10.2026', $loaded->getCell('A3')->getFormattedValue());
            self::assertSame('not a date', $loaded->getCell('A4')->getFormattedValue());
            self::assertSame('42', $loaded->getCell('A5')->getFormattedValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        self::assertFileDoesNotExist($path.'.tmp.styles');
    }

    public function testDateFormatPatchesAnExistingStylesPart(): void
    {
        $existing = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'</styleSheet>';
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()], [
            StylesStore::STYLES_ENTRY => $existing,
        ]);

        $written = (new XlsxAppender($path, ['date_format' => 'DD.MM.YYYY']))->append([['2026-10-29']]);

        self::assertSame(1, $written);

        $styles = $this->readEntry($path, StylesStore::STYLES_ENTRY);
        self::assertStringContainsString(
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD.MM.YYYY"/></numFmts><fonts',
            $styles,
        );
        self::assertStringContainsString('<cellXfs count="2">', $styles);
        self::assertStringContainsString(
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>',
            $styles,
        );
        self::assertStringContainsString('<name val="Calibri"/>', $styles);
        self::assertStringContainsString('<cellStyleXfs count="1">', $styles);

        self::assertStringContainsString(
            '<c r="A1" s="1"><v>46324</v></c>',
            $this->readEntry($path, self::SHEET_ENTRY),
        );
        self::assertFileDoesNotExist($path.'.tmp.styles');
    }

    public function testDateFormatKeepsDatesOutOfSharedStrings(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'mode' => 'shared_strings',
            'date_format' => 'DD.MM.YYYY',
        ]))->append([['hello'], ['2026-10-29'], ['hello']]);

        self::assertSame(3, $written);

        $sst = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('uniqueCount="1"', $sst);
        self::assertStringContainsString('<si><t>hello</t></si>', $sst);
        self::assertStringNotContainsString('46324', $sst);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="A2" t="s"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="A3" s="1"><v>46324</v></c>', $sheet);
        self::assertStringContainsString('<c r="A4" t="s"><v>0</v></c>', $sheet);
    }

    public function testDateFormatWithEmptyBatchLeavesFileByteIdentical(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);
        $before = file_get_contents($path);

        $written = (new XlsxAppender($path, ['date_format' => 'DD.MM.YYYY']))->append([]);

        self::assertSame(0, $written);
        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp.styles');
    }

    public function testDateBeforeExcelEpochLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['date_format' => 'DD.MM.YYYY']))
                ->append([['ok'], [new \DateTimeImmutable('1899-12-31')]]);
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException $exception) {
            self::assertStringContainsString('before 1900-01-01', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp.styles');
    }

    public function testStylesWriteFailureRaisesWriteFailedAndLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);
        $before = file_get_contents($path);

        $appender = new XlsxAppender(
            $path,
            ['date_format' => 'DD.MM.YYYY'],
            static function (): FailingZip {
                $zip = new FailingZip();
                $zip->failAddFileEntry = StylesStore::STYLES_ENTRY;

                return $zip;
            },
        );

        try {
            $appender->append([['2026-10-29']]);
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('styles part', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertFileDoesNotExist($path.'.tmp.styles');
    }

    public function testNumericStringsOptionConvertsDatabaseStringsToNumbers(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, ['start_cell' => 'A2']))
            ->append([['0', '.7', '42', '007', ' 7 ']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="A2"><v>0</v></c>', $sheet);
        self::assertStringContainsString('<c r="B2"><v>0.7</v></c>', $sheet);
        self::assertStringContainsString('<c r="C2"><v>42</v></c>', $sheet);
        self::assertStringContainsString('<c r="D2" t="inlineStr"><is><t>007</t></is></c>', $sheet);
        self::assertStringContainsString('<c r="E2" t="inlineStr"><is><t xml:space="preserve"> 7 </t></is></c>', $sheet);
    }

    public function testNumericStringsOptionOffKeepsDatabaseStringsAsText(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'numeric_strings' => false,
        ]))->append([['0', '.7']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="A2" t="inlineStr"><is><t>0</t></is></c>', $sheet);
        self::assertStringContainsString('<c r="B2" t="inlineStr"><is><t>.7</t></is></c>', $sheet);
    }

    public function testNumericStringsOptionKeepsHeaderRowAsText(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'columns' => ['year' => '2024', 'amount' => '0'],
            'write_header' => true,
            'numeric_strings' => true,
        ]))->append([['1', '.7']]);

        self::assertSame(1, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="A1" t="inlineStr"><is><t>2024</t></is></c>', $sheet);
        self::assertStringContainsString('<c r="B1" t="inlineStr"><is><t>0</t></is></c>', $sheet);
        self::assertStringContainsString('<c r="A2"><v>1</v></c>', $sheet);
        self::assertStringContainsString('<c r="B2"><v>0.7</v></c>', $sheet);
    }

    public function testNumericStringsOptionKeepsConvertedValuesOutOfSharedStrings(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = (new XlsxAppender($path, [
            'start_cell' => 'A2',
            'mode' => 'shared_strings',
            'numeric_strings' => true,
        ]))->append([['42', 'x']]);

        self::assertSame(1, $written);

        $sst = $this->readEntry($path, SharedStringsStore::SST_ENTRY);
        self::assertStringContainsString('uniqueCount="1"', $sst);
        self::assertStringContainsString('<si><t>x</t></si>', $sst);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="A2"><v>42</v></c>', $sheet);
        self::assertStringContainsString('<c r="B2" t="s"><v>0</v></c>', $sheet);
    }

    public function testNumericStringsOverflowLeavesFileUnchanged(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);
        $before = file_get_contents($path);

        try {
            (new XlsxAppender($path, ['numeric_strings' => true]))
                ->append([['ok'], ['1e999']]);
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException $exception) {
            self::assertStringContainsString('INF and NAN', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    /**
     * @param array<string, string> $sheets
     * @param array<string, string> $extraEntries
     */
    private function fixture(array $sheets, array $extraEntries = []): string
    {
        return XlsxFixtureFactory::create($this->tempFilePath(), $sheets, $extraEntries);
    }

    private function readEntry(string $path, string $entry): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            self::fail(\sprintf('Unable to open zip "%s".', $path));
        }

        $contents = $zip->getFromName($entry);
        $zip->close();

        if ($contents === false) {
            self::fail(\sprintf('Entry "%s" missing in "%s".', $entry, $path));
        }

        return $contents;
    }

    /**
     * @return list<string> row numbers in document order
     */
    private function rowNumbers(string $sheet): array
    {
        preg_match_all('/<row\b[^>]*\br="(\d+)"/', $sheet, $matches);

        return $matches[1];
    }
}
