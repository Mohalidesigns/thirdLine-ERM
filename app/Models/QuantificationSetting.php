<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class QuantificationSetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'default_iterations',
        'default_confidence_levels',
        'cbn_minimum_car',
        'cbn_conservation_buffer',
        'cbn_mpr',
        'cbn_loss_threshold_kobo',
        'nfiu_str_threshold_kobo',
        'nfiu_ctr_threshold_kobo',
        'ndic_threshold_kobo',
        'distribution_defaults',
    ];

    protected $casts = [
        'default_confidence_levels' => 'array',
        'distribution_defaults' => 'array',
        'cbn_minimum_car' => 'decimal:4',
        'cbn_conservation_buffer' => 'decimal:4',
        'cbn_mpr' => 'decimal:4',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
