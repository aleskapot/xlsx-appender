<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Laravel;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\Laravel\AppenderFactory;
use XlsxFastAppender\Laravel\XlsxFastAppenderServiceProvider;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;
use XlsxFastAppender\XlsxAppender;

/**
 * ServiceProvider behaviour: config defaults, container bindings and
 * config publishing (§3.3 of the specification).
 */
#[CoversClass(XlsxFastAppenderServiceProvider::class)]
#[CoversClass(AppenderFactory::class)]
final class ServiceProviderTest extends LaravelTestCase
{
    private const SHEET_ENTRY = 'xl/worksheets/sheet1.xml';

    public function testConfigDefaultsMatchTheSpecification(): void
    {
        $config = $this->config()->get('xlsx-appender');

        self::assertIsArray($config);
        self::assertNull($config['sheet']);
        self::assertSame('A2', $config['start_cell']);
        self::assertSame('error', $config['conflict_mode']);
        self::assertSame('inline_str', $config['mode']);
        self::assertSame(268435456, $config['max_sheet_xml_size']);
        self::assertTrue($config['use_lock']);
        self::assertSame(30.0, $config['lock_timeout']);
        self::assertTrue($config['skip_nulls']);
        self::assertNull($config['columns']);
        self::assertFalse($config['write_header']);
        self::assertFalse($config['strict_columns']);
    }

    public function testContainerBindsFreshAppendersThatReadConfig(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $first = $this->container()->make(XlsxAppender::class, ['path' => $path]);
        $second = $this->container()->make(XlsxAppender::class, ['path' => $path]);

        self::assertNotSame($first, $second);

        self::assertSame(1, $first->append([['configured']]));

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="2">', $sheet);
        self::assertStringContainsString('configured', $sheet);
    }

    public function testContainerOptionsParameterOverridesConfig(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $appender = $this->container()->make(XlsxAppender::class, [
            'path' => $path,
            'options' => ['start_cell' => 'C3'],
        ]);

        self::assertSame(1, $appender->append([['deep']]));

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="C3"', $sheet);
    }

    public function testResolvingWithoutAPathFails(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('"path"');

        $this->container()->make(XlsxAppender::class);
    }

    public function testResolvingWithANonArrayOptionsParameterFails(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('"options"');

        $this->container()->make(XlsxAppender::class, [
            'path' => $this->tempFilePath(),
            'options' => 'not-an-array',
        ]);
    }

    public function testNonArrayConfigFallsBackToEmptyOptions(): void
    {
        $this->config()->set('xlsx-appender', 'not-an-array');

        $factory = $this->container()->make(AppenderFactory::class);

        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        // Empty config falls back to core defaults (start_cell A1).
        self::assertSame(1, $factory->make($path)->append([['fallback']]));

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="1">', $sheet);
    }

    public function testNonStringConfigKeysAreDroppedWhileStringKeysApply(): void
    {
        $this->config()->set('xlsx-appender', [7 => 'ignored', 'start_cell' => 'B7']);

        $factory = $this->container()->make(AppenderFactory::class);

        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        self::assertSame(1, $factory->make($path)->append([['keyed']]));

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<c r="B7"', $sheet);
    }

    public function testConfigIsPublishableUnderThePackageTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            XlsxFastAppenderServiceProvider::class,
            'xlsx-appender',
        );

        self::assertNotEmpty($paths);
        self::assertContains(
            \dirname(__DIR__, 2).'/config/xlsx-appender.php',
            array_keys($paths),
        );
        self::assertContains(
            $this->container()->configPath('xlsx-appender.php'),
            array_values($paths),
        );
    }

    public function testFactoryIsBoundAsANonSingleton(): void
    {
        $first = $this->container()->make(AppenderFactory::class);
        $second = $this->container()->make(AppenderFactory::class);

        self::assertNotSame($first, $second);
    }
}
