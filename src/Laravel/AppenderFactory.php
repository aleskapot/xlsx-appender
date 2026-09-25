<?php

declare(strict_types=1);

namespace XlsxFastAppender\Laravel;

use XlsxFastAppender\XlsxAppender;

/**
 * Container-facing factory backing the facade (§3.3 of the specification).
 *
 * A new appender is built for every call so configuration changes between
 * calls are picked up; the factory itself is bound as a non-singleton.
 */
final class AppenderFactory
{
    /**
     * @param array<string, mixed> $config resolved `xlsx-appender` config entries
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param array<string, mixed> $overrides per-call option overrides
     */
    public function make(string $path, array $overrides = []): XlsxAppender
    {
        return new XlsxAppender($path, array_merge($this->config, $overrides));
    }

    /**
     * @param iterable<mixed>         $records
     * @param array<string, mixed>    $overrides per-call option overrides
     *
     * @throws \Throwable whatever `XlsxAppender::append()` throws
     */
    public function append(string $path, iterable $records, array $overrides = []): int
    {
        return $this->make($path, $overrides)->append($records);
    }
}
