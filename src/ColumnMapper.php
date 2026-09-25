<?php

declare(strict_types=1);

namespace XlsxFastAppender;

use XlsxFastAppender\Exception\ColumnCountMismatchException;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Exception\UnsupportedValueException;

/**
 * Resolves a record into positional cell values and provides header labels
 * (§3.5 of the specification).
 *
 * Resolution priority: map() callback > key-based (`columns` with assoc
 * arrays / objects) > positional with arity check > default positional.
 */
final class ColumnMapper
{
    /**
     * @var list<string>|null ordered column keys, null when positional-only
     */
    private readonly ?array $columns;

    /**
     * @var list<string>|null header labels in column order, null when no `columns`
     */
    private readonly ?array $labels;

    /**
     * @param array<mixed>|null $columns list of keys/labels or key => label pairs
     */
    public function __construct(
        ?array $columns,
        private readonly bool $strictColumns = false,
        private readonly ?\Closure $recordMap = null,
    ) {
        if ($columns === null) {
            $this->columns = null;
            $this->labels = null;

            return;
        }

        if ($columns === []) {
            throw new InvalidOptionException('Option "columns" must not be empty.');
        }

        $keys = [];
        $labels = [];
        $isList = array_is_list($columns);

        foreach ($columns as $key => $label) {
            if (!\is_string($label)) {
                throw new InvalidOptionException(\sprintf(
                    'Option "columns" must contain only string values, got %s.',
                    get_debug_type($label),
                ));
            }

            $keys[] = $isList ? $label : (string) $key;
            $labels[] = $label;
        }

        $this->columns = $keys;
        $this->labels = $labels;
    }

    public function width(): ?int
    {
        return $this->columns === null ? null : \count($this->columns);
    }

    /**
     * @return list<string>|null
     */
    public function headerLabels(): ?array
    {
        return $this->labels;
    }

    /**
     *
     * @throws ColumnCountMismatchException when the record does not match `columns`
     * @throws UnsupportedValueException     when the record type cannot be resolved
     *
     * @return list<mixed> positional cell values, null entries included
     */
    public function map(mixed $record): array
    {
        if ($this->recordMap !== null) {
            $values = ($this->recordMap)($record);

            if (!\is_array($values)) {
                throw new UnsupportedValueException(\sprintf(
                    'The map() callback must return an array, got %s.',
                    get_debug_type($values),
                ));
            }

            return array_values($values);
        }

        if (!\is_array($record) && !\is_object($record)) {
            throw new UnsupportedValueException(\sprintf(
                'Records must be arrays or objects, got %s; use map() to transform them.',
                get_debug_type($record),
            ));
        }

        $columns = $this->columns;

        if ($columns === null) {
            if (\is_object($record)) {
                throw new UnsupportedValueException(
                    'Object records require the map() callback or the "columns" option.',
                );
            }

            return array_values($record);
        }

        if (\is_array($record)) {
            if (array_is_list($record)) {
                return $this->mapPositional($record, \count($columns));
            }

            return $this->mapByKey($record, $columns);
        }

        return $this->mapObject($record, $columns);
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private function mapPositional(array $values, int $expectedWidth): array
    {
        if (\count($values) !== $expectedWidth) {
            throw new ColumnCountMismatchException(\sprintf(
                'Record has %d values but %d columns are configured.',
                \count($values),
                $expectedWidth,
            ));
        }

        return $values;
    }

    /**
     * @param array<mixed> $record
     * @param list<string> $columns
     *
     * @return list<mixed>
     */
    private function mapByKey(array $record, array $columns): array
    {
        if ($this->strictColumns) {
            $extra = array_diff(array_keys($record), $columns);

            if ($extra !== []) {
                $quoted = array_map(
                    static fn (string|int $key): string => '"'.$key.'"',
                    array_values($extra),
                );

                throw new ColumnCountMismatchException(\sprintf(
                    'Record has unexpected keys: %s (strict_columns is enabled).',
                    implode(', ', $quoted),
                ));
            }
        }

        $values = [];

        foreach ($columns as $key) {
            $values[] = $record[$key] ?? null;
        }

        return $values;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<mixed>
     */
    private function mapObject(object $record, array $columns): array
    {
        $publicProperties = get_object_vars($record);
        $values = [];

        foreach ($columns as $key) {
            if (method_exists($record, 'getAttribute')) {
                $values[] = $record->getAttribute($key);
            } elseif (\array_key_exists($key, $publicProperties)) {
                $values[] = $publicProperties[$key];
            } elseif (method_exists($record, '__get')) {
                $values[] = $record->__get($key);
            } else {
                $values[] = null;
            }
        }

        return $values;
    }
}
