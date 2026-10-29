<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidCellReferenceException;
use XlsxFastAppender\Exception\UnsupportedValueException;

/**
 * Serializes one row of PHP values into a `<row>` XML fragment using
 * inline-string cells (§2.3 of the specification).
 *
 * When a date style index is attached (date_format option, §3.4), strings in
 * a recognized date shape and DateTimeInterface values become serial-number
 * cells carrying that style; everything else follows the numeric/text rules.
 *
 * With the numeric_strings option, strings that are strict plain-decimal
 * literals ("0", ".7", "1e5") become numeric cells; headers and values with
 * whitespace or leading zeros stay text.
 *
 * Values are validated before any XML is produced, so a rejected row never
 * reaches the output stream: the exception carries the cell address (C9).
 */
final class RowXmlGenerator
{
    /**
     * Characters that XML 1.0 forbids anywhere in a document.
     */
    public const INVALID_XML_CHARS = '/^[^\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]*$/u';

    /**
     * Strict numeric-literal shape used by the numeric_strings option:
     * no surrounding whitespace, no hex/underscore/decimal-comma forms
     * that is_numeric() would also accept.
     */
    private const NUMERIC_STRING = '/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/';

    public function __construct(
        private readonly int $startColumn,
        private readonly bool $skipNulls,
        private readonly ?SharedStringsStore $strings = null,
        private readonly ?int $dateStyle = null,
        private readonly bool $numericStrings = false,
    ) {
    }

    public function startColumn(): int
    {
        return $this->startColumn;
    }

    /**
     * @param list<mixed> $values               cell values, positional from $startColumn
     * @param bool        $allowNumericStrings  when false (header rows), strings that
     *                                          look numeric stay text even with the
     *                                          numeric_strings option enabled
     *
     * @throws InvalidCellReferenceException when the row or a column address is outside Excel limits
     * @throws UnsupportedValueException     when a value cannot be stored in a cell
     */
    public function rowXml(int $rowNumber, array $values, bool $allowNumericStrings = true): string
    {
        if ($rowNumber < 1 || $rowNumber > ExcelLimits::MAX_ROWS) {
            throw new InvalidCellReferenceException(\sprintf(
                'Row %d is outside the Excel limits (1..%d).',
                $rowNumber,
                ExcelLimits::MAX_ROWS,
            ));
        }

        $this->assertColumnsFit($rowNumber, \count($values));

        $validated = [];

        foreach ($values as $offset => $value) {
            if ($value === null && $this->skipNulls) {
                continue;
            }

            $validated[$offset] = $this->representableValue($rowNumber, $offset, $value, $allowNumericStrings);
        }

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(false);
        $writer->startElement('row');
        $writer->writeAttribute('r', (string) $rowNumber);

        foreach ($validated as $offset => $value) {
            $this->writeCell($writer, $rowNumber, $offset, $value);
        }

        $writer->endElement();

        return $writer->outputMemory();
    }

    private function assertColumnsFit(int $rowNumber, int $valueCount): void
    {
        if ($valueCount === 0) {
            return;
        }

        $lastColumn = $this->startColumn + $valueCount - 1;

        if ($lastColumn > ExcelLimits::MAX_COLUMNS) {
            throw new InvalidCellReferenceException(\sprintf(
                'Row %d needs column %d, which exceeds the Excel limit of %d columns (XFD).',
                $rowNumber,
                $lastColumn,
                ExcelLimits::MAX_COLUMNS,
            ));
        }
    }

    /**
     * Validates one cell value and returns it with its narrowed type so the
     * emission step does not have to deal with mixed.
     *
     * Value interpretation order for strings: recognized date shape (when a
     * date style is attached) first, then — with the numeric_strings option —
     * a strict numeric literal, and finally text.
     */
    private function representableValue(int $rowNumber, int $offset, mixed $value, bool $allowNumericStrings = true): int|float|string|bool|null|DateCell
    {
        $column = $this->startColumn + $offset;
        $dateStyle = $this->dateStyle;

        if ($value === null) {
            return null;
        }

        if ($dateStyle !== null) {
            if ($value instanceof \DateTimeInterface) {
                return new DateCell(ExcelDate::serialOf($value, $column, $rowNumber), $dateStyle);
            }

            if (\is_string($value)) {
                $parsed = ExcelDate::tryParse($value);

                if ($parsed !== null) {
                    return new DateCell(ExcelDate::serialOf($parsed, $column, $rowNumber), $dateStyle);
                }
            }
        }

        if (\is_string($value)) {
            $this->assertString($value, $column, $rowNumber);

            if ($this->numericStrings && $allowNumericStrings) {
                $number = $this->tryParseNumericString($value, $column, $rowNumber);

                if ($number !== null) {
                    return $number;
                }
            }

            return $value;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new UnsupportedValueException(\sprintf(
                    'Cell %s%d: INF and NAN cannot be stored in Excel; convert the value before appending.',
                    ColumnName::of($column),
                    $rowNumber,
                ));
            }

            return $value;
        }

        if (\is_bool($value)) {
            return $value;
        }

        throw new UnsupportedValueException(\sprintf(
            'Cell %s%d: values of type %s are not supported; convert them to string, int, float or bool first.',
            ColumnName::of($column),
            $rowNumber,
            get_debug_type($value),
        ));
    }

    private function assertString(string $value, int $column, int $rowNumber): void
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length > ExcelLimits::MAX_STRING_LENGTH) {
            throw new UnsupportedValueException(\sprintf(
                'Cell %s%d: string is %d characters long, exceeding the Excel limit of %d characters.',
                ColumnName::of($column),
                $rowNumber,
                $length,
                ExcelLimits::MAX_STRING_LENGTH,
            ));
        }

        if (preg_match(self::INVALID_XML_CHARS, $value) !== 1) {
            throw new UnsupportedValueException(\sprintf(
                'Cell %s%d: string contains characters that are not allowed in XML documents '
                .'(control characters other than tab, newline or carriage return).',
                ColumnName::of($column),
                $rowNumber,
            ));
        }
    }

    /**
     * Converts a numeric-string cell value (numeric_strings option) to int or
     * float, or returns null when the string must stay text.
     *
     * Strings with surrounding whitespace, with leading zeros in the integer
     * part ("007", "0755" — typically codes or identifiers), or in a non-
     * plain-decimal shape are deliberately kept as text. A literal whose
     * float value overflows ("1e999") is rejected like a native INF float.
     */
    private function tryParseNumericString(string $value, int $column, int $rowNumber): int|float|null
    {
        if (preg_match(self::NUMERIC_STRING, $value) !== 1) {
            return null;
        }

        $unsigned = ltrim($value, '+-');
        $exponentPosition = stripos($unsigned, 'e');
        $mantissa = $exponentPosition === false ? $unsigned : substr($unsigned, 0, $exponentPosition);
        $dotPosition = strpos($mantissa, '.');
        $integerPart = $dotPosition === false ? $mantissa : substr($mantissa, 0, $dotPosition);

        if (\strlen($integerPart) > 1 && $integerPart[0] === '0') {
            return null;
        }

        if ($dotPosition !== false || $exponentPosition !== false) {
            $number = (float) $value;

            if (!is_finite($number)) {
                throw new UnsupportedValueException(\sprintf(
                    'Cell %s%d: INF and NAN cannot be stored in Excel; convert the value before appending.',
                    ColumnName::of($column),
                    $rowNumber,
                ));
            }

            return $number;
        }

        $digits = ltrim($unsigned, '0');

        if ($digits === '') {
            $digits = '0';
        }

        $boundary = ($value[0] === '-' ? '9223372036854775808' : '9223372036854775807');

        $fitsInt = \strlen($digits) < \strlen($boundary)
            || (\strlen($digits) === \strlen($boundary) && strcmp($digits, $boundary) <= 0);

        return $fitsInt ? (int) $value : (float) $value;
    }

    private function writeCell(\XMLWriter $writer, int $rowNumber, int $offset, int|float|string|bool|null|DateCell $value): void
    {
        $reference = ColumnName::of($this->startColumn + $offset).$rowNumber;

        if ($value === null) {
            $writer->startElement('c');
            $writer->writeAttribute('r', $reference);
            $writer->endElement();

            return;
        }

        if ($value instanceof DateCell) {
            $writer->startElement('c');
            $writer->writeAttribute('r', $reference);
            $writer->writeAttribute('s', (string) $value->style);
            $writer->startElement('v');
            $writer->text(\is_int($value->serial) ? (string) $value->serial : self::floatToPlainDecimal($value->serial));
            $writer->endElement();
            $writer->endElement();

            return;
        }

        $writer->startElement('c');
        $writer->writeAttribute('r', $reference);

        if (\is_string($value)) {
            if ($this->strings !== null) {
                $writer->writeAttribute('t', 's');
                $writer->startElement('v');
                $writer->text((string) $this->strings->index($value));
                $writer->endElement();

                $writer->endElement();

                return;
            }

            $writer->writeAttribute('t', 'inlineStr');
            $writer->startElement('is');
            $writer->startElement('t');

            if ($value !== trim($value)) {
                $writer->writeAttribute('xml:space', 'preserve');
            }

            $writer->text($value);
            $writer->endElement();
            $writer->endElement();
        } elseif (\is_bool($value)) {
            $writer->writeAttribute('t', 'b');
            $writer->startElement('v');
            $writer->text($value ? '1' : '0');
            $writer->endElement();
        } elseif (\is_float($value)) {
            $writer->startElement('v');
            $writer->text(self::floatToPlainDecimal($value));
            $writer->endElement();
        } else {
            $writer->startElement('v');
            $writer->text((string) $value);
            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * Renders a float with round-trip precision in plain decimal notation:
     * json_encode() yields the shortest round-trip form, an exponent is then
     * expanded so the serialized value never uses scientific notation.
     */
    private static function floatToPlainDecimal(float $value): string
    {
        $encoded = json_encode($value);

        if ($encoded === false) {
            throw new UnsupportedValueException('Unable to serialize float value.');
        }

        $exponentPosition = stripos($encoded, 'e');

        if ($exponentPosition === false) {
            return $encoded;
        }

        $mantissa = substr($encoded, 0, $exponentPosition);
        $exponent = (int) substr($encoded, $exponentPosition + 1);
        $negative = str_starts_with($mantissa, '-');

        if ($negative) {
            $mantissa = substr($mantissa, 1);
        }

        $dotPosition = strpos($mantissa, '.');
        $integerPart = $dotPosition === false ? $mantissa : substr($mantissa, 0, $dotPosition);
        $fractionalPart = $dotPosition === false ? '' : substr($mantissa, $dotPosition + 1);
        $digits = $integerPart.$fractionalPart;
        $decimalOffset = \strlen($integerPart) + $exponent;

        if ($decimalOffset <= 0) {
            $plain = '0.'.str_repeat('0', -$decimalOffset).$digits;
        } elseif ($decimalOffset >= \strlen($digits)) {
            $plain = $digits.str_repeat('0', $decimalOffset - \strlen($digits));
        } else {
            $plain = substr($digits, 0, $decimalOffset).'.'.substr($digits, $decimalOffset);
        }

        return ($negative ? '-' : '').$plain;
    }
}
