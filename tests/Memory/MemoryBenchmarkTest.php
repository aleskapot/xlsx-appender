<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Memory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use XlsxFastAppender\Tests\Support\InteractsWithTempFiles;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;
use XlsxFastAppender\XlsxAppender;

/**
 * Peak-memory budget test from the Definition of Done (spec §6):
 * peak usage for a 100k-row append must stay within
 * sheet XML size × 2.5 + a fixed constant.
 */
#[Group('memory')]
#[CoversClass(XlsxAppender::class)]
final class MemoryBenchmarkTest extends \PHPUnit\Framework\TestCase
{
    use InteractsWithTempFiles;

    private const ROW_COUNT = 100_000;

    private const PEAK_MULTIPLIER = 2.5;

    private const PEAK_CONSTANT_BYTES = 4_194_304;

    private const SHEET_ENTRY = 'xl/worksheets/sheet1.xml';

    public function testPeakMemoryStaysWithinTheSpecBudgetFor100kRows(): void
    {
        $path = XlsxFixtureFactory::create(
            $this->tempFilePath(),
            ['Data' => XlsxFixtureFactory::emptyWorksheet()],
        );

        $base = memory_get_usage(false);
        memory_reset_peak_usage();

        $startedAt = microtime(true);
        $written = (new XlsxAppender($path, ['start_cell' => 'A2']))->append(self::rows());
        $elapsed = microtime(true) - $startedAt;

        // Exact allocator accounting: page-granular (true) readings show no
        // growth on streaming writes and would make the budget vacuous.
        $peakDelta = memory_get_peak_usage(false) - $base;

        self::assertSame(self::ROW_COUNT, $written);

        $sheetSize = \strlen($this->readEntry($path, self::SHEET_ENTRY));
        $budget = (int) round($sheetSize * self::PEAK_MULTIPLIER) + self::PEAK_CONSTANT_BYTES;

        self::assertLessThanOrEqual(
            $budget,
            $peakDelta,
            \sprintf(
                'Peak memory %d bytes exceeds the budget %d bytes '
                .'(sheet XML %d bytes × %.1f + %d constant); elapsed %.2f s.',
                $peakDelta,
                $budget,
                $sheetSize,
                self::PEAK_MULTIPLIER,
                self::PEAK_CONSTANT_BYTES,
                $elapsed,
            ),
        );
    }

    /**
     * @return \Generator<int, list<string>>
     */
    private static function rows(): \Generator
    {
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            yield ['row-'.$i, 'value-'.$i, (string) $i];
        }
    }

    private function readEntry(string $path, string $entry): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            self::fail(\sprintf('Unable to open zip "%s".', $path));
        }

        $contents = $zip->getFromName($entry);
        $zip->close();

        if ($contents === false) {
            self::fail(\sprintf('Entry "%s" missing in "%s".', $entry, $path));
        }

        return $contents;
    }
}
