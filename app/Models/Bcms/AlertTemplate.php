<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A message template, one row per locale.
 *
 * Not a JSON blob of locales: a WhatsApp Business template is APPROVED PER
 * LOCALE by Meta, and the approval reference belongs on the row it approves.
 * `channel_renderings` exists because 160 characters of SMS, a voice TTS script
 * and a Teams card are three different texts, and one body truncated three ways
 * is how a life-safety instruction loses its second sentence.
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $category
 * @property \App\Enums\Bcms\AlertSeverity $severity
 * @property string $locale
 * @property ?string $subject
 * @property string $body
 * @property array<array-key, mixed> $channel_renderings
 * @property ?string $whatsapp_template_name
 * @property array<array-key, mixed> $variables
 * @property bool $requires_dual_approval
 * @property array<array-key, mixed> $default_audience_rule
 * @property array<array-key, mixed> $default_channel_set
 * @property bool $is_life_safety
 * @property bool $is_system_default
 * @property bool $is_active
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class AlertTemplate extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_alert_templates';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'category', 'severity', 'locale', 'subject', 'body',
        'channel_renderings', 'whatsapp_template_name', 'variables', 'requires_dual_approval',
        'default_audience_rule', 'default_channel_set', 'is_life_safety', 'is_system_default',
        'is_active', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel_renderings' => 'array',
            'variables' => 'array',
            'default_audience_rule' => 'array',
            'default_channel_set' => 'array',
            'organization_id' => 'integer',
            'requires_dual_approval' => 'boolean',
            'is_life_safety' => 'boolean',
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'severity' => AlertSeverity::class,
        ];
    }
}
