<?php

namespace App\Models;

use App\Enums\BaselEventTypeL1;
use App\Enums\BaselEventTypeL2;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Immutable snapshot of a RiskLibraryItem at a specific version (ADR 0028 §2.3).
 *
 * Updates and deletions are blocked at the model lifecycle layer so historical
 * version diffs and ISO 31000 §6.7 audit provenance cannot be altered.
 */
class RiskLibraryItemVersion extends Model
{
    use BelongsToOrganization, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'library_item_id',
        'version_number',
        'title',
        'description',
        'category_id',
        'risk_source',
        'basel_l1_event_type',
        'basel_l2_event_type',
        'typical_causes',
        'typical_consequences',
        'suggested_controls',
        'suggested_kris',
        'change_summary',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'typical_causes' => 'array',
        'typical_consequences' => 'array',
        'suggested_controls' => 'array',
        'suggested_kris' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            if ($version->created_at === null) {
                $version->created_at = now()->utc();
            }
        });

        static::updating(function (): void {
            throw new LogicException('RiskLibraryItemVersion snapshots are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('RiskLibraryItemVersion snapshots are immutable and cannot be deleted.');
        });
    }

    /** @return BelongsTo<RiskLibraryItem, $this> */
    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(RiskLibraryItem::class, 'library_item_id');
    }

    /** @return BelongsTo<RiskCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function baselL1Enum(): ?BaselEventTypeL1
    {
        return $this->basel_l1_event_type ? BaselEventTypeL1::tryFrom($this->basel_l1_event_type) : null;
    }

    public function baselL2Enum(): ?BaselEventTypeL2
    {
        return $this->basel_l2_event_type ? BaselEventTypeL2::tryFrom($this->basel_l2_event_type) : null;
    }
}
