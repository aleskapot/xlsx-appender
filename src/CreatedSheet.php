<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * In-memory description of a worksheet part created by WorkbookSheetCreator.
 *
 * The caller stages the patched workbook parts and the worksheet XML
 * separately, so the archive never has to be re-read before it is saved.
 */
final class CreatedSheet
{
    public function __construct(
        public readonly string $entry,
        public readonly string $worksheetXml,
        public readonly string $workbookXml,
        public readonly string $relationshipsXml,
        public readonly string $contentTypesXml,
    ) {
    }
}
