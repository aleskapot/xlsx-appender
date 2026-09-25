<?php

declare(strict_types=1);

/*
 * Defaults for XlsxFastAppender (§3.3 of the specification).
 *
 * Publish with: php artisan vendor:publish --tag=xlsx-appender
 */

return [
    'sheet' => null,
    'start_cell' => 'A2',
    'mode' => 'inline_str',
    'max_sheet_xml_size' => 268435456,
    'use_lock' => true,
    'lock_timeout' => 30.0,
    'skip_nulls' => true,
    'columns' => null,
    'write_header' => false,
    'strict_columns' => false,
];
