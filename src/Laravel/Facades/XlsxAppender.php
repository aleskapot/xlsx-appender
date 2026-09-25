<?php

declare(strict_types=1);

namespace XlsxFastAppender\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use XlsxFastAppender\Laravel\AppenderFactory;

/**
 * Facade backed by AppenderFactory (§3.3 of the specification):
 *
 *     XlsxAppender::append($path, $records, $overrides);
 *
 * Import alias for applications:
 *
 *     use XlsxFastAppender\Laravel\Facades\XlsxAppender;
 *
 * @method static int append(string $path, iterable<mixed> $records, array<string, mixed> $overrides = [])
 * @method static \XlsxFastAppender\XlsxAppender make(string $path, array<string, mixed> $overrides = [])
 *
 * @see AppenderFactory
 */
final class XlsxAppender extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AppenderFactory::class;
    }
}
