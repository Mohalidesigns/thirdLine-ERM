<?php

namespace App\Console\Commands;

use App\Services\LlmService;
use Illuminate\Console\Command;

class WarmLlm extends Command
{
    protected $signature = 'llm:warm';

    protected $description = 'Pre-load the local LLM into memory so the first demo call is fast.';

    public function handle(LlmService $llm): int
    {
        if (! $llm->available()) {
            $this->error('LLM not available: '.($llm->lastError() ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info('Warming '.config('services.llm.model').' at '.config('services.llm.endpoint').' …');
        $t0 = microtime(true);
        $llm->complete('ok', '', ['max_tokens' => 1, 'timeout' => 120, 'keep_alive' => '60m']);
        $elapsed = round((microtime(true) - $t0) * 1000);
        $this->info("Warm in {$elapsed} ms. Model is now resident and will stay hot for 60 minutes.");

        return self::SUCCESS;
    }
}
