<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidWorkbookException;

/**
 * Streams a worksheet element and collects structural facts: presence of
 * <sheetData>, highest row number, row count. Shared by the read layer
 * (WorkbookInspector::scan) and post-splice verification (SheetSplicer).
 *
 * @internal
 */
final class SheetDataScanner
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidWorkbookException when the XML is malformed (including an
     *                                   unclosed <sheetData>) or <sheetData> is missing (C14)
     */
    public static function scanReader(\XMLReader $reader, string $source): SheetScanResult
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $result = self::walk($reader);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }

        if ($errors !== []) {
            throw new InvalidWorkbookException(\sprintf(
                'Worksheet XML in "%s" is malformed: %s.',
                $source,
                trim($errors[0]->message),
            ));
        }

        if (!$result['hasSheetData']) {
            throw new InvalidWorkbookException(\sprintf(
                'Worksheet in "%s" has no <sheetData> element; the file is not a valid worksheet (C14).',
                $source,
            ));
        }

        return new SheetScanResult($result['lastRow'], $result['rowCount']);
    }

    /**
     * @return array{hasSheetData: bool, lastRow: int, rowCount: int}
     */
    private static function walk(\XMLReader $reader): array
    {
        $hasSheetData = false;
        $inside = false;
        $lastRow = 0;
        $rowCount = 0;
        $previousRow = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'sheetData') {
                $hasSheetData = true;

                if (!$reader->isEmptyElement) {
                    $inside = true;
                }

                continue;
            }

            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'sheetData') {
                $inside = false;

                continue;
            }

            if (!$inside || $reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $rowCount++;
            $attribute = $reader->getAttribute('r');
            $row = ($attribute !== null && ctype_digit($attribute)) ? (int) $attribute : $previousRow + 1;
            $previousRow = $row;

            if ($row > $lastRow) {
                $lastRow = $row;
            }
        }

        return [
            'hasSheetData' => $hasSheetData,
            'lastRow' => $lastRow,
            'rowCount' => $rowCount,
        ];
    }
}
