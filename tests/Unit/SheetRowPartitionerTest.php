<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\SheetRowPartitioner;
use XlsxFastAppender\Tests\Support\TestCase;

#[CoversClass(SheetRowPartitioner::class)]
final class SheetRowPartitionerTest extends TestCase
{
    public function testClearDropsEveryRowFromStartRowToTheEnd(): void
    {
        $body = '<row r="1"><c r="A1"/></row><row r="2"><c r="A2"/></row><row r="3"><c r="A3"/></row>';

        $partition = SheetRowPartitioner::partition($body, 2, null);

        self::assertSame('<row r="1"><c r="A1"/></row>', $partition['before']);
        self::assertSame('', $partition['after']);
        self::assertSame(2, $partition['dropped']);
        self::assertSame(1, $partition['keptMaxRow']);
    }

    public function testOverwriteDropsOnlyTheReplacedRange(): void
    {
        $body = '<row r="1"/><row r="2"/><row r="3"/><row r="4"/>';

        $partition = SheetRowPartitioner::partition($body, 2, 3);

        self::assertSame('<row r="1"/>', $partition['before']);
        self::assertSame('<row r="4"/>', $partition['after']);
        self::assertSame(2, $partition['dropped']);
        self::assertSame(4, $partition['keptMaxRow']);
    }

    public function testOverwriteKeepsRowsOutsideAGap(): void
    {
        $body = '<row r="1"/><row r="2"/><row r="10"/>';

        $partition = SheetRowPartitioner::partition($body, 6, 6);

        self::assertSame('<row r="1"/><row r="2"/>', $partition['before']);
        self::assertSame('<row r="10"/>', $partition['after']);
        self::assertSame(0, $partition['dropped']);
        self::assertSame(10, $partition['keptMaxRow']);
    }

    public function testRowWithoutRAttributeContinuesThePreviousNumber(): void
    {
        $body = '<row r="1"><c r="A1"/></row><row><c r="A2"/></row><row><c r="A3"/></row>';

        $partition = SheetRowPartitioner::partition($body, 3, null);

        self::assertSame('<row r="1"><c r="A1"/></row><row><c r="A2"/></row>', $partition['before']);
        self::assertSame('', $partition['after']);
        self::assertSame(1, $partition['dropped']);
        self::assertSame(2, $partition['keptMaxRow']);
    }

    public function testWhitespaceTravelsWithTheRowThatFollowsIt(): void
    {
        $body = "<row r=\"1\"/>\n<row r=\"2\"/>\n<row r=\"3\"/>";

        $partition = SheetRowPartitioner::partition($body, 3, null);

        self::assertSame("<row r=\"1\"/>\n<row r=\"2\"/>\n", $partition['before']);
        self::assertSame('', $partition['after']);
        self::assertSame(1, $partition['dropped']);
    }

    public function testTrailingWhitespaceAfterTheLastRowIsKept(): void
    {
        $body = "<row r=\"1\"/>\n<row r=\"2\"/>\n";

        $partition = SheetRowPartitioner::partition($body, 2, null);

        self::assertSame("<row r=\"1\"/>\n", $partition['before']);
        self::assertSame("\n", $partition['after']);
        self::assertSame(1, $partition['dropped']);
    }

    public function testEmptyBodyProducesEmptyBuckets(): void
    {
        $partition = SheetRowPartitioner::partition('', 1, null);

        self::assertSame('', $partition['before']);
        self::assertSame('', $partition['after']);
        self::assertSame(0, $partition['dropped']);
        self::assertSame(0, $partition['keptMaxRow']);
    }

    public function testMissingCloseTagSwallowsTheRestOfTheBody(): void
    {
        $body = '<row r="1"/><row r="2"><c r="A2"/>';

        $partition = SheetRowPartitioner::partition($body, 1, 1);

        self::assertSame('', $partition['before']);
        self::assertSame('<row r="2"><c r="A2"/>', $partition['after']);
        self::assertSame(1, $partition['dropped']);
        self::assertSame(2, $partition['keptMaxRow']);
    }

    public function testEveryRowDroppedYieldsEmptyBuckets(): void
    {
        $body = '<row r="1"/><row r="2"/>';

        $partition = SheetRowPartitioner::partition($body, 1, null);

        self::assertSame('', $partition['before']);
        self::assertSame('', $partition['after']);
        self::assertSame(2, $partition['dropped']);
        self::assertSame(0, $partition['keptMaxRow']);
    }
}
