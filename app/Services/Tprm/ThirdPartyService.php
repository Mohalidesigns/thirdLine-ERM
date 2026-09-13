<?php

namespace App\Services\Tprm;

use App\Enums\Tprm\ThirdPartyStatus;
use App\Models\Tprm\ThirdParty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Writes to the third-party register.
 *
 * Thin by design: the register is master data, so most of this is validated
 * assignment. The two parts that are not — slug allocation and status
 * transitions — are here because both have a rule that a Form Request cannot
 * express.
 */
class ThirdPartyService
{
    public function __construct(private readonly ThirdPartyDeduplicator $deduplicator) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{third_party: ThirdParty|null, candidates: Collection<int, array{third_party: \App\Models\Tprm\ThirdParty, kind: string, on: string, confidence: float}>}
     */
    public function create(array $attributes, ?int $userId = null, bool $acceptDuplicate = false): array
    {
        $candidates = $this->deduplicator->candidatesFor([
            'legal_name' => $attributes['legal_name'] ?? null,
            'registration_number' => $attributes['registration_number'] ?? null,
            'tax_id' => $attributes['tax_id'] ?? null,
            'lei' => $attributes['lei'] ?? null,
        ]);

        // FR-TPR-02 presents candidates rather than blocking — but presenting
        // them and then creating the record anyway would make the panel
        // decorative. So the first attempt returns the candidates and creates
        // nothing; the caller confirms, and the second attempt proceeds. The
        // confirmation is what gets recorded, which is the audit question:
        // not "was there a duplicate" but "did somebody look at it".
        if ($candidates->isNotEmpty() && ! $acceptDuplicate) {
            return ['third_party' => null, 'candidates' => $candidates];
        }

        $thirdParty = DB::transaction(function () use ($attributes, $userId, $candidates) {
            $thirdParty = ThirdParty::create($attributes + [
                'slug' => $this->uniqueSlug((string) ($attributes['legal_name'] ?? 'third-party')),
                'status' => $attributes['status'] ?? ThirdPartyStatus::Prospect->value,
                'created_by' => $userId,
            ]);

            if ($candidates->isNotEmpty()) {
                $thirdParty->writeAuditRow('duplicate_accepted', null, [
                    'candidates' => $candidates->map(fn (array $c) => [
                        'id' => $c['third_party']->getKey(),
                        'legal_name' => $c['third_party']->legal_name,
                        'kind' => $c['kind'],
                        'on' => $c['on'],
                        'confidence' => $c['confidence'],
                    ])->all(),
                ]);
            }

            return $thirdParty;
        });

        return ['third_party' => $thirdParty, 'candidates' => collect()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ThirdParty $thirdParty, array $attributes, ?int $userId = null): ThirdParty
    {
        $thirdParty->fill($attributes + ['updated_by' => $userId]);

        // The slug follows the legal name only while nothing links to the
        // record yet. Once an engagement exists the slug is in URLs people
        // have bookmarked and in exports they have filed, so it stops moving.
        if ($thirdParty->isDirty('legal_name') && ! $thirdParty->engagements()->exists()) {
            $thirdParty->slug = $this->uniqueSlug((string) $thirdParty->legal_name, $thirdParty->getKey());
        }

        $thirdParty->save();

        return $thirdParty;
    }

    /**
     * Change the entity's lifecycle status, refusing a move the lifecycle does
     * not allow.
     *
     * FR-TPR-06 is enforced here rather than in a Form Request because it is a
     * rule about the RECORD's readiness, not about the request: neither owner
     * may be vacant while the entity is active, and an entity activated
     * through the API must satisfy it too.
     *
     * @return array{changed: bool, reason: string|null}
     */
    public function changeStatus(ThirdParty $thirdParty, ThirdPartyStatus $target, ?int $userId = null): array
    {
        $current = $thirdParty->status ?? ThirdPartyStatus::Prospect;

        if (! $current->canTransitionTo($target)) {
            return [
                'changed' => false,
                'reason' => "A third party cannot move from {$current->label()} to {$target->label()}.",
            ];
        }

        if ($target === ThirdPartyStatus::Active) {
            if ($thirdParty->relationship_owner_id === null || $thirdParty->oversight_owner_id === null) {
                return [
                    'changed' => false,
                    'reason' => 'An active third party needs both a relationship owner and an oversight owner '
                        .'(FR-TPR-06). Neither may be vacant.',
                ];
            }
        }

        $thirdParty->forceFill(['status' => $target->value, 'updated_by' => $userId])->save();

        return ['changed' => true, 'reason' => null];
    }

    /**
     * A slug unique within the tenant.
     *
     * Scoped to the organisation, so two tenants may each have their own
     * "interlink-systems" — the uniqueness constraint is composite and the
     * generator has to match it or the insert fails on the second tenant.
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'third-party';
        $slug = $base;
        $suffix = 1;

        $exists = fn (string $candidate) => ThirdParty::withTrashed()
            ->where('organization_id', TenantContext::organizationIdOrNull())
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        while ($exists($slug)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
