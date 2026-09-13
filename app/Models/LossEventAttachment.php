<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Columns added by the 200038 alignment migration through its addColumns()
 * loop, which Larastan cannot see statically.
 *
 * @property int|null $file_size
 * @property string|null $original_name
 * @property string|null $file_name
 * @property string|null $document_type
 * @property bool $is_regulatory
 */
class LossEventAttachment extends Model
{
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'loss_event_id',
        'file_name',
        'file_size_bytes',
        'file_type',
        'storage_path',
        'document_type',
        'is_regulatory',
        'uploaded_by',
    ];

    protected $casts = [
        'is_regulatory' => 'boolean',
    ];

    public function getDownloadUrlAttribute(): string
    {
        return route('risk.loss-events.download-attachment', [
            'lossEvent' => $this->loss_event_id,
            'attachment' => $this->id,
        ]);
    }

    public function getSizeFormattedAttribute(): string
    {
        $bytes = (int) $this->file_size_bytes;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
