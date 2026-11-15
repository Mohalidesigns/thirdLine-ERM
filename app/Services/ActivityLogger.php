<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Central writer for the application activity audit log. Anything that needs
 * to be traceable — logins, CRUD, workflow transitions, administrative actions —
 * flows through here.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
        ?int $userId = null,
        ?string $userName = null,
        ?string $userEmail = null,
        ?int $organizationId = null,
    ): ?ActivityLog {
        try {
            $request = request();
            $user = Auth::user();

            $orgId = $organizationId
                ?? ($subject && isset($subject->organization_id) ? $subject->organization_id : null)
                ?? ($user && isset($user->organization_id) ? $user->organization_id : null)
                ?? TenantContext::organizationIdOrNull();

            return ActivityLog::create([
                'organization_id' => $orgId,
                'user_id' => $userId ?? $user?->id,
                'user_name' => $userName ?? $user?->name,
                'user_email' => $userEmail ?? $user?->email,
                'event' => $event,
                'auditable_type' => $subject ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'subject_label' => $subject ? self::labelFor($subject) : null,
                'description' => $description,
                'route_name' => $request?->route()?->getName(),
                'url' => $request?->fullUrl(),
                'method' => $request?->method(),
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 500) ?: null,
                'properties' => $properties ?: null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let logging errors take down the user's request.
            report($e);

            return null;
        }
    }

    /**
     * Best-effort human label for a subject. Falls back to the class basename +
     * key so the admin UI always has something to display.
     */
    public static function labelFor(Model $model): string
    {
        foreach (['name', 'title', 'code', 'reference', 'subject', 'label', 'email'] as $attr) {
            if (isset($model->{$attr}) && is_string($model->{$attr}) && $model->{$attr} !== '') {
                return $model->{$attr};
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
