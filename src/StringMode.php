<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * How string cells are written into the worksheet.
 *
 * Accepted interchangeably with its string value, so
 * `['mode' => StringMode::SharedStrings]` and `['mode' => 'shared_strings']`
 * are the same option.
 */
enum StringMode: string
{
    /**
     * Each string cell carries its text inline (the default).
     */
    case InlineStr = 'inline_str';

    /**
     * String cells reference xl/sharedStrings.xml, deduplicated.
     */
    case SharedStrings = 'shared_strings';
}
