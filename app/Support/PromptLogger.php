<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Appends AI-assisted instruction sequences, development prompts and other
 * significant build input to storage/logs/prompt.log.
 *
 * Writes use FILE_APPEND | LOCK_EX so concurrent processes cannot interleave
 * partial lines. Nothing in here is allowed to throw: a logging utility must
 * never take down the caller.
 *
 * Behaviour is tuned through config/prompt-log.php.
 */
class PromptLogger
{
    /**
     * Append a single prompt entry to the log.
     */
    public static function log(string $prompt): void
    {
        try {
            $entry = static::format($prompt);

            if ($entry === null) {
                return;
            }

            $path = static::path();

            File::ensureDirectoryExists(dirname($path));

            static::rotateIfNeeded($path);

            file_put_contents($path, $entry.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Swallowed on purpose: prompt logging is diagnostic, never fatal.
        }
    }

    /**
     * Absolute path of the active log file.
     */
    public static function path(): string
    {
        return storage_path(static::directory().'/'.static::filename());
    }

    public static function directory(): string
    {
        return (string) config('prompt-log.directory', 'logs');
    }

    public static function filename(): string
    {
        return (string) config('prompt-log.filename', 'prompt.log');
    }

    /**
     * Build the "[timestamp] summary" line, or null when there is nothing
     * worth recording.
     */
    public static function format(string $prompt, ?string $timestamp = null): ?string
    {
        $summary = static::summarize($prompt);

        if ($summary === null) {
            return null;
        }

        $timestamp ??= now()->format(static::timestampFormat());

        return '['.$timestamp.'] '.$summary;
    }

    /**
     * Every line of the current log, oldest first.
     *
     * @return array<int, string>
     */
    public static function entries(): array
    {
        $path = static::path();

        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(
            explode(PHP_EOL, File::get($path)),
            fn (string $line) => trim($line) !== '',
        ));
    }

    protected static function timestampFormat(): string
    {
        return (string) config('prompt-log.timestamp_format', 'Y-m-d H:i:s');
    }

    protected static function maxBytes(): int
    {
        return (int) config('prompt-log.max_bytes', 5 * 1024 * 1024);
    }

    /**
     * Collapse a multi-line prompt onto one line.
     *
     * The on-disk format is line oriented, so an embedded newline would split
     * one entry into several unparseable ones and break the guarantee that
     * each append maps to exactly one line.
     */
    protected static function summarize(string $prompt): ?string
    {
        $flattened = preg_replace('/\s+/u', ' ', trim($prompt)) ?? '';

        return $flattened === '' ? null : $flattened;
    }

    /**
     * Move an oversized log aside so the next write starts a fresh file.
     */
    protected static function rotateIfNeeded(string $path): void
    {
        $limit = static::maxBytes();

        if ($limit <= 0 || ! is_file($path)) {
            return;
        }

        clearstatcache(true, $path);

        if (filesize($path) < $limit) {
            return;
        }

        File::move($path, static::rotatedPath($path));
    }

    /**
     * Destination for a rotated log.
     *
     * The rotated name deliberately keeps a .log suffix so the project's
     * "*.log" ignore rule covers rotated files too.
     */
    protected static function rotatedPath(string $path): string
    {
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return dirname($path).'/'.$stem.'-'.now()->format('Ymd-His').($extension ? '.'.$extension : '');
    }
}
