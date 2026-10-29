<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\UnsupportedValueException;

/**
 * Converts between PHP dates and the Excel 1900 serial date system.
 *
 * Strings are recognized by a fixed set of common shapes (§3.4 of the
 * specification); anything else stays a plain string. A date is stored as the
 * number of days since 1899-12-30 — Excel counts the fictional 1900-02-29,
 * so dates before 1900-03-01 use the 1899-12-31 epoch — plus an optional
 * fraction of the day for the time component.
 */
final class ExcelDate
{
    /**
     * createFromFormat() patterns tried in order. The leading `!` resets
     * unspecified fields, so "2026-10-29" never inherits the current time.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '!Y-m-d',
        '!Y-m-d H:i:s',
        '!Y-m-d\TH:i:s',
        '!d.m.Y',
        '!d.m.Y H:i:s',
    ];

    /**
     * Cheap pre-check mirroring the shapes of self::PATTERNS so ordinary
     * strings never reach DateTimeImmutable::createFromFormat(). Grouped
     * alternation: ISO dates on the left, dotted European dates on the right.
     */
    private const SHAPE = '/^(?:'
        .'\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?'
        .'|'
        .'\d{2}\.\d{2}\.\d{4}(?: \d{2}:\d{2}:\d{2})?'
        .')$/';

    private const FIRST_YEAR = 1900;

    private const LAST_YEAR = 9999;

    /**
     * From 1900-03-01 onwards the serial counts from 1899-12-30; the
     * fictional 1900-02-29 shifts everything in Jan/Feb 1900 one day back.
     */
    private const LEAP_BUG_EPOCH_MONTH_DAY = '0301';

    /**
     * Parses a string as a date when it matches one of the supported shapes
     * exactly; returns null for every other string (they stay plain text).
     */
    public static function tryParse(string $value): ?\DateTimeImmutable
    {
        if (preg_match(self::SHAPE, $value) !== 1) {
            return null;
        }

        foreach (self::PATTERNS as $pattern) {
            $parsed = \DateTimeImmutable::createFromFormat($pattern, $value);

            if ($parsed === false) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();

            // Warnings cover value rollovers ("2020-02-31" becomes March 2)
            // and trailing data; both must leave the string untouched.
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            if ($parsed->format(substr($pattern, 1)) !== $value) {
                continue;
            }

            return $parsed;
        }

        return null;
    }

    /**
     * Renders a date as an Excel 1900-system serial number: whole days plus
     * the fraction of the day, rounded to sub-millisecond precision.
     * The timezone of $date is used as-is (no UTC conversion).
     *
     * @param int $column 1-based column of the cell, for error messages
     * @param int $row    1-based row of the cell, for error messages
     *
     * @throws UnsupportedValueException when the date is outside the representable range
     */
    public static function serialOf(\DateTimeInterface $date, int $column, int $row): int|float
    {
        $cell = ColumnName::of($column).$row;
        $day = ($date instanceof \DateTimeImmutable ? $date : clone $date)->setTime(0, 0, 0);
        $year = (int) $day->format('Y');

        if ($year < self::FIRST_YEAR) {
            throw new UnsupportedValueException(\sprintf(
                'Cell %s: dates before 1900-01-01 cannot be stored in Excel; convert the value before appending.',
                $cell,
            ));
        }

        if ($year > self::LAST_YEAR) {
            throw new UnsupportedValueException(\sprintf(
                'Cell %s: dates after 9999-12-31 cannot be stored in Excel; convert the value before appending.',
                $cell,
            ));
        }

        $epochInLeapBugRange = $year === self::FIRST_YEAR && $day->format('md') < self::LEAP_BUG_EPOCH_MONTH_DAY;
        $epoch = new \DateTimeImmutable(
            ($epochInLeapBugRange ? '1899-12-31' : '1899-12-30').' 00:00:00',
            $date->getTimezone(),
        );
        $days = (int) $epoch->diff($day)->format('%r%a');
        $seconds = (int) $date->format('H') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s');
        $serial = round($days + $seconds / 86400, 7);

        return fmod($serial, 1.0) === 0.0 ? (int) $serial : $serial;
    }
}
