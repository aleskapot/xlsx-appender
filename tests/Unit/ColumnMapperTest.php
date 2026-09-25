<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\ColumnMapper;
use XlsxFastAppender\Exception\ColumnCountMismatchException;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Exception\UnsupportedValueException;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(ColumnMapper::class)]
final class ColumnMapperTest extends TestCase
{
    public function testListFormUsesElementsAsKeysAndLabels(): void
    {
        $mapper = new ColumnMapper(['id', 'name']);

        self::assertSame(2, $mapper->width());
        self::assertSame(['id', 'name'], $mapper->headerLabels());
        self::assertSame([1, 'Ada'], $mapper->map([1, 'Ada']));
    }

    public function testAssociativeFormUsesKeysForRecordsAndLabelsForHeader(): void
    {
        $mapper = new ColumnMapper(['user_id' => 'User ID', 'name' => 'Имя']);

        self::assertSame(2, $mapper->width());
        self::assertSame(['User ID', 'Имя'], $mapper->headerLabels());
        self::assertSame([7, 'Ada'], $mapper->map(['user_id' => 7, 'name' => 'Ada']));
    }

    public function testNullColumnsMeansPositionalPassthrough(): void
    {
        $mapper = new ColumnMapper(null);

        self::assertNull($mapper->width());
        self::assertNull($mapper->headerLabels());
        self::assertSame([1, 'x', null], $mapper->map([1, 'x', null]));
        self::assertSame([1], $mapper->map(['only' => 1]));
    }

    public function testEmptyColumnsAreRejected(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('must not be empty');

        new ColumnMapper([]);
    }

    public function testNonStringLabelIsRejected(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('only string values');

        new ColumnMapper(['id', 42]);
    }

    public function testMapCallbackTakesPriorityOverBuiltInMapping(): void
    {
        $mapper = new ColumnMapper(['a', 'b'], false, static fn (array $record): array => [$record['id'], 'mapped']);

        self::assertSame([99, 'mapped'], $mapper->map(['id' => 99]));
    }

    public function testMapCallbackMustReturnArray(): void
    {
        $mapper = new ColumnMapper(null, false, static fn (): string => 'nope');

        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('must return an array');

        $mapper->map('anything');
    }

    public function testScalarRecordWithoutColumnsIsRejected(): void
    {
        $mapper = new ColumnMapper(null);

        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Records must be arrays or objects');

        $mapper->map('scalar');
    }

    public function testObjectRecordWithoutColumnsOrMapIsRejected(): void
    {
        $mapper = new ColumnMapper(null);

        $this->expectException(UnsupportedValueException::class);
        $this->expectExceptionMessage('Object records require');

        $mapper->map(new \stdClass());
    }

    public function testPositionalRecordMustMatchColumnCount(): void
    {
        $mapper = new ColumnMapper(['a', 'b']);

        self::assertSame([1, 2], $mapper->map([1, 2]));

        $this->expectException(ColumnCountMismatchException::class);
        $this->expectExceptionMessage('has 3 values but 2 columns');

        $mapper->map([1, 2, 3]);
    }

    public function testMissingKeyResolvesToNullQuietly(): void
    {
        $mapper = new ColumnMapper(['name' => 'Имя', 'amount' => 'Сумма']);

        self::assertSame(['Ada', null], $mapper->map(['name' => 'Ada', 'extra' => 'ignored']));
    }

    public function testStrictColumnsRejectsUnexpectedKeys(): void
    {
        $mapper = new ColumnMapper(['name'], true);

        $this->expectException(ColumnCountMismatchException::class);
        $this->expectExceptionMessage('unexpected keys: "amount", "extra"');

        $mapper->map(['name' => 'Ada', 'amount' => 1, 'extra' => 2]);
    }

    public function testObjectWithGetAttributeResolver(): void
    {
        $mapper = new ColumnMapper(['id', 'name']);

        self::assertSame([5, 'Ada'], $mapper->map(new AttributeRecord(['id' => 5, 'name' => 'Ada'])));
        self::assertSame([5, null], $mapper->map(new AttributeRecord(['id' => 5])));
    }

    public function testObjectWithPublicProperties(): void
    {
        $mapper = new ColumnMapper(['name', 'amount']);

        self::assertSame(['Ada', 10], $mapper->map(new PublicDto('Ada', 10)));
    }

    public function testObjectWithMagicGetter(): void
    {
        $mapper = new ColumnMapper(['name', 'missing']);

        self::assertSame(['Ada', null], $mapper->map(new MagicDto(['name' => 'Ada'])));
    }

    public function testObjectWithoutMatchingPropertyOrNullableGetterResolvesToNull(): void
    {
        $mapper = new ColumnMapper(['name']);

        self::assertSame([null], $mapper->map(new OpaqueRecord()));
    }
}

/**
 * Eloquent-like record: attribute access goes through getAttribute().
 */
final class AttributeRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(private readonly array $attributes)
    {
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}

/**
 * Plain DTO with public properties.
 */
final class PublicDto
{
    public function __construct(
        public readonly string $name,
        public readonly int $amount,
    ) {
    }
}

/**
 * DTO exposing data only through __get().
 */
final class MagicDto
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }
}

/**
 * Object whose public property names do not match any column.
 */
final class OpaqueRecord
{
    public string $foo = 'bar';
}
