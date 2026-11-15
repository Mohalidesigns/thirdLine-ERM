<?php

namespace App\Traits;

use App\Services\ActivityLogger;

/**
 * Attach to any Eloquent model to auto-record create/update/delete events
 * into activity_logs. Models may override $activityLogIgnore to skip fields
 * that shouldn't appear in the diff (timestamps, computed counters, etc.).
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $attributes = array_diff_key($model->getAttributes(), array_flip(self::activityIgnoreList($model)));

            ActivityLogger::log(
                event: 'created',
                subject: $model,
                description: class_basename($model).' created',
                properties: [
                    'attributes' => $attributes,
                ],
            );
        });

        static::updated(function ($model) {
            $ignore = self::activityIgnoreList($model);
            $changes = array_diff_key($model->getChanges(), array_flip($ignore));
            $original = array_diff_key(
                collect($model->getRawOriginal())->only(array_keys($model->getChanges()))->all(),
                array_flip($ignore)
            );

            if (empty($changes)) {
                return;
            }

            ActivityLogger::log(
                event: 'updated',
                subject: $model,
                description: class_basename($model).' updated',
                properties: [
                    'changed' => $changes,
                    'original' => $original,
                ],
            );
        });

        static::deleted(function ($model) {
            ActivityLogger::log(
                event: 'deleted',
                subject: $model,
                description: class_basename($model).' deleted',
                properties: [],
            );
        });
    }

    /**
     * Fields excluded from every activity-log payload (create/update/delete)
     * for this model. Defaults protect credential material and Eloquent
     * timestamps; models can add their own via `public array $activityLogIgnore`.
     *
     * @return array<int, string>
     */
    private static function activityIgnoreList(object $model): array
    {
        $modelIgnore = property_exists($model, 'activityLogIgnore') ? $model->activityLogIgnore : [];

        return array_values(array_unique(array_merge(
            ['created_at', 'updated_at', 'deleted_at', 'password', 'remember_token', 'api_token', 'mfa_secret'],
            $modelIgnore
        )));
    }
}
