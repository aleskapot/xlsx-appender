<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\ColumnMapper;
use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Exception\UnsupportedValueException;
use XlsxFastAppender\Exception\WriteFailedException;
use XlsxFastAppender\RowXmlGenerator;
use XlsxFastAppender\SheetSplicer;
use XlsxFastAppender\SpliceResult;
use XlsxFastAppender\Tests\Support\TestCase;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;

#[CoversClass(SheetSplicer::class)]
#[CoversClass(SpliceResult::class)]
final class SheetSplicerTest extends TestCase
{
    public function testInsertsNewRowsBeforeClosingSheetData(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice($original, $tmp, 2, 1, [['a'], ['b']], new ColumnMapper(null));

        self::assertSame(2, $result->rowsWritten);
        self::assertFalse($result->headerWritten);
        self::assertFalse($result->isEmpty());

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('<row r="1">', $content);
        self::assertStringContainsString('<row r="2">', $content);
        self::assertStringContainsString('<row r="3">', $content);
        self::assertSame(1, substr_count($content, '</sheetData>'));
        self::assertStringContainsString('ref="A1:A3"', $content);

        $split = strpos($original, '</sheetData>');
        self::assertIsInt($split);
        self::assertTrue(str_ends_with($content, substr($original, $split)));
    }

    public function testRewritesSelfClosedSheetDataToInsertRowsIntoAnEmptySheet(): void
    {
        $original = XlsxFixtureFactory::emptyWorksheet();
        self::assertStringContainsString('<sheetData/>', $original);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice($original, $tmp, 1, 0, [['a'], ['b']], new ColumnMapper(null));

        self::assertSame(2, $result->rowsWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringNotContainsString('<sheetData/>', $content);
        self::assertStringContainsString('<sheetData><row r="1">', $content);
        self::assertStringContainsString('<row r="2">', $content);
        self::assertSame(1, substr_count($content, '</sheetData>'));
        self::assertStringEndsWith('</worksheet>', $content);
    }

    public function testWritesHeaderRowBeforeData(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice(
            $original,
            $tmp,
            3,
            1,
            [['new', 42]],
            new ColumnMapper(['name' => 'Имя', 'amount' => 'Сумма']),
            ['Имя', 'Сумма'],
        );

        self::assertSame(1, $result->rowsWritten);
        self::assertTrue($result->headerWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('<row r="2">', $content);
        self::assertStringContainsString('Имя', $content);
        self::assertStringContainsString('<row r="3">', $content);
    }

    public function testHeaderOnlyRunWritesSingleHeaderRow(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice(
            $original,
            $tmp,
            3,
            1,
            [],
            new ColumnMapper(['name']),
            ['Name'],
        );

        self::assertSame(0, $result->rowsWritten);
        self::assertTrue($result->headerWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('<row r="2">', $content);
        self::assertStringNotContainsString('<row r="3"', $content);
    }

    public function testEmptyRunCreatesNoTemporaryFiles(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice($original, $tmp, 2, 1, [], new ColumnMapper(null));

        self::assertSame(0, $result->rowsWritten);
        self::assertFalse($result->headerWritten);
        self::assertTrue($result->isEmpty());
        self::assertFileDoesNotExist($tmp);
        self::assertFileDoesNotExist($tmp.'.rows');
    }

    public function testRejectsSheetWithoutClosingSheetData(): void
    {
        $tmp = $this->tempFilePath();

        try {
            $this->splicer()->splice(
                '<worksheet><sheetData><row r="1"></row>',
                $tmp,
                2,
                1,
                [['x']],
                new ColumnMapper(null),
            );
            self::fail('Expected InvalidWorkbookException.');
        } catch (InvalidWorkbookException $exception) {
            self::assertStringContainsString('</sheetData>', $exception->getMessage());
        }

        self::assertFileDoesNotExist($tmp);
        self::assertFileDoesNotExist($tmp.'.rows');
    }

    public function testCleansUpTemporaryFilesWhenAMidBatchRecordFails(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();

        try {
            $this->splicer()->splice(
                $original,
                $tmp,
                2,
                1,
                [['fine'], 'scalar-record'],
                new ColumnMapper(['a']),
                null,
            );
            self::fail('Expected UnsupportedValueException.');
        } catch (UnsupportedValueException $exception) {
            self::assertStringContainsString('Records must be arrays or objects', $exception->getMessage());
        }

        self::assertFileDoesNotExist($tmp);
        self::assertFileDoesNotExist($tmp.'.rows');
    }

    public function testUpdatesDimensionByMergingExistingExtent(): void
    {
        $original = XlsxFixtureFactory::wrapWorksheet(
            '<row r="2"><c r="B2" t="inlineStr"><is><t>x</t></is></c></row>'
            .'<row r="3"><c r="C3" t="inlineStr"><is><t>y</t></is></c></row>'
            .'<row r="4"><c r="D4" t="inlineStr"><is><t>z</t></is></c></row>',
            '<dimension ref="B2:D4"/>',
        );
        $tmp = $this->tempFilePath();

        $this->splicer()->splice($original, $tmp, 5, 3, [['n1', 'n2'], ['n3', 'n4']], new ColumnMapper(null));

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('ref="A2:D6"', $content);
    }

    public function testLeavesDimensionUntouchedWhenSheetHasNone(): void
    {
        $original = XlsxFixtureFactory::wrapWorksheet('<row r="1"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>');
        $tmp = $this->tempFilePath();

        $this->splicer()->splice($original, $tmp, 2, 1, [['y']], new ColumnMapper(null));

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringNotContainsString('<dimension', $content);
        self::assertStringContainsString('<row r="2">', $content);
    }

    public function testKeepsDimensionWhenNoCellsWereEmitted(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice($original, $tmp, 2, 1, [[]], new ColumnMapper(null));

        self::assertSame(1, $result->rowsWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('ref="A1:A1"', $content);
    }

    public function testVerifyRejectsMismatchedRowCount(): void
    {
        $tmp = $this->tempFilePath();
        file_put_contents($tmp, XlsxFixtureFactory::worksheet([['only']]));

        $this->expectException(InvalidWorkbookException::class);
        $this->expectExceptionMessage('expected 5 rows in <sheetData>, found 1');

        $this->splicer()->verify($tmp, new SpliceResult(5, false), 0);
    }

    public function testVerifyRejectsUnreadableFile(): void
    {
        $tmp = $this->tempFilePath();

        set_error_handler(static fn (): bool => true);

        try {
            $this->splicer()->verify($tmp, new SpliceResult(0, false), 0);
            self::fail('Expected InvalidWorkbookException.');
        } catch (InvalidWorkbookException $exception) {
            self::assertStringContainsString('Unable to read back', $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testAssemblesRowsLargerThanTheCopyChunk(): void
    {
        $original = XlsxFixtureFactory::worksheet([['old']]);
        $tmp = $this->tempFilePath();
        $records = [];

        for ($i = 0; $i < 1500; $i++) {
            $records[] = ['row-'.$i, str_repeat('x', 120)];
        }

        $result = $this->splicer()->splice($original, $tmp, 2, 1, $records, new ColumnMapper(null));

        self::assertSame(1500, $result->rowsWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertGreaterThan(65536, \strlen($content));
        self::assertStringContainsString('<row r="1501">', $content);
        self::assertFileDoesNotExist($tmp.'.rows');
    }

    public function testDimensionWithoutRefAttributeIsReplacedByTheNewBounds(): void
    {
        $original = XlsxFixtureFactory::wrapWorksheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>',
            '<dimension/>',
        );
        $tmp = $this->tempFilePath();

        $this->splicer()->splice($original, $tmp, 2, 1, [['y']], new ColumnMapper(null));

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('ref="A2:A2"', $content);
    }

    public function testDimensionWithSingleCellRefIsReplacedByTheNewBounds(): void
    {
        $original = XlsxFixtureFactory::wrapWorksheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>',
            '<dimension ref="A1"/>',
        );
        $tmp = $this->tempFilePath();

        $this->splicer()->splice($original, $tmp, 2, 1, [['y']], new ColumnMapper(null));

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('ref="A2:A2"', $content);
        self::assertStringNotContainsString('ref="A1"', $content);
    }

    public function testDimensionWithOutOfRangeColumnIsReplacedByTheNewBounds(): void
    {
        $original = XlsxFixtureFactory::wrapWorksheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>',
            '<dimension ref="XFE1:A1"/>',
        );
        $tmp = $this->tempFilePath();

        $this->splicer()->splice($original, $tmp, 2, 1, [['y']], new ColumnMapper(null));

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        self::assertStringContainsString('ref="A2:A2"', $content);
        self::assertStringNotContainsString('XFE', $content);
    }

    public function testClearModeDropsExistingRowsFromStartRowToTheEnd(): void
    {
        $original = XlsxFixtureFactory::worksheet([['1'], ['2'], ['3'], ['4'], ['5']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice(
            $original,
            $tmp,
            2,
            5,
            [['x'], ['y']],
            new ColumnMapper(null),
            null,
            'clear',
        );

        self::assertSame(2, $result->rowsWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        preg_match_all('/<row\b[^>]*\br="(\d+)"/', $content, $matches);
        self::assertSame(['1', '2', '3'], $matches[1]);
        self::assertStringContainsString('<t>1</t>', $content);
        self::assertStringNotContainsString('<t>5</t>', $content);
        self::assertStringContainsString('<t>x</t>', $content);
        self::assertStringContainsString('ref="A1:A3"', $content);
        self::assertStringNotContainsString('ref="A1:A5"', $content);
        self::assertFileDoesNotExist($tmp.'.rows');
    }

    public function testOverwriteModeReplacesCoveredRowsAndKeepsTheRest(): void
    {
        $original = XlsxFixtureFactory::worksheet([['1'], ['2'], ['3']]);
        $tmp = $this->tempFilePath();

        $result = $this->splicer()->splice(
            $original,
            $tmp,
            2,
            3,
            [['a'], ['b'], ['c'], ['d']],
            new ColumnMapper(null),
            null,
            'overwrite',
        );

        self::assertSame(4, $result->rowsWritten);

        $content = file_get_contents($tmp);
        self::assertIsString($content);
        preg_match_all('/<row\b[^>]*\br="(\d+)"/', $content, $matches);
        self::assertSame(['1', '2', '3', '4', '5'], $matches[1]);
        self::assertStringContainsString('<t>1</t>', $content);
        self::assertStringNotContainsString('<t>2</t>', $content);
        self::assertStringContainsString('<t>d</t>', $content);
        self::assertStringContainsString('ref="A1:A5"', $content);
        self::assertStringNotContainsString('ref="A1:A3"', $content);
    }

    public function testConflictModeRejectsPrefixWithoutOpeningSheetDataTag(): void
    {
        $tmp = $this->tempFilePath();

        try {
            $this->splicer()->splice(
                '<worksheet><dimension ref="A1:A1"/></sheetData>',
                $tmp,
                1,
                1,
                [['x']],
                new ColumnMapper(null),
                null,
                'clear',
            );
            self::fail('Expected InvalidWorkbookException.');
        } catch (InvalidWorkbookException $exception) {
            self::assertStringContainsString('opening <sheetData>', $exception->getMessage());
        }

        self::assertFileDoesNotExist($tmp);
        self::assertFileDoesNotExist($tmp.'.rows');
    }

    public function testRejectsWhenTheTemporaryDirectoryIsNotWritable(): void
    {
        $directory = sys_get_temp_dir().'/xla_sp_'.bin2hex(random_bytes(4));

        if (!mkdir($directory)) {
            self::markTestSkipped('Unable to create a probe directory.');
        }

        $tmp = $directory.'/sheet.tmp';
        $original = XlsxFixtureFactory::worksheet([['old']]);
        chmod($directory, 0o555);

        try {
            if (is_writable($directory)) {
                self::markTestSkipped('Directory permissions are not enforced on this platform.');
            }

            $this->splicer()->splice($original, $tmp, 2, 1, [['y']], new ColumnMapper(null));
            self::fail('Expected WriteFailedException.');
        } catch (WriteFailedException $exception) {
            self::assertStringContainsString('is not writable', $exception->getMessage());
        } finally {
            chmod($directory, 0o777);
            @unlink($tmp);
            @unlink($tmp.'.rows');
            @rmdir($directory);
        }

        self::assertFileDoesNotExist($tmp);
    }

    private function splicer(int $startColumn = 1, bool $skipNulls = true): SheetSplicer
    {
        return new SheetSplicer(new RowXmlGenerator($startColumn, $skipNulls));
    }
}
