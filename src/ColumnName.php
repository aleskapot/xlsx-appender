<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidCellReferenceException;

/**
 * Conversion between 1-based column indexes and Excel column names (A..XFD).
 */
final class ColumnName
{
    /**
     * Converts a 1-based column index into its letter name (1 => "A", 27 => "AA").
     *
     * @throws InvalidCellReferenceException when the index is outside 1..16384
     */
    public static function of(int $index): string
    {
        if ($index < 1 || $index > ExcelLimits::MAX_COLUMNS) {
            throw new InvalidCellReferenceException(\sprintf(
                'Column index %d is out of range; Excel columns are 1..%d (A..XFD).',
                $index,
                ExcelLimits::MAX_COLUMNS,
            ));
        }

        $name = '';

        while ($index > 0) {
            $index--;
            $name = \chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /**
     * Converts a letter name ("A", "aa", "XFD") into a 1-based column index.
     *
     * @throws InvalidCellReferenceException when the name is malformed or exceeds XFD
     */
    public static function indexOf(string $name): int
    {
        if (preg_match('/^[A-Za-z]{1,3}$/', $name) !== 1) {
            throw new InvalidCellReferenceException(\sprintf(
                'Invalid column name "%s"; expected 1..3 letters (A..XFD).',
                $name,
            ));
        }

        $name = strtoupper($name);
        $index = 0;

        for ($i = 0, $length = \strlen($name); $i < $length; $i++) {
            $index = ($index * 26) + (\ord($name[$i]) - 64);
        }

        if ($index > ExcelLimits::MAX_COLUMNS) {
            throw new InvalidCellReferenceException(\sprintf(
                'Column "%s" exceeds the last Excel column XFD (index %d).',
                $name,
                ExcelLimits::MAX_COLUMNS,
            ));
        }

        return $index;
    }
}
