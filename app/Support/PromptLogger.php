<?php

namespace App\Support;

use DateTimeImmutable;
use Throwable;

/**
 * Append-only logger for development prompts / instructions.
 *
 * Every time this application (or any AI-assisted tooling) processes a
 * development prompt or instruction, call PromptLogger::log() with the
 * prompt text or a concise summary of it.
 *
 * Writes are ALWAYS appended - existing content is never overwritten.
 */
class PromptLogger
{
    /**
     * Absolute path of the log file.
     */
    public static function path(): string
    {
        return storage_path('logs'.DIRECTORY_SEPARATOR.'prompt.log');
    }

    /**
     * Append a single prompt entry to storage/logs/prompt.log.
     *
     * Line format: [YYYY-MM-DD HH:MM:SS] <prompt text/summary>
     */
    public static function log(string $prompt): void
    {
        $line = self::format($prompt);

        try {
            self::ensureDirectoryExists();

            // LOCK_EX keeps concurrent writers from interleaving partial lines.
            $written = @file_put_contents(
                self::path(),
                $line,
                FILE_APPEND | LOCK_EX
            );

            if ($written === false) {
                self::writeFallback($line);
            }
        } catch (Throwable) {
            // Logging must never break the application.
            self::writeFallback($line);
        }
    }

    /**
     * Build the formatted, newline-terminated log line.
     */
    public static function format(string $prompt, ?string $timestamp = null): string
    {
        $timestamp ??= (new DateTimeImmutable)->format('Y-m-d H:i:s');

        $prompt = self::normalize($prompt);

        return sprintf('[%s] %s%s', $timestamp, $prompt, PHP_EOL);
    }

    /**
     * Collapse whitespace and strip control characters so one prompt is one line.
     */
    protected static function normalize(string $prompt): string
    {
        $prompt = str_replace(["\r\n", "\r", "\n", "\t", "\0"], ' ', $prompt);
        $prompt = (string) preg_replace('/\s+/u', ' ', $prompt);

        return trim($prompt);
    }

    /**
     * Make sure storage/logs exists and is writable.
     */
    protected static function ensureDirectoryExists(): void
    {
        $dir = dirname(self::path());

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (! is_writable($dir)) {
            throw new \RuntimeException(sprintf('Directory [%s] is not writable.', $dir));
        }

        $path = self::path();

        if (! file_exists($path)) {
            // Touch creates an empty file; it never truncates an existing one.
            @touch($path);
        }
    }

    /**
     * Last-resort append straight to the file handle, then to the error log.
     */
    protected static function writeFallback(string $line): void
    {
        $path = self::path();

        if (@fopen($path, 'a') !== false) {
            @file_put_contents($path, $line, FILE_APPEND);
        }

        @error_log('PromptLogger could not append to prompt.log: '.trim($line));
    }
}
