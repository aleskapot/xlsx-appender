<?php

declare(strict_types=1);

namespace XlsxFastAppender;

/**
 * What to do when `start_cell` points at rows that already exist (C2/C3).
 *
 * Accepted interchangeably with its string value, so
 * `['conflict_mode' => ConflictMode::Clear]` and `['conflict_mode' => 'clear']`
 * are the same option.
 */
enum ConflictMode: string
{
    /**
     * Throw StartCellConflictException (the default).
     */
    case Error = 'error';

    /**
     * Drop every existing row from start_cell's row to the end of the sheet.
     */
    case Clear = 'clear';

    /**
     * Replace only the rows covered by the new batch; the rest survive.
     */
    case Overwrite = 'overwrite';
}
