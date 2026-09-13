<?php

namespace App\Enums\Bcms;

/**
 * The clause groups the maturity engine scores (`bcms_maturity_scores.clause_group`).
 *
 * NINE GROUPS, NOT FIFTY CLAUSES. A maturity level for "clause 8.5.3" is a
 * precision the model does not have and would invite a customer to argue about
 * a number rather than about the gap behind it. The groups are the ones an
 * ISO 22301 auditor works through, with clause 8 split at the three places the
 * evidence genuinely differs — analysis, plans, exercises — because a bank with
 * excellent plans and no exercise programme is the failure mode this whole
 * product is sold against, and a single "clause 8" score would hide it.
 */
enum MaturityClauseGroup: string
{
    case Context = '4';
    case Leadership = '5';
    case Planning = '6';
    case Support = '7';
    case Analysis = '8.2';
    case Strategy = '8.3';
    case Plans = '8.4';
    case Exercises = '8.5';
    case Evaluation = '9';
    case Improvement = '10';

    public function label(): string
    {
        return match ($this) {
            self::Context => 'Context and scope',
            self::Leadership => 'Leadership and policy',
            self::Planning => 'Planning and objectives',
            self::Support => 'Support, competence and communication',
            self::Analysis => 'Business impact analysis',
            self::Strategy => 'Continuity strategy',
            self::Plans => 'Plans and procedures',
            self::Exercises => 'Exercise programme',
            self::Evaluation => 'Performance evaluation',
            self::Improvement => 'Improvement and corrective action',
        };
    }

    /**
     * The weight this group carries in the overall score.
     *
     * NOT EQUAL, AND THE INEQUALITY IS THE PRODUCT THESIS. Blueprint §2.4: a
     * certification proves you wrote plans; only testing proves readiness. The
     * exercise programme and improvement groups therefore weigh more than
     * context and leadership, which are documents. An organisation that scores
     * five on documentation and one on exercising should not come out average.
     */
    public function weight(): float
    {
        return match ($this) {
            self::Context, self::Leadership => 0.75,
            self::Planning, self::Support, self::Strategy => 1.0,
            self::Analysis, self::Plans, self::Evaluation => 1.25,
            self::Exercises, self::Improvement => 1.5,
        };
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return self::cases();
    }
}
