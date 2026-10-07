<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XlsxFastAppender\ConflictMode;
use XlsxFastAppender\StringMode;

#[CoversClass(ConflictMode::class)]
#[CoversClass(StringMode::class)]
final class OptionEnumsTest extends TestCase
{
    /**
     * @return iterable<string, array{ConflictMode, string}>
     */
    public static function provideConflictModes(): iterable
    {
        yield 'error' => [ConflictMode::Error, 'error'];
        yield 'clear' => [ConflictMode::Clear, 'clear'];
        yield 'overwrite' => [ConflictMode::Overwrite, 'overwrite'];
    }

    /**
     * @return iterable<string, array{StringMode, string}>
     */
    public static function provideStringModes(): iterable
    {
        yield 'inline_str' => [StringMode::InlineStr, 'inline_str'];
        yield 'shared_strings' => [StringMode::SharedStrings, 'shared_strings'];
    }

    #[DataProvider('provideConflictModes')]
    public function testConflictModeBackedValueMatchesTheStringOptionValue(
        ConflictMode $case,
        string $value,
    ): void {
        self::assertSame($value, $case->value);
        self::assertSame($case, ConflictMode::tryFrom($value));
    }

    #[DataProvider('provideStringModes')]
    public function testStringModeBackedValueMatchesTheStringOptionValue(
        StringMode $case,
        string $value,
    ): void {
        self::assertSame($value, $case->value);
        self::assertSame($case, StringMode::tryFrom($value));
    }
}
