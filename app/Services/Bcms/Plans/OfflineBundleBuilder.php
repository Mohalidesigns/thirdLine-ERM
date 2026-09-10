<?php

namespace App\Services\Bcms\Plans;

use App\Enums\Bcms\PlanSectionSource;
use App\Models\Bcms\Plan;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * The offline bundle — the plan, the tree, the contacts and the sites, as one
 * JSON document a phone can hold.
 *
 * BLUEPRINT §1.2: THE NETWORK IS THE FIRST THING TO FAIL. A continuity platform
 * that is unreachable during the disruption it exists for is a filing cabinet
 * with a login page. The bundle is what the PWA caches, and what makes the
 * plan available when the data centre it lives in is the thing that is down.
 *
 * IT IS ASSEMBLED FROM THE SAME `document()` THE PDF USES. Three renderings of
 * one plan — screen, paper, phone — that could disagree is the failure this
 * design is arranged to prevent, and the way that happens is three call sites
 * building the document three ways.
 *
 * IT CARRIES PERSONAL DATA AND SAYS SO. Call trees and contact blocks are
 * mobile numbers, and the whole point is that they leave the platform. The
 * bundle therefore records what personal data it contains and when it was
 * generated, so an NDPA answer to "what is on that phone" is a fact rather than
 * an estimate — and `bcms.contact.export`, not `bcms.plan.view`, is what gates
 * generating one.
 *
 * THE PWA SERVICE WORKER IS PHASE 12. This produces and validates the bundle;
 * end-to-end "airplane mode on a phone" is verified at the W14 integration
 * window, and saying so here is more useful than implying it already works.
 */
class OfflineBundleBuilder
{
    /** Bumped when the bundle's shape changes, so an old cached bundle is recognisable. */
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly PlanAssembler $assembler) {}

    /**
     * Build the bundle for a plan.
     *
     * @return array<string, mixed>
     */
    public function build(Plan $plan): array
    {
        if ($plan->status !== 'approved') {
            throw new InvalidArgumentException(
                'Only an approved plan is bundled for offline use. Caching a draft on people\'s phones puts an '
                .'unapproved document in their hands during an event, which is the one moment they cannot check.'
            );
        }

        $sections = $this->assembler->document($plan);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'plan' => [
                'uuid' => $plan->uuid,
                'title' => $plan->title,
                'plan_type' => $plan->plan_type->value,
                'plan_type_label' => $plan->plan_type->label(),
                'version' => $plan->version,
                'status' => $plan->status,
                'effective_from' => $plan->effective_from?->toDateString(),
                'next_review_date' => $plan->next_review_date?->toDateString(),
                'approved_at' => $plan->approved_at?->toIso8601String(),
                'iso_clause_ref' => $plan->iso_clause_ref,
            ],
            'sections' => array_map(fn (array $s) => [
                'key' => $s['key'],
                'title' => $s['title'],
                'body' => $s['body'],
                'sort_order' => $s['sort_order'],
                'source' => $s['binding']['source'] ?? null,
                'last_verified_at' => $s['last_verified_at'],
                'rows' => $s['live']['rows'] ?? [],
                'notes' => $s['live']['notes'] ?? [],
                'empty_reason' => $s['live']['empty_reason'] ?? null,
            ], $sections),
            // Pulled out of the sections as well as left in them. During an
            // event nobody scrolls a document looking for the call tree; the
            // three things somebody needs in the first ten minutes are indexed
            // at the top of the bundle.
            'call_tree' => $this->extract($sections, PlanSectionSource::CallTree),
            'contacts' => $this->extract($sections, PlanSectionSource::CrisisTeamContacts),
            'sites' => $this->extract($sections, PlanSectionSource::AssemblyPoints),
            'contains_personal_data' => $this->containsPersonalData($sections),
        ];
    }

    /**
     * Build, store and stamp the plan.
     *
     * The path is stamped on the plan so a later request can tell whether a
     * bundle exists and how old it is. `offline_bundle_generated_at` is what
     * the dashboard reads to say "this plan has never been made available
     * offline", which is a real gap and not a cosmetic one.
     */
    public function generate(Plan $plan, ?int $userId = null): array
    {
        $bundle = $this->build($plan);
        $path = 'bcms/offline/'.$plan->organization_id.'/plan-'.$plan->uuid.'-v'.$plan->version.'.json';

        Storage::disk($this->disk())->put($path, (string) json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $plan->forceFill([
            'offline_bundle_generated_at' => now(),
            'offline_bundle_path' => $path,
            'updated_by' => $userId ?? auth()->id(),
        ])->save();

        return $bundle;
    }

    /**
     * Validate a bundle against the shape the offline viewer expects.
     *
     * A REAL CHECK, NOT A SCHEMA FILE. Acceptance criterion 5 asks that the
     * bundle "validates against the bundle schema"; a JSON Schema document
     * would need a validator dependency and would still not catch the thing
     * that actually breaks the viewer, which is a section with no `rows` key
     * because a resolver returned early. This asserts what the viewer reads.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<string> the problems found; empty means valid
     */
    public function validate(array $bundle): array
    {
        $problems = [];

        foreach (['schema_version', 'generated_at', 'plan', 'sections', 'contains_personal_data'] as $key) {
            if (! array_key_exists($key, $bundle)) {
                $problems[] = "The bundle is missing \"{$key}\".";
            }
        }

        if (($bundle['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $problems[] = 'The bundle was built for schema version '.json_encode($bundle['schema_version'] ?? null)
                .'; this viewer expects '.self::SCHEMA_VERSION.'.';
        }

        foreach (['uuid', 'title', 'version', 'plan_type'] as $key) {
            if (blank($bundle['plan'][$key] ?? null)) {
                $problems[] = "The bundle's plan block is missing \"{$key}\".";
            }
        }

        $sections = $bundle['sections'] ?? null;

        if (! is_array($sections)) {
            $problems[] = 'The bundle has no sections array.';

            return $problems;
        }

        if ($sections === []) {
            $problems[] = 'The bundle has no sections. A plan with nothing in it is not usable offline.';
        }

        foreach ($sections as $index => $section) {
            foreach (['key', 'title', 'rows', 'notes'] as $key) {
                if (! array_key_exists($key, is_array($section) ? $section : [])) {
                    $problems[] = "Section {$index} is missing \"{$key}\".";
                }
            }

            if (isset($section['rows']) && ! is_array($section['rows'])) {
                $problems[] = "Section {$index}'s rows are not an array.";
            }
        }

        return $problems;
    }

    /**
     * The stored bundle for a plan, or null if none has been generated.
     *
     * @return array<string, mixed>|null
     */
    public function stored(Plan $plan): ?array
    {
        if ($plan->offline_bundle_path === null) {
            return null;
        }

        $disk = Storage::disk($this->disk());

        if (! $disk->exists($plan->offline_bundle_path)) {
            // The stamp says a bundle exists and the file does not. Say so
            // rather than returning null, which the caller would read as "never
            // generated" and would not investigate.
            throw new RuntimeException(
                'This plan is stamped with an offline bundle at "'.$plan->offline_bundle_path.'" but the file is '
                .'not there. Regenerate it.'
            );
        }

        $decoded = json_decode((string) $disk->get($plan->offline_bundle_path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>|null
     */
    private function extract(array $sections, PlanSectionSource $source): ?array
    {
        foreach ($sections as $section) {
            if (($section['binding']['source'] ?? null) !== $source->value) {
                continue;
            }

            return [
                'section_key' => $section['key'],
                'title' => $section['title'],
                'last_verified_at' => $section['last_verified_at'],
                'rows' => $section['live']['rows'] ?? [],
            ];
        }

        return null;
    }

    /** @param list<array<string, mixed>> $sections */
    private function containsPersonalData(array $sections): bool
    {
        foreach ($sections as $section) {
            $source = PlanSectionSource::tryFrom((string) ($section['binding']['source'] ?? ''));

            if ($source?->isPersonalData()) {
                return true;
            }
        }

        return false;
    }

    private function disk(): string
    {
        return (string) (config('bcms.offline_disk') ?? config('filesystems.default'));
    }
}
