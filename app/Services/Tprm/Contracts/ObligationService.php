<?php

namespace App\Services\Tprm\Contracts;

use App\Enums\Tprm\ObligationStatus;
use App\Models\Tprm\Obligation;
use Illuminate\Support\Facades\DB;

/**
 * Satisfying, breaching and advancing obligations.
 *
 * SATISFYING A RECURRING DUTY ADVANCES IT; IT DOES NOT CLOSE IT. A quarterly
 * access review marked done in April is due again in July, and a register that
 * closed it would show an empty list and a bank with no access reviews. The
 * status returns to `pending` with the next date set, which is what a standing
 * duty looks like between occurrences.
 *
 * A BREACH INCREMENTS A COUNTER AND THE COUNTER IS NEVER RESET. Three missed
 * quarterly reviews in a year is a different fact from one, and it is the fact
 * that belongs in a board pack — but only if the second and third are still
 * visible after the fourth was done on time. `breach_count` is cumulative for
 * the life of the obligation, deliberately.
 */
class ObligationService
{
    /**
     * Record that a duty was performed.
     *
     * Evidence is REQUIRED where the obligation says it is, and refusing
     * without it is the point: an annual assurance report "obtained" with
     * nothing attached is a tick in a box, and the tick is what a supervisor
     * asks to see behind.
     *
     * @return array{satisfied: bool, reason: string|null, next_due: string|null}
     */
    public function satisfy(Obligation $obligation, ?int $documentId = null, ?int $userId = null): array
    {
        if ($obligation->evidence_required && $documentId === null) {
            return [
                'satisfied' => false,
                'reason' => 'This obligation requires evidence. Attach the document that shows it was performed '
                    .'— a tick with nothing behind it is what a supervisor asks to see behind.',
                'next_due' => null,
            ];
        }

        return DB::transaction(function () use ($obligation, $documentId, $userId) {
            $next = $obligation->isRecurring() ? $obligation->nextOccurrenceAfter() : null;

            $obligation->forceFill([
                // A recurring duty returns to `pending` for its next
                // occurrence; a one-off is done.
                'status' => $next === null
                    ? ObligationStatus::Satisfied->value
                    : ObligationStatus::Pending->value,
                'next_due_date' => $next?->toDateString(),
                'evidence_document_id' => $documentId ?? $obligation->evidence_document_id,
                'updated_by' => $userId,
            ])->save();

            $obligation->writeAuditRow('obligation_satisfied', null, [
                'evidence_document_id' => $documentId,
                'next_due_date' => $next?->toDateString(),
            ]);

            return [
                'satisfied' => true,
                'reason' => null,
                'next_due' => $next?->toDateString(),
            ];
        });
    }

    /**
     * Record a breach and move the duty to its next occurrence.
     *
     * The next date advances even though this one was missed, for the reason
     * `nextOccurrenceAfter()` gives: a duty that stayed on a date already past
     * would report the same breach every day until somebody closed it, and the
     * count of breaches would then depend on how often the job ran.
     */
    public function breach(Obligation $obligation, ?string $note = null, ?int $userId = null): Obligation
    {
        return DB::transaction(function () use ($obligation, $note, $userId) {
            $next = $obligation->isRecurring() ? $obligation->nextOccurrenceAfter() : null;

            $obligation->forceFill([
                'status' => $next === null
                    ? ObligationStatus::Breached->value
                    : ObligationStatus::Pending->value,
                'breach_count' => $obligation->breach_count + 1,
                'next_due_date' => $next?->toDateString(),
                'updated_by' => $userId,
            ])->save();

            $obligation->writeAuditRow('obligation_breached', null, [
                'breach_count' => $obligation->breach_count,
                'missed_date' => $obligation->getOriginal('next_due_date'),
                'note' => $note,
            ]);

            return $obligation->refresh();
        });
    }

    /**
     * Mark outstanding duties whose date has passed as `due`.
     *
     * `due` and `breached` are different states and the gap between them is
     * where a register earns its keep: an obligation one day past its date
     * needs a reminder, and one a month past needs a conversation. The nightly
     * sweep moves the first; only a person or the grace period moves the
     * second.
     */
    public function markDue(?int $organizationId = null): int
    {
        return Obligation::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', ObligationStatus::Pending->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', now()->toDateString())
            ->update(['status' => ObligationStatus::Due->value]);
    }

    /**
     * Breach anything still `due` beyond the grace period.
     *
     * The grace period exists so that a duty performed on the Monday after a
     * weekend deadline is not recorded as a breach nobody could have avoided.
     */
    public function breachOverdue(int $graceDays = 7, ?int $organizationId = null): int
    {
        $overdue = Obligation::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', ObligationStatus::Due->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', now()->subDays($graceDays)->toDateString())
            ->get();

        foreach ($overdue as $obligation) {
            $this->breach($obligation, "Not performed within {$graceDays} days of its due date.");
        }

        return $overdue->count();
    }

    /**
     * The register summary — split by who owes what, because those are two
     * different conversations.
     *
     * @return array<string, int>
     */
    public function summary(?int $organizationId = null): array
    {
        $base = fn () => Obligation::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));

        return [
            'total' => $base()->count(),
            'ours' => $base()->owedByUs()->outstanding()->count(),
            'theirs' => $base()->where('obligor', Obligation::OBLIGOR_PROVIDER)->outstanding()->count(),
            'overdue' => $base()->overdue()->count(),
            'due_30' => $base()->dueWithin(30)->count(),
            'unowned' => $base()->outstanding()->whereNull('owner_id')->count(),
            'breached_ever' => $base()->where('breach_count', '>', 0)->count(),
        ];
    }
}
