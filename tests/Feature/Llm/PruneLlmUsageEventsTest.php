<?php

namespace Tests\Feature\Llm;

use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * phase-11a-ai-contract.md §7.4 — `llm:prune-usage`, and a check that a
 * SCHEDULED COMMAND NOTHING RUNS is this repository's most reliable source
 * of dead code (backend-engineer's own module notes). This test actually
 * invokes it.
 */
class PruneLlmUsageEventsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_rows_older_than_the_retention_window_and_keeps_the_rest(): void
    {
        config()->set('llm.retention_months', 24);

        $organization = Organization::create([
            'name' => 'Retention Bank', 'short_name' => 'RTB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        RiskCategory::create([
            'organization_id' => $organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $old = LlmUsageEvent::create([
            'organization_id' => $organization->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'duration_ms' => 500, 'usage_month' => now()->subMonths(30)->format('Y-m'),
            'created_at' => now()->subMonths(30),
        ]);

        $recent = LlmUsageEvent::create([
            'organization_id' => $organization->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'duration_ms' => 500, 'usage_month' => now()->format('Y-m'), 'created_at' => now(),
        ]);

        $this->artisan('llm:prune-usage')->assertExitCode(0);

        $this->assertDatabaseMissing('llm_usage_events', ['id' => $old->id]);
        $this->assertDatabaseHas('llm_usage_events', ['id' => $recent->id]);
    }
}
