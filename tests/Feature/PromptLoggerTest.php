<?php

namespace Tests\Feature;

use App\Support\PromptLogger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PromptLoggerTest extends TestCase
{
    /**
     * Isolated log directory per test so the suite never touches the real
     * storage/logs tree (and its .gitignore).
     */
    protected string $logDir = 'logs/testing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = 'logs/testing-'.getmypid().'-'.spl_object_id($this);

        config()->set('prompt-log.directory', $this->logDir);
        config()->set('prompt-log.filename', 'prompt.log');
        config()->set('prompt-log.max_bytes', 5 * 1024 * 1024);

        File::deleteDirectory(storage_path($this->logDir));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path($this->logDir));

        // A test may have repointed the config at another directory.
        File::deleteDirectory(storage_path((string) config('prompt-log.directory')));

        parent::tearDown();
    }

    public function test_it_creates_the_log_directory_and_file(): void
    {
        $this->assertDirectoryDoesNotExist(storage_path($this->logDir));

        PromptLogger::log('bootstrap the log');

        $this->assertFileExists(PromptLogger::path());
    }

    public function test_it_writes_the_documented_entry_format(): void
    {
        PromptLogger::log('Format check');

        $line = trim(File::get(PromptLogger::path()));

        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] Format check$/',
            $line,
        );
    }

    public function test_it_appends_rather_than_overwrites(): void
    {
        PromptLogger::log('first');
        PromptLogger::log('second');
        PromptLogger::log('third');

        $entries = PromptLogger::entries();

        $this->assertCount(3, $entries);
        $this->assertStringEndsWith('first', $entries[0]);
        $this->assertStringEndsWith('second', $entries[1]);
        $this->assertStringEndsWith('third', $entries[2]);
    }

    public function test_it_flattens_multiline_prompts_to_one_line(): void
    {
        PromptLogger::log("first line\nsecond line\n\n  third   line  ");

        $this->assertCount(1, PromptLogger::entries());
        $this->assertStringContainsString('first line second line third line', PromptLogger::entries()[0]);
    }

    public function test_it_ignores_blank_prompts(): void
    {
        PromptLogger::log('   ');
        PromptLogger::log('');
        PromptLogger::log("\n\t  \n");

        $this->assertFileDoesNotExist(PromptLogger::path());
    }

    public function test_it_never_throws_when_the_log_cannot_be_written(): void
    {
        // Put a directory where the log file should be: the append primitive
        // fails, and logging must stay silent instead of propagating.
        File::ensureDirectoryExists(PromptLogger::path());

        PromptLogger::log('this must not throw');

        $this->assertTrue(true);
    }

    public function test_entries_returns_empty_when_no_log_exists(): void
    {
        $this->assertSame([], PromptLogger::entries());
    }

    public function test_it_rotates_the_log_once_it_passes_the_threshold(): void
    {
        config()->set('prompt-log.max_bytes', 100);

        PromptLogger::log(str_repeat('a', 150));
        PromptLogger::log(str_repeat('b', 150));

        $rotated = $this->rotatedFiles();

        $this->assertCount(1, $rotated, 'Expected exactly one rotated log file.');
        $this->assertStringEndsWith('.log', $rotated[0], 'Rotated files must keep a .log suffix to stay git-ignored.');
        $this->assertStringContainsString('a', File::get($rotated[0]));

        // The active log was restarted, so it only holds the newest entry.
        $this->assertCount(1, PromptLogger::entries());
        $this->assertStringContainsString('b', PromptLogger::entries()[0]);
    }

    public function test_rotation_can_be_disabled(): void
    {
        config()->set('prompt-log.max_bytes', 0);

        PromptLogger::log(str_repeat('a', 150));
        PromptLogger::log(str_repeat('b', 150));

        $this->assertEmpty($this->rotatedFiles());
        $this->assertCount(2, PromptLogger::entries());
    }

    public function test_no_rotation_while_the_log_stays_under_the_threshold(): void
    {
        config()->set('prompt-log.max_bytes', 10_000);

        PromptLogger::log('small one');
        PromptLogger::log('small two');

        $this->assertEmpty($this->rotatedFiles());
        $this->assertCount(2, PromptLogger::entries());
    }

    public function test_location_is_configurable(): void
    {
        config()->set('prompt-log.directory', 'logs/custom');
        config()->set('prompt-log.filename', 'ai.log');

        PromptLogger::log('relocated');

        $this->assertFileExists(storage_path('logs/custom/ai.log'));
    }

    public function test_format_returns_null_for_blank_input(): void
    {
        $this->assertNull(PromptLogger::format('   '));
        $this->assertSame('[2026-01-02 03:04:05] hello', PromptLogger::format('hello', '2026-01-02 03:04:05'));
    }

    public function test_artisan_command_records_a_prompt(): void
    {
        $this->artisan('prompt:log', ['prompt' => 'via artisan command'])
            ->assertSuccessful();

        $this->assertStringContainsString('via artisan command', File::get(PromptLogger::path()));
    }

    public function test_repeated_writes_all_produce_well_formed_lines(): void
    {
        for ($i = 1; $i <= 200; $i++) {
            PromptLogger::log("entry {$i}");
        }

        $entries = PromptLogger::entries();

        $this->assertCount(200, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression(
                '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] entry \d+$/',
                $entry,
            );
        }
    }

    /**
     * Rotated logs are named "<stem>-<timestamp>.<ext>" inside the active
     * log's directory.
     *
     * @return array<int, string>
     */
    protected function rotatedFiles(): array
    {
        $path = PromptLogger::path();

        $pattern = dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-*'
            .(pathinfo($path, PATHINFO_EXTENSION) ? '.'.pathinfo($path, PATHINFO_EXTENSION) : '');

        return glob($pattern) ?: [];
    }
}
