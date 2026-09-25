<?php

declare(strict_types=1);

namespace XlsxFastAppender\Exception;

/**
 * The XML of the target worksheet exceeds the configured max_sheet_xml_size
 * (variant C reads the sheet into memory, C11).
 */
final class AppenderSizeLimitException extends AppenderException
{
}
