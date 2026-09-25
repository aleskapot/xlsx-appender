<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * Hard limits of the Excel (OOXML SpreadsheetML) format.
 *
 * @see https://learn.microsoft.com/en-us/openspecs/office_standards/ms-xlsx/
 */
final class ExcelLimits
{
    /**
     * Maximum number of columns (last column is XFD).
     */
    public const MAX_COLUMNS = 16384;

    /**
     * Maximum number of rows per worksheet.
     */
    public const MAX_ROWS = 1048576;

    /**
     * Maximum length of a single string cell value, in characters.
     */
    public const MAX_STRING_LENGTH = 32767;
}
