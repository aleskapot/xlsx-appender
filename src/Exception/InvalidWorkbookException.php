<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The file is not a usable XLSX workbook: a required part is missing,
 * malformed, or <sheetData> is absent/unclosed (C14).
 */
final class InvalidWorkbookException extends AppenderException
{
}
