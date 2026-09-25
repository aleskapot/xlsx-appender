<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use XlsxFastAppender\Exception\SheetNotFoundException;

#[CoversClass(SheetNotFoundException::class)]
final class SheetNotFoundExceptionTest extends TestCase
{
    public function testCarriesAvailableSheetNamesAndListsThemInMessage(): void
    {
        $exception = new SheetNotFoundException('Missing', ['Alpha', 'Beta']);

        self::assertSame(['Alpha', 'Beta'], $exception->availableSheets);
        self::assertStringContainsString('Sheet "Missing" not found', $exception->getMessage());
        self::assertStringContainsString('Alpha, Beta', $exception->getMessage());
    }

    public function testReportsWhenWorkbookHasNoSheets(): void
    {
        $exception = new SheetNotFoundException('Missing', []);

        self::assertSame([], $exception->availableSheets);
        self::assertStringContainsString('(none)', $exception->getMessage());
    }
}
