<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Laravel;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use XlsxFastAppender\Laravel\AppenderFactory;
use XlsxFastAppender\Laravel\Facades\XlsxAppender;
use XlsxFastAppender\Tests\Fixtures\Report;
use XlsxFastAppender\Tests\Support\XlsxFixtureFactory;

/**
 * Facade behaviour: config-backed appends, per-call overrides and the
 * iterable flavours from the stage-5 exit criteria (Eloquent collections,
 * lazy streams, chunked batches).
 */
#[CoversClass(XlsxAppender::class)]
#[CoversClass(AppenderFactory::class)]
final class FacadeTest extends LaravelTestCase
{
    private const SHEET_ENTRY = 'xl/worksheets/sheet1.xml';

    public function testFacadeAppendsWithConfiguredDefaults(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $written = XlsxAppender::append($path, [['alpha'], ['beta']]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="2">', $sheet);
        self::assertStringContainsString('<row r="3">', $sheet);
        self::assertStringContainsString('<t>alpha</t>', $sheet);
    }

    public function testFacadeMakeBuildsAnAppenderFromConfig(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $appender = XlsxAppender::make($path, ['start_cell' => 'A5']);

        self::assertSame(1, $appender->append([['deep']]));

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('<row r="5">', $sheet);
    }

    public function testFacadePerCallOverridesSelectAnotherSheet(): void
    {
        $path = $this->fixture([
            'Data' => XlsxFixtureFactory::emptyWorksheet(),
            'Other' => XlsxFixtureFactory::emptyWorksheet(),
        ]);

        $written = XlsxAppender::append($path, [['targeted']], ['sheet' => 'Other']);

        self::assertSame(1, $written);

        $data = $this->readEntry($path, self::SHEET_ENTRY);
        $other = $this->readEntry($path, 'xl/worksheets/sheet2.xml');

        self::assertStringContainsString('targeted', $other);
        self::assertStringNotContainsString('<row', $data);
    }

    public function testEloquentCollectionRecordsAreMappedByColumnKeys(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $records = new EloquentCollection([
            new Report(['name' => 'Ada', 'amount' => 10]),
            new Report(['name' => 'Grace', 'amount' => 20]),
        ]);

        $written = XlsxAppender::append($path, $records, [
            'columns' => ['name' => 'Name', 'amount' => 'Amount'],
            'write_header' => true,
        ]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('Name', $sheet);
        self::assertStringContainsString('Ada', $sheet);
        self::assertStringContainsString('Grace', $sheet);
        self::assertStringContainsString('<row r="3">', $sheet);
    }

    public function testLazyCollectionOfModelsIsStreamedIntoTheSheet(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $records = LazyCollection::make(static function (): \Generator {
            yield new Report(['name' => 'lazy-1', 'amount' => 1]);
            yield new Report(['name' => 'lazy-2', 'amount' => 2]);
        });

        $written = XlsxAppender::append($path, $records, [
            'columns' => ['name' => 'Name', 'amount' => 'Amount'],
        ]);

        self::assertSame(2, $written);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertStringContainsString('lazy-1', $sheet);
        self::assertStringContainsString('lazy-2', $sheet);
    }

    public function testChunkedBatchesAppendSequentially(): void
    {
        $path = $this->fixture(['Data' => XlsxFixtureFactory::emptyWorksheet()]);

        $records = new EloquentCollection([
            new Report(['name' => 'one', 'amount' => 1]),
            new Report(['name' => 'two', 'amount' => 2]),
            new Report(['name' => 'three', 'amount' => 3]),
            new Report(['name' => 'four', 'amount' => 4]),
        ]);

        $results = [];
        $offset = 0;

        foreach ($records->chunk(2) as $chunk) {
            $results[] = XlsxAppender::append($path, $chunk, [
                'columns' => ['name' => 'Name', 'amount' => 'Amount'],
                'start_cell' => 'A'.(2 + $offset * 2),
            ]);

            $offset++;
        }

        self::assertSame([2, 2], $results);

        $sheet = $this->readEntry($path, self::SHEET_ENTRY);
        self::assertSame(4, preg_match_all('/<row\b/', $sheet));
        self::assertStringContainsString('<row r="5">', $sheet);
        self::assertStringContainsString('one', $sheet);
        self::assertStringContainsString('four', $sheet);
    }
}
