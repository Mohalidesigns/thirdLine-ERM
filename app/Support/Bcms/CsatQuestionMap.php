<?php

namespace App\Support\Bcms;

use App\Enums\Bcms\IsoClauseRef;

/**
 * The CBN CSAT pre-fill mapping content pack — `clause ref → CSAT section and
 * question label` — ADR 0021 §4.
 *
 * LABELS, NEVER COORDINATES. We do not hold the CBN CSAT workbook, so a cell
 * address is unknowable, and a guessed one would put our guess into a bank's
 * regulatory submission. The writer locates each question BY LABEL on the
 * customer's own uploaded workbook and leaves anything it cannot find
 * untouched — this class is the one place the label text lives, so the writer
 * and any future consumer read the same list rather than two.
 */
class CsatQuestionMap
{
    /**
     * @return list<array{clause_ref: string, section: string, question_label: string}>
     */
    public static function all(): array
    {
        return [
            ['clause_ref' => IsoClauseRef::Cbn_rcf_bcdr->value, 'section' => 'Cyber Resilience — BC/DR', 'question_label' => 'Does the institution maintain and test business continuity and disaster recovery arrangements for systems supporting critical banking services?'],
            ['clause_ref' => IsoClauseRef::Iso22301_8_4_5->value, 'section' => 'Cyber Resilience — BC/DR', 'question_label' => 'Are disaster recovery test results reported to the board?'],
            ['clause_ref' => IsoClauseRef::Cbn_rcf_incident->value, 'section' => 'Incident Response and Recovery', 'question_label' => 'Does the institution have an incident response and recovery capability, including reporting to the CBN?'],
            ['clause_ref' => IsoClauseRef::Iso22320_incident->value, 'section' => 'Incident Response and Recovery', 'question_label' => 'Does the institution participate in NigFinCERT information sharing?'],
            ['clause_ref' => IsoClauseRef::Cbn_rcf_drills->value, 'section' => 'Cyber Drills', 'question_label' => 'Has the institution conducted a cyber drill or participated in an industry-wide exercise in the assessment period?'],
            ['clause_ref' => IsoClauseRef::Iso22301_8_5_report->value, 'section' => 'Cyber Drills', 'question_label' => 'Is there a formal post-exercise report with actions arising, for each drill conducted?'],
            ['clause_ref' => IsoClauseRef::Iso22301_7_2->value, 'section' => 'Cyber Drills', 'question_label' => 'Is competency of staff involved in incident response and business continuity assessed and evidenced?'],
            ['clause_ref' => IsoClauseRef::Iso22318_supply_chain->value, 'section' => 'Vendor Management', 'question_label' => 'Does the institution evidence its critical vendors\' own business continuity and disaster recovery testing?'],
        ];
    }
}
