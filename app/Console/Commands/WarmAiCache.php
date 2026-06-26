<?php

namespace App\Console\Commands;

use App\Http\Controllers\Risk\AiToolsController;
use App\Models\Risk;
use App\Services\LlmService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Pre-generate and cache AI tool responses for the demo click-path.
 *
 * Each of the heavy AI tools (Control Recommender, KRI Suggester, Executive
 * Narrative) takes 30-80 seconds on granite4:micro on CPU. That is fine for
 * the first hit but too slow to perform live in front of a client.
 *
 * Run this once before the demo and the first 5 Critical/High residual risks
 * will have instant responses when the presenter clicks the AI buttons.
 */
class WarmAiCache extends Command
{
    protected $signature = 'ai:warm-cache {--org=1}';
    protected $description = 'Pre-generate AI responses for top residual risks so the live demo is instant.';

    public function handle(LlmService $llm): int
    {
        if (! $llm->available()) {
            $this->error('LLM unavailable: ' . ($llm->lastError() ?? 'unknown'));
            return self::FAILURE;
        }

        $orgId = (int) $this->option('org');
        $ctrl = new AiToolsController($llm);

        // Warm the model first
        $this->info('Warming model …');
        $llm->complete('ok', '', ['max_tokens' => 1, 'timeout' => 120]);

        // Get top 5 critical/high residual risks
        $topRisks = Risk::where('organization_id', $orgId)
            ->whereIn('residual_rating', ['Critical', 'High'])
            ->orderByDesc('residual_score')
            ->limit(5)
            ->get();

        $this->info("Warming AI cache for {$topRisks->count()} top residual risks in org {$orgId} …");
        $this->newLine();

        foreach ($topRisks as $risk) {
            $this->info("• {$risk->risk_code} — {$risk->title}");

            $this->line('    Control Recommender …');
            $t = microtime(true);
            $req = new Request(['risk_id' => $risk->id]);
            $ctrl->controlRecommendations($req);
            $this->line('      ' . $this->elapsed($t));

            $this->line('    KRI Suggester …');
            $t = microtime(true);
            $req = new Request(['risk_id' => $risk->id]);
            $ctrl->kriSuggestions($req);
            $this->line('      ' . $this->elapsed($t));
        }

        $this->newLine();
        $this->info('Executive Narrative …');
        auth()->loginUsingId(\App\Models\User::where('organization_id', $orgId)->first()->id);
        $t = microtime(true);
        $ctrl->executiveNarrative(new Request());
        $this->line('  ' . $this->elapsed($t));

        $this->newLine();
        $this->info('AI cache warmed. Demo clicks on these risks will now be instant.');
        return self::SUCCESS;
    }

    private function elapsed(float $t): string
    {
        $ms = (int) round((microtime(true) - $t) * 1000);
        return "done in {$ms} ms";
    }
}
