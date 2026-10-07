<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidWorkbookException;
use XlsxFastAppender\Exception\WriteFailedException;

/**
 * Splices new rows into worksheet XML (variant C):
 *
 * 1. the original XML is split at the first `</sheetData>`;
 * 2. new rows are streamed into a `.rows` temp file next to the target;
 * 3. the final temp file is assembled as prefix (with an updated
 *    `<dimension ref>`) + rows + suffix — everything outside the inserted
 *    block is preserved byte for byte (C17);
 * 4. the assembled file is verified by re-scanning it (row count and
 *    well-formedness) before it may enter the zip.
 *
 * With conflict_mode "clear" / "overwrite" the existing rows are re-bucketed
 * first (SheetRowPartitioner): rows the rewrite drops are removed, kept rows
 * keep their bytes and land around the inserted block in ascending order.
 *
 * On any failure both temp files are removed and the exception is rethrown:
 * the caller's file is never touched.
 */
final class SheetSplicer
{
    private const CHUNK_SIZE = 65536;

    public function __construct(private readonly RowXmlGenerator $generator)
    {
    }

    /**
     * @param iterable<mixed>   $records          raw records, mapped by $mapper per row
     * @param list<string>|null $headerLabels     header row at startRow − 1, or null
     * @param int               $previousRowCount rows already present in <sheetData>
     * @param string            $conflictMode     "error" appends below the data as-is;
     *                                            "clear" drops every row from startRow to the
     *                                            end of the sheet, "overwrite" drops only the
     *                                            rows the new block covers
     *
     * @throws InvalidWorkbookException when the sheet XML has no `</sheetData>` (C14)
     * @throws WriteFailedException     when a temp file cannot be written (C13)
     */
    public function splice(
        string $sheetXml,
        string $tmpPath,
        int $startRow,
        int $previousRowCount,
        iterable $records,
        ColumnMapper $mapper,
        ?array $headerLabels = null,
        string $conflictMode = 'error',
    ): SpliceResult {
        $rowsPath = $tmpPath.'.rows';
        $directory = \dirname($rowsPath);

        if (!is_writable($directory)) {
            throw new WriteFailedException(\sprintf(
                'The directory "%s" is not writable; a temporary file cannot be created next to "%s".',
                $directory,
                $tmpPath,
            ));
        }

        try {
            [$result, $dropped] = $this->writeRows(
                $sheetXml,
                $rowsPath,
                $tmpPath,
                $startRow,
                $records,
                $mapper,
                $headerLabels,
                $conflictMode,
            );

            if ($result->isEmpty()) {
                $this->removeFile($rowsPath);

                return $result;
            }

            $this->verify($tmpPath, $result, $previousRowCount, $dropped);

            return $result;
        } catch (\Throwable $exception) {
            $this->removeFile($rowsPath);
            $this->removeFile($tmpPath);

            throw $exception;
        }
    }

    /**
     * Re-scans an assembled sheet file and asserts its row count matches the
     * expectation (specification step 9, run before the file enters the zip).
     *
     * @param int $droppedRowCount rows removed by the "clear" / "overwrite" rewrite
     *
     * @throws InvalidWorkbookException when the file is malformed or has the wrong row count
     */
    public function verify(
        string $tmpPath,
        SpliceResult $expected,
        int $previousRowCount,
        int $droppedRowCount = 0,
    ): void {
        $reader = \XMLReader::open($tmpPath);

        if (!$reader instanceof \XMLReader) {
            throw new InvalidWorkbookException(\sprintf('Unable to read back "%s" for verification.', $tmpPath));
        }

        $result = SheetDataScanner::scanReader($reader, $tmpPath);
        $expectedRows = $previousRowCount - $droppedRowCount
            + $expected->rowsWritten
            + ($expected->headerWritten ? 1 : 0);

        if ($result->rowCount !== $expectedRows) {
            throw new InvalidWorkbookException(\sprintf(
                'Verification failed for "%s": expected %d rows in <sheetData>, found %d.',
                $tmpPath,
                $expectedRows,
                $result->rowCount,
            ));
        }
    }

    /**
     * @param iterable<mixed>   $records
     * @param list<string>|null $headerLabels
     *
     * @return array{SpliceResult, int} splice result and the number of
     *                                  existing rows dropped by the rewrite
     */
    private function writeRows(
        string $sheetXml,
        string $rowsPath,
        string $tmpPath,
        int $startRow,
        iterable $records,
        ColumnMapper $mapper,
        ?array $headerLabels,
        string $conflictMode,
    ): array {
        $split = $this->splitSheetData($sheetXml);

        $rows = 0;
        $headerWritten = false;
        $maxColumn = 0;
        $minRow = $startRow;

        $handle = fopen($rowsPath, 'wb');

        if ($handle === false) {
            throw new WriteFailedException(\sprintf('Unable to create the temporary file "%s".', $rowsPath));
        }

        try {
            if ($headerLabels !== null) {
                $headerRow = $startRow - 1;
                $headerXml = $this->generator->rowXml($headerRow, $headerLabels);

                $this->writeChunk($handle, $headerXml, $rowsPath);
                $headerWritten = true;
                $minRow = $headerRow;
                $maxColumn = max($maxColumn, $this->columnSpan($headerLabels));
            }

            foreach ($records as $record) {
                $values = $mapper->map($record);
                $rowNumber = $startRow + $rows;
                $rowXml = $this->generator->rowXml($rowNumber, $values);

                $this->writeChunk($handle, $rowXml, $rowsPath);
                $rows++;
                $maxColumn = max($maxColumn, $this->columnSpan($values));
            }
        } finally {
            fclose($handle);
        }

        if ($rows === 0 && !$headerWritten) {
            $this->removeFile($rowsPath);

            return [new SpliceResult(0, false), 0];
        }

        $maxRow = $headerWritten && $rows === 0 ? $startRow - 1 : $startRow + $rows - 1;
        $dropped = 0;

        if ($conflictMode === 'clear' || $conflictMode === 'overwrite') {
            [$head, $body] = $this->splitSheetHead($split[0]);
            $lastReplacedRow = $conflictMode === 'clear' ? null : $startRow + $rows - 1;
            $partition = SheetRowPartitioner::partition($body, $startRow, $lastReplacedRow);
            $dropped = $partition['dropped'];
            $prefix = $this->applyDimension(
                $head.$partition['before'],
                $minRow,
                max($partition['keptMaxRow'], $maxRow),
                $maxColumn,
                false,
            );
            $suffix = $partition['after'].$split[1];
        } else {
            $prefix = $this->applyDimension($split[0], $minRow, $maxRow, $maxColumn);
            $suffix = $split[1];
        }

        $this->assemble($prefix, $rowsPath, $suffix, $tmpPath);
        $this->removeFile($rowsPath);

        return [new SpliceResult($rows, $headerWritten), $dropped];
    }

    /**
     * Splits the prefix produced by splitSheetData() into the worksheet
     * header (everything up to and including the <sheetData> open tag) and
     * the body that holds the existing rows.
     *
     *
     * @throws InvalidWorkbookException when the opening tag is missing (C14)
     *
     * @return array{string, string} <sheetData> open tag and the rows inside it
     */
    private function splitSheetHead(string $prefix): array
    {
        if (preg_match('/<sheetData\b[^>]*>/', $prefix, $match, \PREG_OFFSET_CAPTURE) !== 1) {
            throw new InvalidWorkbookException(
                'The worksheet XML has no opening <sheetData> tag; the file is not a usable worksheet (C14).',
            );
        }

        $end = $match[0][1] + \strlen($match[0][0]);

        return [substr($prefix, 0, $end), substr($prefix, $end)];
    }

    /**
     * Locates the insertion point: everything before </sheetData> (the
     * self-closed <sheetData/> form is rewritten into an open element so the
     * new rows have somewhere to live).
     *
     *
     * @throws InvalidWorkbookException when neither form is present (C14)
     *
     * @return array{string, string} prefix up to the insertion point, suffix starting at </sheetData>
     */
    private function splitSheetData(string $sheetXml): array
    {
        $closing = strpos($sheetXml, '</sheetData>');

        if ($closing !== false) {
            return [substr($sheetXml, 0, $closing), substr($sheetXml, $closing)];
        }

        if (preg_match('/<sheetData\b[^>]*\/>/', $sheetXml, $match, \PREG_OFFSET_CAPTURE) === 1) {
            $position = $match[0][1];
            $length = \strlen($match[0][0]);

            return [
                substr($sheetXml, 0, $position).'<sheetData>',
                '</sheetData>'.substr($sheetXml, $position + $length),
            ];
        }

        throw new InvalidWorkbookException(
            'The worksheet XML has no closing </sheetData> tag; the file is not a usable worksheet (C14).',
        );
    }

    /**
     * @param list<mixed> $values
     */
    private function columnSpan(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return $this->generator->startColumn() + \count($values) - 1;
    }

    /**
     * @param resource $handle
     */
    private function writeChunk($handle, string $chunk, string $path): void
    {
        $written = fwrite($handle, $chunk);

        if ($written === false || $written !== \strlen($chunk)) {
            throw new WriteFailedException(\sprintf('Unable to write to the temporary file "%s".', $path));
        }
    }

    private function assemble(string $prefix, string $rowsPath, string $suffix, string $tmpPath): void
    {
        $source = fopen($rowsPath, 'rb');

        if ($source === false) {
            throw new WriteFailedException(\sprintf('Unable to reopen the temporary file "%s".', $rowsPath));
        }

        $target = fopen($tmpPath, 'wb');

        if ($target === false) {
            fclose($source);

            throw new WriteFailedException(\sprintf('Unable to create the temporary file "%s".', $tmpPath));
        }

        try {
            $this->writeChunk($target, $prefix, $tmpPath);

            while (!feof($source)) {
                $buffer = fread($source, self::CHUNK_SIZE);

                if ($buffer === false) {
                    throw new WriteFailedException(\sprintf('Unable to read the temporary file "%s".', $rowsPath));
                }

                if ($buffer !== '') {
                    $this->writeChunk($target, $buffer, $tmpPath);
                }
            }

            $this->writeChunk($target, $suffix, $tmpPath);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /**
     * Rewrites the `<dimension ref>` of the sheet header so it covers both the
     * previous extent (when the old ref is parseable) and the new block.
     *
     * @param bool $keepOldMaxRow merge the old bottom row as well; false for the
     *                            "clear" / "overwrite" modes, where rows below the
     *                            kept ones no longer exist and the exact kept
     *                            maximum is already known
     */
    private function applyDimension(
        string $prefix,
        int $minRow,
        int $maxRow,
        int $maxColumn,
        bool $keepOldMaxRow = true,
    ): string {
        if ($maxColumn === 0) {
            return $prefix;
        }

        if (preg_match('/<dimension\b([^>]*?)\s*\/>/', $prefix, $match) !== 1) {
            return $prefix;
        }

        $startColumn = $this->generator->startColumn();
        $bounds = ['minCol' => $startColumn, 'minRow' => $minRow, 'maxCol' => $maxColumn, 'maxRow' => $maxRow];
        $old = self::parseRef($match[1]);

        if ($old !== null) {
            $bounds['minCol'] = min($old['minCol'], $bounds['minCol']);
            $bounds['minRow'] = min($old['minRow'], $bounds['minRow']);
            $bounds['maxCol'] = max($old['maxCol'], $bounds['maxCol']);

            if ($keepOldMaxRow) {
                $bounds['maxRow'] = max($old['maxRow'], $bounds['maxRow']);
            }
        }

        return preg_replace_callback(
            '/<dimension\b([^>]*?)\s*\/>/',
            static function (array $matches) use ($bounds): string {
                $ref = \sprintf(
                    '%s%d:%s%d',
                    ColumnName::of($bounds['minCol']),
                    $bounds['minRow'],
                    ColumnName::of($bounds['maxCol']),
                    $bounds['maxRow'],
                );
                $attributes = preg_replace('/\s+ref="[^"]*"/', '', $matches[1]);

                return '<dimension'.($attributes ?? '').' ref="'.$ref.'"/>';
            },
            $prefix,
            1,
        ) ?? $prefix;
    }

    /**
     * @return array{minCol: int, minRow: int, maxCol: int, maxRow: int}|null
     */
    private static function parseRef(string $attributes): ?array
    {
        if (preg_match('/\bref="([^"]*)"/', $attributes, $refMatch) !== 1) {
            return null;
        }

        $ref = $refMatch[1];

        if (preg_match('/\A([A-Za-z]+)(\d+):([A-Za-z]+)(\d+)\z/', $ref, $match) !== 1) {
            return null;
        }

        try {
            return [
                'minCol' => ColumnName::indexOf($match[1]),
                'minRow' => (int) $match[2],
                'maxCol' => ColumnName::indexOf($match[3]),
                'maxRow' => (int) $match[4],
            ];
        } catch (Exception\InvalidCellReferenceException) {
            return null;
        }
    }

    private function removeFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
