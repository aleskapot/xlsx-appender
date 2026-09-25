<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use XlsxFastAppender\Laravel\XlsxFastAppenderServiceProvider;
use XlsxFastAppender\Tests\Support\InteractsWithTempFiles;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;

/**
 * Testbench base wiring the package's service provider plus the shared
 * temp-file helpers.
 */
abstract class LaravelTestCase extends TestbenchTestCase
{
    use InteractsWithTempFiles;

    protected function getPackageProviders(mixed $app): array
    {
        return [XlsxFastAppenderServiceProvider::class];
    }

    /**
     * Testbench types its $app property as nullable; this accessor narrows it.
     */
    protected function container(): Application
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        return $app;
    }

    protected function config(): Repository
    {
        return $this->container()->make(Repository::class);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $sheets
     * @param array<string, string> $extraEntries
     */
    protected function fixture(array $sheets, array $extraEntries = []): string
    {
        return XlsxFixtureFactory::create($this->tempFilePath(), $sheets, $extraEntries);
    }

    protected function readEntry(string $path, string $entry): string
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
