<?php

namespace App\Services\Workflow;

/**
 * Records a workflow can be started over, per bound subject type — the
 * options behind the definitions screen's "Start" form (migration Phase
 * 3.7, was WorkflowController::startableSubjects).
 */
class StartableSubjects
{
    /**
     * @return array<string, list<array{id:int, label:string}>>
     */
    public function options(int $organizationId): array
    {
        $sources = [
            'issue' => [\App\Models\Issue::class, fn ($i) => ($i->issue_reference ?? "ISS-{$i->id}").' — '.($i->title ?? '')],
            'loss_event' => [\App\Models\LossEvent::class, fn ($e) => ($e->event_reference ?? "LE-{$e->id}").' — '.($e->title ?? '')],
            'risk_assessment' => [\App\Models\RiskAssessment::class, fn ($a) => 'ASS-'.str_pad((string) $a->id, 4, '0', STR_PAD_LEFT)],
            'treatment_plan' => [\App\Models\TreatmentPlan::class, fn ($t) => ($t->treatment_code ?? "TP-{$t->id}").' — '.($t->action_title ?? '')],
            'control_test' => [\App\Models\ControlTest::class, fn ($t) => ($t->test_code ?? "CT-{$t->id}")],
            'risk' => [\App\Models\Risk::class, fn ($r) => ($r->risk_code ?? "RSK-{$r->id}").' — '.($r->title ?? '')],
            'risk_appetite' => [\App\Models\RiskAppetite::class, fn ($a) => 'Appetite #'.$a->id.' — '.($a->appetite_level ?? '')],
            'icaap_assessment' => [\App\Models\IcaapAssessment::class, fn ($i) => 'ICAAP '.($i->period ?? '#'.$i->id)],
        ];

        $options = [];

        foreach ($sources as $alias => [$class, $label]) {
            $options[$alias] = $class::query()
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn ($model) => ['id' => (int) $model->id, 'label' => trim($label($model))])
                ->values()
                ->all();
        }

        return $options;
    }
}
