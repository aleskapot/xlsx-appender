<?php

declare(strict_types=1);

namespace XlsxFastAppender\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use XlsxFastAppender\Exception\InvalidOptionException;
use XlsxFastAppender\XlsxAppender;

/**
 * Registers config, the container bindings and config publishing (§3.3).
 *
 * Discovered automatically on Laravel >= 5.5 through composer.json
 * `extra.laravel.providers`.
 */
final class XlsxFastAppenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::packageConfigPath(), 'xlsx-appender');

        $this->app->bind(AppenderFactory::class, function (Container $app): AppenderFactory {
            $config = $app->make(Repository::class)->get('xlsx-appender', []);

            return new AppenderFactory(self::stringKeys(\is_array($config) ? $config : []));
        });

        $this->app->bind(XlsxAppender::class, function (Container $app, array $parameters): XlsxAppender {
            $path = $parameters['path'] ?? null;

            if (!\is_string($path) || $path === '') {
                throw new InvalidOptionException(
                    'Resolving XlsxAppender from the container requires a non-empty string '
                    .'"path" parameter, e.g. app(XlsxAppender::class, ["path" => "/data/report.xlsx"]).',
                );
            }

            $overrides = $parameters['options'] ?? [];

            if (!\is_array($overrides)) {
                throw new InvalidOptionException(\sprintf(
                    'The container "options" parameter must be an array, got %s.',
                    get_debug_type($overrides),
                ));
            }

            $config = $app->make(Repository::class)->get('xlsx-appender', []);
            $merged = array_merge(\is_array($config) ? $config : [], $overrides);

            return new XlsxAppender($path, self::stringKeys($merged));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            self::packageConfigPath() => $this->app->configPath('xlsx-appender.php'),
        ], 'xlsx-appender');
    }

    private static function packageConfigPath(): string
    {
        return \dirname(__DIR__, 2).'/config/xlsx-appender.php';
    }

    /**
     * Narrows a config array to string keys (config files are string-keyed;
     * is_array() alone types keys as int|string).
     *
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $config): array
    {
        $options = [];

        foreach ($config as $key => $value) {
            if (\is_string($key)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }
}
