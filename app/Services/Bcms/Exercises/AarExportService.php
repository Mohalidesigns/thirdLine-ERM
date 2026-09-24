<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Finding;

/**
 * The AAR export pack (ADR 0019 §5, compliance analyst refinement 12).
 *
 * BUILT FROM STORED VALUES, COMPUTING NOTHING — modelled on
 * `App\Services\Bcms\Emns\EvidenceExport`, for the reason that export gives:
 * an evidence pack that disagrees with last month's copy of itself is worse
 * than none.
 *
 * ONE JSON RENDERING. ADR 0019 §5 names a PDF as well, through
 * `ThirdLine\Reporting\DocumentRenderer`; that half is not built in this
 * pass — see the phase notes for why — and the JSON here already answers
 * "show me the last fire drill" in one click, which is the sufficiency bar
 * the compliance analyst set.
 *
 * THE EVIDENCE SECTION IS AN INDEX, NOT A BUNDLE — filename, kind, caption,
 * uploader, `captured_at`, sha256, lock state. Zipping every photograph is a
 * different feature with a different cost; the bytes are fetched
 * individually through the permissioned download route, and the hash here is
 * what lets an examiner prove the file they were given is the file that was
 * locked.
 */
class AarExportService
{
    public function __construct(private AarService $aarService) {}

    /** @return array<string, mixed> */
    public function build(ExerciseOccurrence $occurrence): array
    {
        $aar = $occurrence->aar;

        if ($aar === null) {
            return ['occurrence_uuid' => $occurrence->uuid, 'aar' => null];
        }

        $this->aarService->refreshComputedSections($aar);
        $aar->refresh();

        $definition = $occurrence->definition;

        return [
            'schema' => 'bcms.aar.export.v1',
            'generated_at' => now()->toIso8601String(),
            'occurrence' => [
                'uuid' => $occurrence->uuid,
                'exercise' => $definition?->name,
                'exercise_type' => $definition?->exerciseType?->name,
                'ladder_level' => $definition?->exerciseType?->ladder_level?->value,
                'scheduled_date' => $occurrence->scheduled_date?->toDateString(),
                'actual_start' => $occurrence->actual_start?->toIso8601String(),
                'actual_end' => $occurrence->actual_end?->toIso8601String(),
                'facilitator' => $occurrence->facilitator?->name,
                'site' => $occurrence->site?->name,
                'outcome' => $occurrence->outcome?->value,
                'iso_clause_ref' => $occurrence->iso_clause_ref,
            ],
            'aar' => [
                'uuid' => $aar->uuid,
                'status' => $aar->status,
                'summary' => $aar->summary,
                'what_worked' => $aar->what_worked,
                'what_failed' => $aar->what_failed,
                'quantitative_results' => $aar->quantitative_results,
                'participant_feedback' => $aar->participant_feedback,
                'ai_generated' => (bool) $aar->ai_generated,
                'approved_by' => $aar->approver?->name,
                'approved_at' => $aar->approved_at?->toIso8601String(),
                'distributed_at' => $aar->distributed_at?->toIso8601String(),
                'iso_clause_ref' => $aar->iso_clause_ref,
            ],
            'timeline' => $occurrence->timeline()->with('loggedBy:id,name')->orderBy('logged_at')->get()->map(fn ($t) => [
                'logged_at' => $t->logged_at->toIso8601String(),
                'entry_type' => $t->entry_type,
                'content' => $t->content,
                'logged_by' => $t->loggedBy?->name,
            ])->all(),
            'scores' => $occurrence->scores()->with('evaluator:id,name')->get()->map(fn ($s) => [
                'objective_text' => $s->objective_text,
                'evaluator' => $s->evaluator?->name,
                'score' => $s->score,
                'commentary' => $s->commentary,
            ])->all(),
            'attendance' => $occurrence->participants()->with(['user:id,name'])->get()->map(fn ($p) => [
                'name' => $p->user?->name,
                'role' => $p->role,
                'attendance_status' => $p->attendance_status,
                'check_in_method' => $p->check_in_method,
                'checked_in_at' => $p->checked_in_at?->toIso8601String(),
            ])->all(),
            'readiness_overrides' => $occurrence->readinessTasks()->whereNotNull('override_reason')->get()->map(fn ($t) => [
                'task' => $t->title,
                'reason' => $t->override_reason,
                'overridden_at' => $t->overridden_at?->toIso8601String(),
            ])->all(),
            'findings' => Finding::query()->where('aar_id', $aar->getKey())
                ->with('correctiveActions')->get()->map(fn (Finding $f) => [
                    'reference' => $f->reference,
                    'classification' => $f->classification?->value,
                    'severity' => $f->severity?->value,
                    'iso_clause_ref' => $f->iso_clause_ref,
                    'status' => $f->status,
                    'description' => $f->description,
                    'corrective_actions' => $f->correctiveActions->map(fn (\App\Models\Bcms\CorrectiveAction $a) => [
                        'reference' => $a->reference,
                        'status' => $a->status?->value,
                        'due_date' => $a->due_date?->toDateString(),
                        'carried_to_occurrence_id' => $a->carried_to_occurrence_id,
                    ])->all(),
                ])->all(),
            'evidence_index' => $occurrence->evidence()->with(['uploader:id,name'])->get()->map(fn ($e) => [
                'file_name' => $e->file_name,
                'kind' => $e->kind,
                'caption' => $e->caption,
                'uploader' => $e->uploader?->name,
                'captured_at' => $e->captured_at?->toIso8601String(),
                'hash' => $e->hash,
                'locked' => $e->isLocked(),
            ])->all(),
        ];
    }

    public function filename(ExerciseOccurrence $occurrence): string
    {
        return 'bcms-aar-'.$occurrence->uuid.'.json';
    }
}
