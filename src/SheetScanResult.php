<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * Structural information about a worksheet collected by {@see WorkbookInspector::scan()}.
 */
final class SheetScanResult
{
    /**
     * @param int $lastRow highest existing row number (0 when the sheet has no rows)
     * @param int $rowCount number of <row> elements present in <sheetData>
     */
    public function __construct(
        public readonly int $lastRow,
        public readonly int $rowCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rowCount === 0;
    }
}
