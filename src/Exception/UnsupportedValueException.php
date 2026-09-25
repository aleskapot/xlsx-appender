<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * A value cannot be represented in an XLSX cell: unsupported PHP type,
 * INF/NAN, a string beyond the Excel character limit (C9, C15), or a string
 * containing characters that XML 1.0 forbids.
 */
final class UnsupportedValueException extends AppenderException
{
}
