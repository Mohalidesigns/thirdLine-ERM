<?php

namespace App\Enums\Bcms;

/**
 * The lifecycle of one business impact assessment.
 *
 * `Returned` IS NOT `Draft`. A returned assessment carries a reviewer's reason
 * and goes back to the same assessor with what they got wrong; a draft has never
 * been looked at. Collapsing the two loses the only signal that tells a
 * coordinator which of forty outstanding assessments has already failed review
 * once — and that one is a different conversation from the thirty-nine.
 *
 * `Approved` FIXES THE NUMBERS. Every strategy, plan, DR tier and regulatory
 * return downstream is measured against an approved assessment's RTO, which is
 * why approving is a separate permission from completing and why an approved
 * assessment is edited by superseding it rather than in place.
 */
enum BiaAssessmentStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';

    /** Still with the assessor. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::InProgress, self::Returned], true);
    }

    /** Counts as a response for the campaign's response rate. */
    public function isResponded(): bool
    {
        return in_array($this, [self::Submitted, self::Approved], true);
    }

    /** May the assessor still change the numbers? */
    public function isEditable(): bool
    {
        return $this->isOpen();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Not started',
            self::InProgress => 'In progress',
            self::Submitted => 'Submitted for review',
            self::Approved => 'Approved',
            self::Returned => 'Returned for rework',
        };
    }
}
