<?php

namespace Tests\Feature\Workflow;

use App\Models\WorkflowDefinition;
use App\Services\Workflow\WorkflowLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Phase 6 acceptance criterion 4 — the designer round trip.
 *
 * A definition saved from the React designer must equal the JSON
 * WorkflowLibrary ships, after normalisation. That is the claim the whole
 * screen rests on: whatever the designer draws is the same contract the engine
 * runs, so a process a customer edits in the browser is not a second, subtly
 * different dialect of the one the platform seeds.
 *
 * "After normalisation" is doing real work in that sentence, and it is worth
 * being precise about what it forgives:
 *
 *   - **Node position.** The library ships no x/y; the designer stamps them so
 *     a process looks the same to the next person who opens it. Position is
 *     presentation, and the engine never reads it.
 *   - **Key order.** JSON objects have none that matters here.
 *   - **Absent optional keys.** A library node that omits `allow_return` and a
 *     designer node that sends `null` for it are the same node.
 *
 * It forgives nothing else. Every code, type, name, assignee rule, SLA,
 * timeout, outcome, edge, condition and edge ORDER must survive the round trip
 * exactly — edge order especially, because an exclusive gateway takes the first
 * satisfied branch, so reordering the edges silently changes which way a
 * process goes.
 */
class DesignerRoundTripTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);
    }

    #[Test]
    public function a_shipped_definition_survives_a_round_trip_through_the_designer(): void
    {
        $shipped = WorkflowLibrary::byCode()['risk_assessment_approval'];

        // What the designer posts: the shipped graph with positions stamped on,
        // exactly as the canvas would after a user opened and dragged it.
        $posted = $shipped;
        $posted['code'] = 'round_trip';
        $posted['trigger'] = $shipped['trigger'] ?? 'manual';
        $posted['definition']['nodes'] = $this->withPositions($shipped['definition']['nodes']);

        $this->post(route('risk.workflows.create-design'), $posted)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $saved = WorkflowDefinition::withoutGlobalScopes()->where('code', 'round_trip')->firstOrFail();

        $this->assertSame(
            $this->normalise($shipped['definition']),
            $this->normalise($saved->definition),
            'A definition drawn in the designer must be the same graph the engine runs.',
        );
    }

    #[Test]
    public function every_shipped_definition_is_something_the_designer_could_have_drawn(): void
    {
        // Not just the one: if any shipped process uses a shape the designer's
        // own request would refuse, the designer cannot edit it, and a customer
        // who opens it discovers that only when they try to save.
        foreach (WorkflowLibrary::definitions() as $index => $shipped) {
            $posted = $shipped;
            $posted['code'] = 'round_trip_'.$index;
            $posted['trigger'] = $shipped['trigger'] ?? 'manual';
            $posted['definition']['nodes'] = $this->withPositions($shipped['definition']['nodes']);

            $this->post(route('risk.workflows.create-design'), $posted)
                ->assertSessionHasNoErrors();

            $saved = WorkflowDefinition::withoutGlobalScopes()->where('code', 'round_trip_'.$index)->firstOrFail();

            $this->assertSame(
                $this->normalise($shipped['definition']),
                $this->normalise($saved->definition),
                "[{$shipped['code']}] does not survive the designer.",
            );
        }
    }

    #[Test]
    public function edge_order_survives_the_round_trip(): void
    {
        // Order is meaningful, not cosmetic: an exclusive gateway takes the
        // FIRST satisfied edge, so reordering silently changes which way a
        // process goes.
        $shipped = WorkflowLibrary::byCode()['risk_assessment_approval'];

        $posted = $shipped;
        $posted['code'] = 'order_check';
        $posted['trigger'] = $shipped['trigger'] ?? 'manual';
        $posted['definition']['nodes'] = $this->withPositions($shipped['definition']['nodes']);

        $this->post(route('risk.workflows.create-design'), $posted)->assertSessionHasNoErrors();

        $saved = WorkflowDefinition::withoutGlobalScopes()->where('code', 'order_check')->firstOrFail();

        $expected = array_map(
            fn (array $edge) => ($edge['from'] ?? '').'→'.($edge['to'] ?? '').'@'.($edge['when'] ?? ''),
            $shipped['definition']['edges'],
        );
        $actual = array_map(
            fn (array $edge) => ($edge['from'] ?? '').'→'.($edge['to'] ?? '').'@'.($edge['when'] ?? ''),
            $saved->definition['edges'],
        );

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function a_validator_failure_names_the_field_it_is_about(): void
    {
        // Criterion 4's second half: errors surface with field paths, so the
        // page can put the message next to the thing that is wrong rather than
        // in a heap at the top.
        $shipped = WorkflowLibrary::byCode()['risk_assessment_approval'];

        $posted = $shipped;
        $posted['code'] = 'bad_edge';
        $posted['trigger'] = 'manual';
        $posted['definition']['nodes'] = $this->withPositions($shipped['definition']['nodes']);
        $posted['definition']['edges'][] = ['from' => 'start', 'to' => 'nowhere_at_all'];

        $lastEdge = count($posted['definition']['edges']) - 1;

        $this->postJson(route('risk.workflows.create-design'), $posted)
            ->assertStatus(422)
            ->assertJsonValidationErrors("definition.edges.{$lastEdge}.to");
    }

    /**
     * The designer stamps a position on every node; the library ships none.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function withPositions(array $nodes): array
    {
        return array_values(array_map(
            fn (array $node, int $index) => array_merge($node, [
                'x' => 40 + ($index * 240),
                'y' => 120,
            ]),
            $nodes,
            array_keys($nodes),
        ));
    }

    /**
     * The graph with presentation removed, so two graphs can be compared for
     * what the engine actually reads.
     *
     * @param  array<string, mixed>  $definition
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function normalise(array $definition): array
    {
        $node = function (array $node): array {
            unset($node['x'], $node['y']);

            // An absent optional key and an explicit null are the same node.
            $node = array_filter($node, fn ($value) => $value !== null);
            ksort($node);

            return $node;
        };

        $edge = function (array $edge): array {
            $edge = array_filter($edge, fn ($value) => $value !== null);
            ksort($edge);

            return $edge;
        };

        return [
            'nodes' => array_values(array_map($node, $definition['nodes'] ?? [])),
            'edges' => array_values(array_map($edge, $definition['edges'] ?? [])),
        ];
    }
}
