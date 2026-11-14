<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Note;
use App\Policies\Sm\NotePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * The notes log (§A1.7, spec 02 §7).
 *
 * APPEND-ONLY, and the enforcement is the ABSENCE of things rather than a check: no update
 * route, no destroy route, no `update`/`delete` ability on `NotePolicy`, and a store-only
 * Form Request. "A corrected note is a new note. This is the bias-mitigation trail D4 rests
 * on, and a dissent its author can quietly edit is not a dissent trail."
 */
class NotesAppendOnlyTest extends SmTestCase
{
    #[Test]
    public function the_four_kinds_are_frozen_and_a_fifth_is_refused(): void
    {
        // §A1.7: `kind` is string(20), not a DB enum — ADR 0026 §2's rule that a fifth kind
        // is then a CODE change, not an ALTER TABLE. The four are frozen all the same.
        $this->assertSame(['assumption', 'dissent', 'challenge', 'workshop_minute'], Note::KINDS);

        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $scenario), ['kind' => 'observation', 'body' => 'Something worth recording.'])
            ->assertSessionHasErrors(['kind' => 'Choose what kind of note this is.']);

        $this->assertSame(0, $scenario->notes()->count());
    }

    #[Test]
    #[DataProvider('kindProvider')]
    public function each_of_the_four_kinds_can_be_appended(string $kind): void
    {
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $scenario), ['kind' => $kind, 'body' => "A {$kind} worth recording."])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Note added.');

        $note = $scenario->notes()->sole();

        $this->assertSame($kind, $note->kind);
        $this->assertSame("A {$kind} worth recording.", $note->body);
    }

    /** @return list<array{0: string}> */
    public static function kindProvider(): array
    {
        return array_map(fn (string $kind) => [$kind], Note::KINDS);
    }

    #[Test]
    public function the_author_is_a_snapshot_of_the_authenticated_user_and_not_a_posted_field(): void
    {
        // Spec 02 §7.1: "Identity is not a field. The author is the authenticated user and the
        // timestamp is the server's. A note whose author can be typed is not evidence."
        // §A1.7 adds why the NAME is snapshotted: "an anonymous dissent cannot be followed up,
        // which is worse than none."
        $scenario = $this->makeScenario();
        $someoneElse = $this->userWith(['scenario.note'], 'imposter@example.test');

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $scenario), [
                'kind' => 'dissent',
                'body' => 'I disagree with the replacement timeline.',
                // Attempted spoofing: neither key has a rule, so neither reaches validated().
                'author_id' => $someoneElse->id,
                'author_name' => 'Somebody Else',
            ])
            ->assertSessionHasNoErrors();

        $note = $scenario->notes()->sole();

        $this->assertSame($this->actor->id, $note->author_id);
        $this->assertSame($this->actor->name, $note->author_name);
        $this->assertNotSame('Somebody Else', $note->author_name);
    }

    #[Test]
    public function an_empty_or_overlong_body_is_refused_with_spec_02s_messages(): void
    {
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $scenario), ['kind' => 'assumption', 'body' => ''])
            ->assertSessionHasErrors(['body' => 'Write the note before adding it.']);

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $scenario), ['kind' => 'assumption', 'body' => str_repeat('a', 2001)])
            ->assertSessionHasErrors(['body' => 'Keep a note to 2 000 characters or fewer.']);

        $this->assertSame(0, $scenario->notes()->count());
    }

    #[Test]
    public function there_is_no_update_route_and_no_destroy_route(): void
    {
        // The absence IS the enforcement. A route nobody registered cannot be called, and a
        // PUT or DELETE against the notes path falls through to the `{scenario}` wildcard or
        // 405s — either way nothing reaches a write.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('scenario.notes.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('scenario.notes.destroy'));

        $scenario = $this->makeScenario();
        $note = $this->appendNote($scenario, 'assumption', 'Two analysts leave per year.');

        // 404, not 405: nothing under `{scenario}/notes/{note}` is registered for ANY verb, so
        // the router has no route to reject the method against. A 405 would mean an update route
        // exists and merely dislikes PUT — the opposite of what §A1.7 requires.
        $this->actingAs($this->actor)->put("/risk/scenarios/{$scenario->id}/notes/{$note->id}", ['body' => 'Rewritten'])->assertNotFound();
        $this->actingAs($this->actor)->delete("/risk/scenarios/{$scenario->id}/notes/{$note->id}")->assertNotFound();
        $this->actingAs($this->actor)->patch("/risk/scenarios/{$scenario->id}/notes/{$note->id}", ['body' => 'Rewritten'])->assertNotFound();

        $this->assertSame('Two analysts leave per year.', $note->fresh()->body);
    }

    #[Test]
    public function the_policy_exposes_no_update_or_delete_ability_at_all(): void
    {
        // Belt to the routes' braces: even a future controller that asked would get a denial
        // rather than a silent true, because the method does not exist.
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(NotePolicy::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $this->assertNotContains('update', $methods);
        $this->assertNotContains('delete', $methods);
        $this->assertNotContains('forceDelete', $methods);
        $this->assertContains('create', $methods);
        $this->assertContains('view', $methods);

        // And a correction is a NEW note, not an edit of the old one.
        $scenario = $this->makeScenario();
        $this->appendNote($scenario, 'assumption', 'We assume two analysts leave per year.');
        $this->appendNote($scenario, 'challenge', 'Correction: HR data shows four, not two.');

        $this->assertSame(2, $scenario->notes()->count());
        $this->assertSame('We assume two analysts leave per year.', $scenario->notes()->orderBy('id')->first()->body);
    }

    #[Test]
    public function notes_stay_open_in_every_status_except_archived(): void
    {
        // Spec 01 §5.5: notes are allowed in `signed_off` — "a note is commentary, not a
        // change" — and the one place they close is `archived`, where the record is out of
        // circulation and a comment nobody will read is worse than no comment box.
        foreach (['draft', 'in_design', 'designed', 'in_validation', 'validated', 'signed_off'] as $status) {
            $scenario = $this->makeScenario(['status' => $status]);

            $this->actingAs($this->actor)
                ->post(route('scenario.notes.store', $scenario), ['kind' => 'assumption', 'body' => "Noted while {$status}."])
                ->assertSessionHasNoErrors();

            $this->assertSame(1, $scenario->notes()->count(), "notes must be addable while {$status}");
        }

        $archived = $this->makeScenario(['status' => 'archived']);

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $archived), ['kind' => 'assumption', 'body' => 'Noted while archived.'])
            ->assertSessionHas('error');

        $this->assertSame(0, $archived->notes()->count());
    }

    #[Test]
    public function the_note_permission_is_separate_from_the_update_permission(): void
    {
        // §A1.10's whole argument: editing a scenario is first-line work, recording a dissent
        // against somebody else's is second-line work, and one permission for both lets the
        // designer write the dissent log the trail exists to keep. Per §A1.10's grants
        // risk-analyst, risk-owner and compliance-officer hold view + note ONLY, so a risk
        // owner can open a scenario they cannot edit and dissent against it.
        $analyst = $this->userWith(['scenario.view', 'scenario.note'], 'analyst@example.test');
        $scenario = $this->makeScenario(['status' => 'in_design', 'background' => 'Original']);

        $this->actingAs($analyst)
            ->post(route('scenario.notes.store', $scenario), ['kind' => 'dissent', 'body' => 'The replacement timeline is optimistic.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $scenario->notes()->count());

        // …and the same analyst cannot edit the scenario.
        $this->actingAs($analyst)
            ->put(route('scenario.update', $scenario), ['background' => 'Rewritten by the analyst'])
            ->assertForbidden();

        $this->assertSame('Original', $scenario->fresh()->background);
    }

    #[Test]
    public function the_payload_caps_notes_at_twenty_and_reports_the_total(): void
    {
        // Spec 01 §4.4: the latest twenty in descending order, with `notesMeta.total`, and
        // nothing auto-loads — "a scenario workshopped over a year can carry hundreds of
        // notes and the panel is above the fold".
        $scenario = $this->makeScenario();

        foreach (range(1, 25) as $n) {
            $this->appendNote($scenario, 'assumption', "Note {$n}");
        }

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page
                ->where('notesMeta', ['shown' => 20, 'total' => 25])
                ->where('notes', function ($notes) {
                    $this->assertCount(20, $notes);
                    // Descending: the newest first, so a workshop's last decision is at the top.
                    $this->assertSame('Note 25', $notes[0]['body']);
                    $this->assertSame('Note 6', $notes[19]['body']);
                    // `kind`, not `type` — §A1.7 froze the column spelling and addendum-4 says
                    // the presenter does no renaming.
                    $this->assertSame('Assumption', $notes[0]['kind_label']);
                    $this->assertArrayHasKey('kind', $notes[0]);
                    $this->assertArrayNotHasKey('type', $notes[0]);

                    return true;
                }));
    }

    #[Test]
    public function the_note_kinds_prop_carries_the_hints_that_make_a_note_useful(): void
    {
        // Spec 02 §7.1: "The hints are the point — they are what makes a risk officer write a
        // useful note instead of 'discussed'."
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('noteKinds', function ($kinds) {
                $this->assertSame(['assumption', 'dissent', 'challenge', 'workshop_minute'], collect($kinds)->pluck('value')->all());

                foreach ($kinds as $kind) {
                    $this->assertNotSame('', $kind['label']);
                    $this->assertNotSame('', $kind['hint'], 'each kind needs the hint that tells a user what it is for');
                }

                $this->assertStringContainsString('probably not argued about', collect($kinds)->firstWhere('value', 'dissent')['hint']);

                return true;
            }));
    }

    #[Test]
    public function a_note_written_by_a_lifecycle_action_is_attributable_like_a_humans(): void
    {
        // Spec 02 §7.2: "the system's own entries are indistinguishable in format from a
        // human's and equally attributable" — which is why the log is trustworthy. A
        // return-for-rework reason lands here as a `challenge` with the acting user as author.
        $scenario = $this->makeScenario(['status' => 'in_validation']);

        $this->actingAs($this->actor)->post(route('scenario.status', $scenario), [
            'to' => 'returned_for_rework',
            'reason' => 'The allocation no longer matches the reorganisation.',
        ])->assertSessionHasNoErrors();

        $note = $scenario->notes()->sole();

        $this->assertSame('challenge', $note->kind);
        $this->assertSame($this->actor->id, $note->author_id);
        $this->assertSame($this->actor->name, $note->author_name);
    }

    private function appendNote(\App\Models\Sm\Scenario $scenario, string $kind, string $body): Note
    {
        return $scenario->notes()->create([
            'organization_id' => $scenario->organization_id,
            'kind' => $kind,
            'author_id' => $this->actor->id,
            'author_name' => $this->actor->name,
            'body' => $body,
        ]);
    }
}
