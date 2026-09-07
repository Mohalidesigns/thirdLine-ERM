<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\AlertStatus;
use App\Enums\Tprm\SignalType;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Alert;
use App\Models\Tprm\AlertRule;
use App\Models\Tprm\MonitoringSignal;
use App\Models\Tprm\MonitoringSource;
use App\Models\Tprm\SanctionsList;
use App\Services\Tprm\Monitoring\AlertEngine;
use App\Services\Tprm\Monitoring\InternalSignalGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The monitoring console — FR-MON-04's stream, rules, source health and mute
 * register.
 *
 * THE SOURCE HEALTH PANEL LEADS WITH WHAT IS *NOT* WATCHED. A console showing
 * three green feeds is reassuring and misleading if the client has bought
 * three of a possible seven; the panel says which signal types nothing is
 * currently producing, because that is the gap an examiner asks about.
 *
 * THE INTERNAL GENERATOR IS SHOWN AS A SOURCE even though it has no row in
 * `tp_monitoring_sources`. It produces nine signal types with no subscription,
 * and a health panel that omitted it would tell a client with no data budget
 * that they are monitoring nothing — when in fact they are monitoring the nine
 * things their own register already knows.
 */
class MonitoringController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('tprm.monitoring.view');

        return Inertia::render('Tprm/Monitoring/Index', [
            'summary' => fn () => $this->summary(),
            'signals' => fn () => $this->signalStream($request),
            'alerts' => fn () => $this->alertStream(),
            'rules' => fn () => $this->rules(),
            'sourceHealth' => fn () => $this->sourceHealth(),
            'muteRegister' => fn () => $this->muteRegister(),
            'signalTypes' => collect(SignalType::cases())->map(fn (SignalType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'internal' => $type->isInternallyDerived(),
            ])->values(),
            'actions' => AlertRule::ACTIONS,
            'filters' => [
                'type' => $request->string('type')->toString() ?: null,
                'severity' => $request->string('severity')->toString() ?: null,
            ],
            'can' => [
                'manage' => $request->user()->can('tprm.monitoring.manage'),
            ],
        ]);
    }

    /** Derive internal signals and evaluate the rules, on demand. */
    public function run(Request $request, InternalSignalGenerator $generator, AlertEngine $engine)
    {
        Gate::authorize('tprm.monitoring.manage');

        $signals = $generator->generate($request->user()->organization_id);
        $alerts = $engine->process($request->user()->organization_id);

        return back()->with('success', sprintf(
            '%d new signal(s) derived and %d alert(s) fired. This runs nightly as well — the button is here '
            .'so a change made this morning can be seen now rather than tomorrow.',
            $signals->count(),
            $alerts->count(),
        ));
    }

    public function storeRule(Request $request)
    {
        Gate::authorize('tprm.monitoring.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'signal_types' => 'array',
            'signal_types.*' => 'string|max:40',
            'condition' => 'nullable|array',
            'scope' => 'nullable|array',
            'actions' => 'required|array|min:1',
            'actions.*' => 'in:'.implode(',', AlertRule::ACTIONS),
            'severity' => 'required|in:critical,high,medium,low,info',
            'cooldown_hours' => 'required|integer|min:0|max:8760',
        ]);

        AlertRule::create($validated + [
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'The rule was created and applies to signals from now on.');
    }

    public function updateRule(Request $request, AlertRule $rule)
    {
        Gate::authorize('tprm.monitoring.manage');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'signal_types' => 'array',
            'condition' => 'nullable|array',
            'scope' => 'nullable|array',
            'actions' => 'sometimes|array|min:1',
            'actions.*' => 'in:'.implode(',', AlertRule::ACTIONS),
            'severity' => 'sometimes|in:critical,high,medium,low,info',
            'is_enabled' => 'sometimes|boolean',
            'cooldown_hours' => 'sometimes|integer|min:0|max:8760',
        ]);

        $rule->fill($validated + ['updated_by' => $request->user()->id])->save();

        return back()->with('success', 'The rule was updated.');
    }

    public function acknowledge(Request $request, Alert $alert)
    {
        Gate::authorize('tprm.monitoring.view');

        $alert->forceFill([
            'status' => AlertStatus::Acknowledged->value,
            'acknowledged_at' => now(),
            'assigned_to' => $alert->assigned_to ?? $request->user()->id,
        ])->save();

        return back()->with('success', 'Acknowledged.');
    }

    /**
     * Mute an alert — with a reason and an expiry, both required.
     *
     * A mute with neither is how a monitoring programme quietly stops covering
     * what it claims to: somebody silences a noisy rule during an incident,
     * the incident ends, and two years later nobody knows why that vendor
     * produces no alerts.
     */
    public function mute(Request $request, Alert $alert)
    {
        Gate::authorize('tprm.monitoring.manage');

        $validated = $request->validate([
            'mute_reason' => 'required|string|min:15|max:1000',
            'muted_until' => 'required|date|after:today|before:'.now()->addYear()->toDateString(),
        ]);

        $alert->forceFill([
            'status' => AlertStatus::Muted->value,
            'mute_reason' => $validated['mute_reason'],
            'muted_until' => $validated['muted_until'],
        ])->save();

        return back()->with('success', sprintf(
            'Muted until %s. It appears on the mute register until then, and comes back by itself afterwards.',
            $alert->muted_until?->toFormattedDateString(),
        ));
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return [
            'signals_30d' => MonitoringSignal::query()->since(30)->count(),
            'internal_30d' => MonitoringSignal::query()->since(30)->internal()->count(),
            'open_alerts' => Alert::query()->open()->count(),
            'critical_alerts' => Alert::query()->open()->where('severity', 'critical')->count(),
            'muted' => Alert::query()->muted()->count(),
            'rules' => AlertRule::query()->enabled()->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function signalStream(Request $request): array
    {
        return MonitoringSignal::query()
            ->with(['thirdParty:id,legal_name,uuid,slug', 'engagement:id,uuid,reference', 'source:id,name'])
            ->when($request->filled('type'), fn ($q) => $q->where('signal_type', $request->string('type')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->orderByDesc('observed_at')
            ->limit(100)
            ->get()
            ->map(fn (MonitoringSignal $signal): array => [
                'id' => $signal->getKey(),
                'type' => $signal->signal_type->value,
                'type_label' => $signal->signal_type->label(),
                'internal' => $signal->source_id === null,
                'source' => $signal->source?->name,
                'severity' => $signal->severity->value,
                'title' => $signal->title,
                'third_party' => $signal->thirdParty?->legal_name,
                'engagement' => $signal->engagement?->reference,
                'observed_at' => $signal->observed_at?->toDayDateTimeString(),
                'processed' => $signal->is_processed,
                'payload' => $signal->payload,
                // Null means NOT ASSESSED, never "not relevant" — the screen
                // has to be able to tell those apart.
                'ai_relevance' => $signal->ai_relevance === null ? null : (float) $signal->ai_relevance,
                'ai_materiality' => $signal->ai_materiality,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function alertStream(): array
    {
        return Alert::query()
            ->with(['rule:id,name', 'signal:id,title,signal_type', 'thirdParty:id,legal_name', 'assignee:id,name'])
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->getKey(),
                'rule' => $alert->rule?->name,
                'signal' => $alert->signal?->title,
                'third_party' => $alert->thirdParty?->legal_name,
                'severity' => $alert->severity->value,
                'status' => $alert->status->value,
                'status_label' => $alert->status->label(),
                'assignee' => $alert->assignee?->name,
                'muted_until' => $alert->muted_until?->toDateString(),
                'mute_reason' => $alert->mute_reason,
                // What actually happened, per action — not what the rule asked
                // for. A console showing a green tick over a half-completed
                // response is worse than one that says which half failed.
                'actions_taken' => $alert->actions_taken ?? [],
                'finding_id' => $alert->created_finding_id,
                'assessment_id' => $alert->created_assessment_id,
                'created_at' => $alert->created_at?->toDayDateTimeString(),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        return AlertRule::query()
            ->withCount('alerts')
            ->orderBy('name')
            ->get()
            ->map(fn (AlertRule $rule): array => [
                'id' => $rule->getKey(),
                'name' => $rule->name,
                'signal_types' => $rule->signal_types ?? [],
                'watches_everything' => empty($rule->signal_types),
                'actions' => $rule->actionList(),
                'severity' => $rule->severity->value,
                'enabled' => $rule->is_enabled,
                'cooldown_hours' => $rule->cooldown_hours,
                'has_condition' => ! empty($rule->condition),
                'has_scope' => ! empty($rule->scope),
                'fired' => $rule->alerts_count,
            ])->values()->all();
    }

    /**
     * Which signal types anything is actually producing.
     *
     * @return array<string, mixed>
     */
    private function sourceHealth(): array
    {
        $sources = MonitoringSource::query()->get();

        $covered = collect(SignalType::cases())
            ->filter(fn (SignalType $type) => $type->isInternallyDerived())
            ->map(fn (SignalType $type) => $type->value);

        foreach ($sources->where('is_enabled', true) as $source) {
            $covered = $covered->merge((array) ($source->signal_types ?? []));
        }

        $uncovered = collect(SignalType::cases())
            ->reject(fn (SignalType $type) => $covered->contains($type->value))
            ->map(fn (SignalType $type) => ['value' => $type->value, 'label' => $type->label()])
            ->values();

        return [
            // Listed as a source even though it has no row: it produces nine
            // signal types with no subscription, and a panel that omitted it
            // would tell a client with no data budget that they are monitoring
            // nothing.
            'internal' => [
                'name' => 'Internal derivation',
                'enabled' => true,
                'credentials_set' => true,
                'last_run_at' => MonitoringSignal::query()->internal()->max('ingested_at'),
                'last_status' => MonitoringSource::STATUS_OK,
                'stale' => false,
                'signal_types' => collect(SignalType::cases())
                    ->filter(fn (SignalType $type) => $type->isInternallyDerived())
                    ->map(fn (SignalType $type) => $type->value)->values()->all(),
                'note' => 'Derived nightly from the register itself. Needs no subscription and cannot go down.',
            ],
            'external' => $sources->map(fn (MonitoringSource $source) => $source->healthReport())->values()->all(),
            // The gap an examiner asks about.
            'uncovered_signal_types' => $uncovered->all(),
            'sanctions_lists' => SanctionsList::query()->active()->get()
                ->map(fn (SanctionsList $list) => $list->healthReport())->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function muteRegister(): array
    {
        return Alert::query()
            ->muted()
            ->with(['rule:id,name', 'thirdParty:id,legal_name'])
            ->orderBy('muted_until')
            ->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->getKey(),
                'rule' => $alert->rule?->name,
                'third_party' => $alert->thirdParty?->legal_name,
                'reason' => $alert->mute_reason,
                'until' => $alert->muted_until?->toDateString(),
                'days_remaining' => $alert->muted_until === null
                    ? null
                    : (int) now()->startOfDay()->diffInDays($alert->muted_until, false),
            ])->values()->all();
    }
}
