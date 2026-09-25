<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\InvalidCellReferenceException;
use XlsxFastAppender\Exception\UnsupportedValueException;

/**
 * Serializes one row of PHP values into a `<row>` XML fragment using
 * inline-string cells (§2.3 of the specification).
 *
 * Values are validated before any XML is produced, so a rejected row never
 * reaches the output stream: the exception carries the cell address (C9).
 */
final class RowXmlGenerator
{
    /**
     * Characters that XML 1.0 forbids anywhere in a document.
     */
    private const INVALID_XML_CHARS = '/^[^\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]*$/u';

    public function __construct(
        private readonly int $startColumn,
        private readonly bool $skipNulls,
        private readonly ?SharedStringsStore $strings = null,
    ) {
    }

    public function startColumn(): int
    {
        return $this->startColumn;
    }

    /**
     * @param list<mixed> $values cell values, positional from $startColumn
     *
     * @throws InvalidCellReferenceException when the row or a column address is outside Excel limits
     * @throws UnsupportedValueException     when a value cannot be stored in a cell
     */
    public function rowXml(int $rowNumber, array $values): string
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

            $validated[$offset] = $this->representableValue($rowNumber, $offset, $value);
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
     */
    private function representableValue(int $rowNumber, int $offset, mixed $value): int|float|string|bool|null
    {
        $column = $this->startColumn + $offset;

        if ($value === null) {
            return null;
        }

        if (\is_string($value)) {
            $this->assertString($value, $column, $rowNumber);

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

    private function writeCell(\XMLWriter $writer, int $rowNumber, int $offset, int|float|string|bool|null $value): void
    {
        $reference = ColumnName::of($this->startColumn + $offset).$rowNumber;

        if ($value === null) {
            $writer->startElement('c');
            $writer->writeAttribute('r', $reference);
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
