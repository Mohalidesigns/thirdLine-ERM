<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Risk appetite approval.
 *
 * An appetite statement is a board artefact: the limit against which every RAG
 * band on the platform is read. risk_appetite carries approved_by and
 * approved_date and nothing else, so "approved" here means those two columns
 * are set — an unapproved appetite is one with a null approved_date, which is
 * the state WP-10's appetite rewrite will make visible on screen.
 */
class RiskAppetiteBinding extends BaseSubjectBinding
{
    public function label(Model $subject): string
    {
        return trim(($subject->riskCategory?->name ?? 'Risk appetite')
            .' — '.($subject->appetite_level ?? $subject->appetite_type ?? ''));
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'risk_category_id' => $subject->risk_category_id,
            'appetite_level' => $subject->appetite_level,
            'appetite_type' => $subject->appetite_type,
            'max_tolerance' => $subject->max_tolerance,
            'target_min' => $subject->target_min,
            'target_max' => $subject->target_max,
            'effective_date' => optional($subject->effective_date)->toDateString(),
        ];
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->write($subject, [
            'approved_by' => $actor?->id,
            'approved_date' => now()->toDateString(),
        ]);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        // A rejected appetite is one that was never approved. Clearing the
        // columns rather than writing a rejection flag keeps "in force" a
        // single unambiguous test — approved_date is not null — everywhere it
        // is asked.
        $this->write($subject, [
            'approved_by' => null,
            'approved_date' => null,
        ]);
    }
}
