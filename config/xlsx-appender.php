<?php

declare(strict_types=1);

/*
 * Defaults for XlsxFastAppender (§3.3 of the specification).
 *
 * `conflict_mode` and `mode` accept the plain strings below or the matching
 * `XlsxFastAppender\ConflictMode` / `XlsxFastAppender\StringMode` enum cases.
 *
 * `date_format` is an Excel number-format code (e.g. 'DD.MM.YYYY'); null
 * keeps every value as-is (strings stay text, DateTime values are rejected).
 *
 * `numeric_strings` converts strict numeric literals arriving as strings
 * ("0", ".7", "1e5") into numeric cells; false keeps every string as text.
 * Values with surrounding whitespace or leading zeros ("007") stay text,
 * and header labels are never converted. Enabled by default.
 *
 * Publish with: php artisan vendor:publish --tag=xlsx-appender
 */

return [
    'sheet' => null,
    'create_sheet_if_missing' => false,
    'start_cell' => 'A2',
    'conflict_mode' => 'error',
    'mode' => 'inline_str',
    'max_sheet_xml_size' => 268435456,
    'use_lock' => true,
    'lock_timeout' => 30.0,
    'skip_nulls' => true,
    'columns' => null,
    'write_header' => false,
    'strict_columns' => false,
    'date_format' => null,
    'numeric_strings' => true,
];
