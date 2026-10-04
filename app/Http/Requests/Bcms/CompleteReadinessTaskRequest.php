<?php

namespace App\Http\Requests\Bcms;

use App\Models\Bcms\ReadinessTask;
use App\Services\FileUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completing a readiness task, with optional evidence (A8 — moved out of
 * `ReadinessController::complete()`'s inline `$request->validate([...])`;
 * rules and authorization are unchanged from the controller version, message
 * text included via `failedAuthorization()` below).
 *
 * GAP 5, OWNERSHIP-SCOPED: a holder of `bcms.exercise.facilitate` may
 * complete any task, as before. Someone who holds only `my.view` may
 * complete only a task THEY OWN — `readiness_tasks.owner_id`, the exact
 * column `ReadinessService::forUser()` filters on for `me/readiness-
 * tasks` — never any other employee's. A `my.view`-only caller naming a
 * task that is not theirs is refused before anything else runs.
 */
class CompleteReadinessTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user?->can('bcms.exercise.facilitate') === true) {
            return true;
        }

        if ($user?->can('my.view') !== true) {
            return false;
        }

        $task = $this->route('task');

        return $task instanceof ReadinessTask && (int) $task->owner_id === (int) $user->getKey();
    }

    /**
     * The controller version distinguished "no grant at all" (the stock
     * `Gate::authorize('my.view')` message) from "my.view, but not your
     * task" (`abort(403, 'This readiness task is not assigned to you.')`) —
     * preserved here rather than collapsing both into one generic message.
     */
    protected function failedAuthorization(): void
    {
        $user = $this->user();
        $task = $this->route('task');

        if ($user?->can('bcms.exercise.facilitate') !== true
            && $user?->can('my.view') === true
            && $task instanceof ReadinessTask
            && (int) $task->owner_id !== (int) $user->getKey()) {
            throw new AuthorizationException('This readiness task is not assigned to you.');
        }

        throw new AuthorizationException;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evidence' => app(FileUploadService::class)->rules(FileUploadService::PROFILE_BCMS_EVIDENCE, required: false),
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
