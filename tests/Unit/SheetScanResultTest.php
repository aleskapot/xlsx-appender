<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use XlsxFastAppender\SheetScanResult;

#[CoversClass(SheetScanResult::class)]
final class SheetScanResultTest extends TestCase
{
    public function testExposesCollectedValues(): void
    {
        $result = new SheetScanResult(42, 7);

        self::assertSame(42, $result->lastRow);
        self::assertSame(7, $result->rowCount);
    }

    public function testIsEmptyReflectsRowCount(): void
    {
        self::assertTrue((new SheetScanResult(0, 0))->isEmpty());
        self::assertFalse((new SheetScanResult(3, 3))->isEmpty());
    }
}
