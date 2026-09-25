<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
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
        yield 'start_cell not a string' => [['start_cell' => 1], 'Option "start_cell"'];
        yield 'mode unknown' => [['mode' => 'fast'], 'Option "mode"'];
        yield 'size not an int' => [['max_sheet_xml_size' => '1mb'], 'Option "max_sheet_xml_size"'];
        yield 'size not positive' => [['max_sheet_xml_size' => 0], 'Option "max_sheet_xml_size"'];
        yield 'use_lock not a bool' => [['use_lock' => 'yes'], 'Option "use_lock"'];
        yield 'lock_timeout not a number' => [['lock_timeout' => '30'], 'Option "lock_timeout"'];
        yield 'lock_timeout not positive' => [['lock_timeout' => 0], 'Option "lock_timeout"'];
        yield 'skip_nulls not a bool' => [['skip_nulls' => 1], 'Option "skip_nulls"'];
        yield 'columns not an array' => [['columns' => 'name'], 'Option "columns"'];
        yield 'write_header not a bool' => [['write_header' => 'true'], 'Option "write_header"'];
        yield 'strict_columns not a bool' => [['strict_columns' => 'no'], 'Option "strict_columns"'];
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
}
