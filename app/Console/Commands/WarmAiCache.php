<?php

namespace App\Console\Commands;

use App\Http\Controllers\Risk\AiToolsController;
use App\Http\Requests\Ai\SuggestControlsRequest;
use App\Http\Requests\Ai\SuggestKrisRequest;
use App\Models\Risk;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;

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
            $this->error('LLM unavailable: '.($llm->lastError() ?? 'unknown'));

            return self::FAILURE;
        }

        $orgId = (int) $this->option('org');
        $ctrl = new AiToolsController($llm);

        // Sign in BEFORE the loop, not just before the narrative as this used
        // to. The AI endpoints now take Form Requests whose authorize() asks
        // for ai.use, and warming a cache by making the same calls a user
        // would should run as somebody entitled to make them.
        $actor = User::query()
            ->where('organization_id', $orgId)
            ->get()
            ->first(fn (User $user) => $user->can('ai.use'));

        if ($actor === null) {
            $this->error("No user in organization {$orgId} holds ai.use, so there is nobody these calls could be made as.");

            return self::FAILURE;
        }

        auth()->login($actor);

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
            $ctrl->controlRecommendations(
                $this->formRequest(SuggestControlsRequest::class, ['risk_id' => $risk->id])
            );
            $this->line('      '.$this->elapsed($t));

            $this->line('    KRI Suggester …');
            $t = microtime(true);
            $ctrl->kriSuggestions(
                $this->formRequest(SuggestKrisRequest::class, ['risk_id' => $risk->id])
            );
            $this->line('      '.$this->elapsed($t));
        }

        $this->newLine();
        $this->info('Executive Narrative …');
        $t = microtime(true);
        $ctrl->executiveNarrative(new Request);
        $this->line('  '.$this->elapsed($t));

        $this->newLine();
        $this->info('AI cache warmed. Demo clicks on these risks will now be instant.');

        return self::SUCCESS;
    }

    /**
     * Build a Form Request the way the HTTP kernel would, so this command
     * exercises the same authorisation and the same rules a real click does.
     *
     * Calling a controller from a console command is a smell — the drafting
     * logic belongs in a service both could use — but that extraction is a
     * behaviour change and this is a demo tool. Constructing the request
     * properly is the honest version of what it was already doing with a bare
     * Request.
     *
     * @template TRequest of FormRequest
     *
     * @param  class-string<TRequest>  $class
     * @param  array<string, mixed>  $payload
     * @return TRequest
     */
    private function formRequest(string $class, array $payload): FormRequest
    {
        /** @var TRequest $request */
        $request = $class::create('/', 'POST', $payload);

        $request->setContainer(app())
            ->setRedirector(app(Redirector::class))
            ->setUserResolver(fn () => auth()->user());

        $request->validateResolved();

        return $request;
    }

    private function elapsed(float $t): string
    {
        $ms = (int) round((microtime(true) - $t) * 1000);

        return "done in {$ms} ms";
    }
}
