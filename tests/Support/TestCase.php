<?php

declare(strict_types=1);

namespace XlsxFastAppender\Tests\Support;

/**
 * Base test case with temp-file and ZipArchive lifecycle handling.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use InteractsWithTempFiles;

    protected function tearDown(): void
    {
        $this->cleanupTempFiles();

        parent::tearDown();
    }
}
