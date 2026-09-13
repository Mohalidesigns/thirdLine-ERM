<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The bridge from a module's own screen to the engine.
 *
 * Every "Approve" button on the platform pressed from a record's own page ends
 * up here. It exists so the controllers do not each have to know how to find
 * the right open task, what to do when no workflow is configured, and how to
 * keep behaving sensibly for a tenant that has not published a definition.
 *
 * THE FALLBACK IS DELIBERATE. An organization with no published definition for
 * a subject still has to be able to approve things — WP-06 replaces the
 * approval MECHANISM, and a tenant mid-upgrade must not find the button dead.
 * submit()/decide() return false in that case and the controller does what it
 * always did, minus the approval_requests write.
 */
class ModuleApprovals
{
    public function __construct(
        private WorkflowEngine $engine,
        private SubjectRegistry $subjects,
    ) {}

    /**
     * Start the named process over a subject, if the tenant has published one.
     *
     * @param  array<string, mixed>  $context
     * @return bool whether an instance was started
     */
    public function submit(string $definitionCode, Model $subject, array $context = [], ?User $actor = null): bool
    {
        if ($this->engine->openInstanceFor($subject) !== null) {
            throw new RuntimeException('A review of this record is already running.');
        }

        return $this->engine->startFor($definitionCode, $subject, $context, $actor) !== null;
    }

    /**
     * Record a decision on whatever open task this user owns on this subject.
     *
     * @param  array<string, mixed>  $payload
     * @return bool whether the engine handled it
     */
    public function decide(Model $subject, string $outcome, User $actor, array $payload = []): bool
    {
        $task = $this->engine->taskFor($subject, $actor);

        if ($task === null) {
            return false;
        }

        $this->engine->advance($task, $outcome, $payload, $actor);

        return true;
    }

    /**
     * Apply a decision without an engine instance.
     *
     * The fallback path for a tenant that has not published the definition yet,
     * and the reason the domain work lives in the bindings rather than in the
     * controllers: both routes end in the same code, so a record approved
     * before the definition was published and one approved after it are left in
     * exactly the same state.
     */
    public function decideDirectly(Model $subject, string $outcome, ?User $actor, ?string $comments = null): void
    {
        $binding = $this->subjects->for($subject);

        if ($binding === null) {
            return;
        }

        match ($outcome) {
            'reject' => $binding->onRejected($subject, null, $actor, $comments),
            'return' => $binding->onReturned($subject, null, $actor, $comments),
            'cancel' => $binding->onCancelled($subject, null, $actor, $comments),
            default => $binding->onApproved($subject, null, $actor, $comments),
        };
    }

    /**
     * Put the subject into its "submitted" state without an engine instance —
     * the same fallback, for the submit step.
     */
    public function markSubmitted(Model $subject): void
    {
        $this->subjects->for($subject)?->onStarted($subject, null);
    }

    /** The open task this user may act on for this subject, for rendering the buttons. */
    public function taskFor(Model $subject, User $actor): ?WorkflowTask
    {
        return $this->engine->taskFor($subject, $actor);
    }

    /** Whether a workflow is running over this subject at all. */
    public function isRunning(Model $subject): bool
    {
        return $this->engine->openInstanceFor($subject) !== null;
    }

    /**
     * Cancel any open instance — used when a record is withdrawn or deleted, so
     * a task does not outlive the thing it was about.
     */
    public function withdraw(Model $subject, string $reason, ?User $actor = null): void
    {
        $instance = $this->engine->openInstanceFor($subject);

        if ($instance !== null) {
            $this->engine->cancel($instance, $reason, $actor);
        }
    }
}
