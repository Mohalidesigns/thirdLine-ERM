<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Support\Tprm\TrustProfileSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The vendor's answers, written once — FR-PRT-04.
 *
 * NO `BelongsToOrganization`. It hangs from a `VendorIdentity`, which has no
 * organisation either. This and `tp_vendor_identities` are the only two tables
 * in the module outside the tenant scope, and the compensating control is that
 * every internal read goes through an approved `TrustProfileShare` — never
 * through this model directly.
 *
 * DRAFT AND PUBLISHED ARE SEPARATE DOCUMENTS, not one document with a status.
 * A vendor revising next quarter's answers must not change what four banks are
 * reading today, and a status flag on a single document forces exactly that:
 * either the edits go live as they are typed, or the vendor cannot start them.
 * `published` is what a share exposes; `draft` is never shared with anybody.
 */
class TrustProfile extends Model
{
    use HasTprmUuid;

    protected $table = 'tp_trust_profiles';

    protected $fillable = ['vendor_identity_id', 'draft'];

    /**
     * Publishing is an act with a version number, not a field somebody sets.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'published', 'published_version', 'last_published_at', 'completeness_pct',
    ];

    protected $casts = [
        'draft' => 'array',
        'published' => 'array',
        'published_version' => 'integer',
        'last_published_at' => 'datetime',
        'completeness_pct' => 'decimal:2',
    ];

    /** @return BelongsTo<VendorIdentity, $this> */
    public function vendorIdentity(): BelongsTo
    {
        return $this->belongsTo(VendorIdentity::class, 'vendor_identity_id');
    }

    /** @return HasMany<TrustProfileShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(TrustProfileShare::class, 'trust_profile_id');
    }

    public function isPublished(): bool
    {
        return $this->published_version > 0 && $this->published !== null;
    }

    /**
     * The document a client sees, restricted to the sections it may read.
     *
     * TAKES THE SCOPE RATHER THAN CONSULTING IT. The caller has already
     * resolved which share applies and whether the vendor approved it; a
     * method that went and found its own share would be one refactor away from
     * finding the wrong one.
     *
     * @param  list<string>|null  $sections
     * @return array<string, mixed>
     */
    public function publishedFor(?array $sections): array
    {
        $document = (array) ($this->published ?? []);

        if ($sections === null) {
            return $document;
        }

        return array_intersect_key($document, array_flip($sections));
    }

    /**
     * How complete the PUBLISHED document is, section by section.
     *
     * Measured on what is published rather than on the draft, because the
     * number is a promise to clients about what they can read. A vendor with a
     * perfect unpublished draft has shared nothing.
     *
     * @return array{pct: float, sections: list<array<string, mixed>>}
     */
    public function completeness(): array
    {
        return self::scoreDocument((array) ($this->published ?? []));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{pct: float, sections: list<array<string, mixed>>}
     */
    public static function scoreDocument(array $document): array
    {
        $earned = 0.0;
        $sections = [];

        foreach (TrustProfileSchema::SECTIONS as $key => $section) {
            $answered = 0;

            foreach ($section['fields'] as $field) {
                $value = $document[$key][$field] ?? null;

                if (is_array($value) ? $value !== [] : (is_string($value) ? trim($value) !== '' : $value !== null)) {
                    $answered++;
                }
            }

            $total = count($section['fields']);
            $fraction = $total === 0 ? 0.0 : $answered / $total;

            $earned += $fraction * $section['weight'];

            $sections[] = [
                'key' => $key,
                'label' => $section['label'],
                'answered' => $answered,
                'total' => $total,
                'pct' => round($fraction * 100, 1),
                'weight' => $section['weight'],
                'unlocks' => $section['unlocks'],
                // What the vendor would gain by finishing this section — the
                // ordering key for the "what unlocks faster onboarding" list.
                'points_available' => round((1 - $fraction) * $section['weight'], 1),
            ];
        }

        return [
            'pct' => round(($earned / max(1, TrustProfileSchema::totalWeight())) * 100, 1),
            'sections' => $sections,
        ];
    }
}
