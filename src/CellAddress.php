<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidCellReferenceException;

/**
 * A parsed, validated cell reference such as "A1" or "$B$2".
 *
 * Both coordinates are 1-based, exactly like the {@see ExcelLimits} constants.
 */
final class CellAddress
{
    /**
     * @throws InvalidCellReferenceException when column or row is outside the Excel range
     */
    public function __construct(
        public readonly int $column,
        public readonly int $row,
    ) {
        if ($column < 1 || $column > ExcelLimits::MAX_COLUMNS) {
            throw new InvalidCellReferenceException(\sprintf(
                'Column %d is out of range; Excel columns are 1..%d (A..XFD).',
                $column,
                ExcelLimits::MAX_COLUMNS,
            ));
        }

        if ($row < 1 || $row > ExcelLimits::MAX_ROWS) {
            throw new InvalidCellReferenceException(\sprintf(
                'Row %d is out of range; Excel rows are 1..%d.',
                $row,
                ExcelLimits::MAX_ROWS,
            ));
        }
    }

    /**
     * Parses references like "A1", "b2", "$C$5". Anchors and letter case are ignored.
     *
     * @throws InvalidCellReferenceException when the reference is malformed or out of range
     */
    public static function parse(string $reference): self
    {
        if (preg_match('/^\$?([A-Za-z]{1,3})\$?([1-9][0-9]{0,6})$/', $reference, $matches) !== 1) {
            throw new InvalidCellReferenceException(\sprintf(
                'Invalid cell reference "%s"; expected a reference like "A1" or "$B$2".',
                $reference,
            ));
        }

        return new self(ColumnName::indexOf($matches[1]), (int) $matches[2]);
    }

    public function toString(): string
    {
        return ColumnName::of($this->column).$this->row;
    }
}
