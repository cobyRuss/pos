<?php

namespace App\Console\Commands;

use App\Support\PromptLogger;
use Illuminate\Console\Command;

class LogPromptCommand extends Command
{
    protected $signature = 'prompt:log
                            {prompt : The instruction or prompt text to record}
                            {--quiet-entry : Suppress the confirmation output}';

    protected $description = 'Append an AI-assisted instruction or development prompt to storage/logs/prompt.log';

    public function handle(): int
    {
        $prompt = (string) $this->argument('prompt');

        PromptLogger::log($prompt);

        if (! $this->option('quiet-entry')) {
            $this->components->info('Prompt logged to '.PromptLogger::path());
        }

        return self::SUCCESS;
    }
}
