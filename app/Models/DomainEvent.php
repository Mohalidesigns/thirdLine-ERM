<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class DomainEvent extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'event_type',
        'source_module',
        'source_id',
        'payload',
        'status',
        'retry_count',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Polymorphic relationship to the aggregate root.
     * Maps aggregate_type string to the corresponding model class.
     */
    public function aggregate()
    {
        $class = Relation::getMorphedModel(
            MorphTypes::normalise($this->aggregate_type) ?? ''
        );
        if ($class) {
            return $this->belongsTo($class, 'aggregate_id');
        }

        return $this->belongsTo(Risk::class, 'aggregate_id'); // fallback
    }
}
