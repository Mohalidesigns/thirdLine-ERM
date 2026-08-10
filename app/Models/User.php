<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use BelongsToOrganization, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'scope_entity_id',
        'name',
        'email',
        'password',
        'staff_id',
        'job_title',
        'department',
        'phone',
        'is_active',
        'mfa_secret',
        'mfa_enabled',
        'login_attempts',
        'locked_until',
        'last_login_at',
        'password_changed_at',
        'must_change_password',
        'last_activity_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /**
     * The graph node this user is confined to, or null for organization-wide
     * visibility. See App\Support\Authorization\GraphScope.
     */
    public function scopeEntity()
    {
        return $this->belongsTo(Entity::class, 'scope_entity_id');
    }

    public function ownedRisks()
    {
        return $this->hasMany(Risk::class, 'risk_owner_id');
    }

    public function stewardedRisks()
    {
        return $this->hasMany(Risk::class, 'risk_steward_id');
    }

    public function ownedControls()
    {
        return $this->hasMany(Control::class, 'owner_id');
    }

    public function ownedIssues()
    {
        return $this->hasMany(Issue::class, 'responsible_owner_id');
    }

    public function ownedTreatments()
    {
        return $this->hasMany(TreatmentPlan::class, 'owner_id');
    }

    public function ownedKris()
    {
        return $this->hasMany(KeyRiskIndicator::class, 'owner_id');
    }

    public function assessments()
    {
        return $this->hasMany(RiskAssessment::class, 'assessor_id');
    }

    public function assignedLossEvents()
    {
        return $this->hasMany(LossEvent::class, 'assigned_to_id');
    }
}
