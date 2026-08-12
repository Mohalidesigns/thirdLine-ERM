@extends('layouts.app')

@php
    $editing = $assessment !== null;
    $action = $editing ? route('risk.assessments.update', $assessment) : route('risk.assessments.store');

    // The thirteen steps, grouped into the five stages a person actually works
    // in. The numbers are kept visible because the chain is the point.
    $stages = [
        1 => ['label' => 'Context & Cause', 'steps' => '1–2', 'icon' => 'account_tree'],
        2 => ['label' => 'Inherent Risk', 'steps' => '3–5', 'icon' => 'trending_up'],
        3 => ['label' => 'Controls', 'steps' => '6–7', 'icon' => 'shield'],
        4 => ['label' => 'Residual Risk', 'steps' => '8', 'icon' => 'shield_moon'],
        5 => ['label' => 'Treatment & Monitoring', 'steps' => '9–13', 'icon' => 'task_alt'],
    ];

    $dimensionMeta = [
        'financial' => ['Financial', 'Direct monetary loss or cost'],
        'operational' => ['Operational', 'Process disruption or service degradation'],
        'reputational' => ['Reputational', 'Brand damage, media coverage, customer trust'],
        'regulatory' => ['Regulatory', 'CBN sanctions, penalties, license risk'],
        'strategic' => ['Strategic', 'Effect on strategic objectives'],
    ];
@endphp

@section('title', ($editing ? 'Edit' : 'New').' Assessment - GRC Risk Management')
@section('page-section', 'Assessments')
@section('page-title', $editing ? 'Edit Assessment' : 'New Assessment')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.assessments.index') }}" class="hover:text-[#1A365D]">Assessments</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $risk->risk_code }}</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-red-600">error</span>
                <span class="text-sm font-semibold text-red-700">Please correct the following:</span>
            </div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-5 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">{{ $risk->risk_code }} — {{ $risk->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $risk->category->name ?? 'Uncategorised' }}
                @if ($risk->riskOwner) &middot; Owner: {{ $risk->riskOwner->name }} @endif
                @if ($previousAssessment)
                    &middot; Last assessed {{ $previousAssessment->assessment_date?->format('d M Y') }}
                    (inherent {{ $previousAssessment->overall_score }}, residual {{ $previousAssessment->residual_score ?? '—' }})
                @endif
            </p>
        </div>
        <a href="{{ route('risk.assessments.create') }}" class="shrink-0 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Change risk</a>
    </div>

    <form method="POST" action="{{ $action }}"
          x-data="assessmentChain(@js([
              'config' => $scoringConfig,
              'dimensions' => $dimensions,
              'causes' => old('causes', $causes->map(fn ($c) => [
                  'id' => $c->id,
                  'description' => $c->description,
                  'cause_category_id' => $c->cause_category_id,
                  'source' => $c->source,
                  'is_primary' => (bool) $c->is_primary,
              ])->values()->all()),
              'controls' => $controls->map(fn ($c) => array_merge($c, old('controls.'.$c['control_id'], [])))->all(),
              'likelihood' => (int) old('likelihood', $assessment->likelihood_score ?? 0),
              'impacts' => collect($dimensions)->mapWithKeys(fn ($d) => [
                  $d => (int) old('impact_'.$d, $assessment->{'impact_'.$d} ?? 0),
              ])->all(),
              'strategy' => old('treatment_strategy', $assessment->treatment_strategy ?? ''),
              'override' => (bool) old('override_residual', $assessment?->residualWasOverridden()),
              'residualLikelihood' => (int) old('residual_likelihood', $assessment->residual_likelihood ?? 0),
              'residualImpact' => (int) old('residual_impact', $assessment->residual_impact ?? 0),
              // Rebuilt from old() so a validation bounce does not throw away
              // action plans the assessor has already typed.
              'actions' => array_values(old('actions', [])),
              'kriIds' => array_map('intval', old('kri_ids', $linkedKris->pluck('id')->all())),
          ]))"
          @submit="onSubmit">
        @csrf
        @if ($editing) @method('PUT') @endif
        <input type="hidden" name="risk_id" value="{{ $risk->id }}">

        {{-- ------------------------------------------------------------- --}}
        {{--  The chain, as a stepper                                       --}}
        {{-- ------------------------------------------------------------- --}}
        <nav class="bg-white rounded-xl border border-gray-200 p-2 mb-6 flex overflow-x-auto" aria-label="Assessment stages">
            @foreach ($stages as $number => $meta)
                <button type="button" @click="stage = {{ $number }}"
                        :class="stage === {{ $number }} ? 'bg-[#F0F4F8] text-[#1A365D]' : 'text-gray-500 hover:bg-gray-50'"
                        class="flex-1 min-w-[9rem] flex items-center gap-2 px-3 py-2.5 rounded-lg text-left transition-colors">
                    <span class="material-symbols-outlined text-lg">{{ $meta['icon'] }}</span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium truncate">{{ $meta['label'] }}</span>
                        <span class="block text-[11px] text-gray-400">Step {{ $meta['steps'] }}</span>
                    </span>
                </button>
            @endforeach
        </nav>

        {{-- ============================================================= --}}
        {{--  Stage 1 — Steps 1-2: the risk and its root causes             --}}
        {{-- ============================================================= --}}
        <section x-show="stage === 1" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Assessment context</h2>
                <p class="text-sm text-gray-500 mb-5">Step 1 — the risk under assessment.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="assessment_date" class="block text-sm font-medium text-gray-700 mb-2">Assessment date <span class="text-red-500">*</span></label>
                        <input type="date" id="assessment_date" name="assessment_date" required
                               value="{{ old('assessment_date', $assessment?->assessment_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    <div>
                        <label for="assessment_type" class="block text-sm font-medium text-gray-700 mb-2">Assessment type</label>
                        <select id="assessment_type" name="assessment_type"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                            @foreach (['periodic' => 'Periodic review', 'initial' => 'Initial assessment', 'event_driven' => 'Event-driven', 'triggered' => 'Triggered', 'annual' => 'Annual'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('assessment_type', $assessment->assessment_type ?? 'periodic') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-start justify-between gap-4 mb-1">
                    <h2 class="text-lg font-semibold text-[#1A365D]">Root causes</h2>
                    <button type="button" @click="addCause()"
                            class="shrink-0 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">add</span> Add cause
                    </button>
                </div>
                <p class="text-sm text-gray-500 mb-5">
                    Step 2 — what actually drives this risk. Causes stay on the risk between assessments and
                    feed the bow-tie; this assessment records the set you reasoned about.
                </p>

                <template x-if="causes.length === 0">
                    <div class="text-center py-8 border-2 border-dashed border-gray-200 rounded-lg">
                        <span class="material-symbols-outlined text-3xl text-gray-300">psychology_alt</span>
                        <p class="text-sm text-gray-500 mt-2">No causes recorded yet.</p>
                        <button type="button" @click="addCause()" class="mt-3 text-sm text-[#1A365D] underline">Add the first one</button>
                    </div>
                </template>

                <div class="space-y-3">
                    <template x-for="(cause, index) in causes" :key="index">
                        <div class="border border-gray-200 rounded-lg p-4">
                            <input type="hidden" :name="`causes[${index}][id]`" :value="cause.id ?? ''">
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">
                                <div class="lg:col-span-6">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Cause <span class="text-red-500">*</span></label>
                                    <textarea :name="`causes[${index}][description]`" x-model="cause.description" rows="2" maxlength="2000"
                                              placeholder="e.g. Manual reconciliation with no maker-checker on high-value entries"
                                              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></textarea>
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                                    <select :name="`causes[${index}][cause_category_id]`" x-model="cause.cause_category_id"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                        <option value="">Unclassified</option>
                                        @foreach ($causeCategories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Source</label>
                                    <select :name="`causes[${index}][source]`" x-model="cause.source"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                        <option value="">Not stated</option>
                                        @foreach ($causeSources as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="flex items-center justify-between mt-3">
                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input type="hidden" :name="`causes[${index}][is_primary]`" :value="cause.is_primary ? 1 : 0">
                                    <input type="checkbox" x-model="cause.is_primary" @change="setPrimary(index)"
                                           class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                                    Primary cause
                                </label>
                                <button type="button" @click="causes.splice(index, 1)"
                                        class="text-xs text-red-600 hover:underline flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">delete</span> Remove from this assessment
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="button" @click="stage = 2" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Continue to inherent risk</button>
            </div>
        </section>

        {{-- ============================================================= --}}
        {{--  Stage 2 — Steps 3-5: likelihood, impact, inherent risk        --}}
        {{-- ============================================================= --}}
        <section x-show="stage === 2" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Likelihood</h2>
                <p class="text-sm text-gray-500 mb-5">Step 3 — how likely the risk is to materialise, before controls are considered.</p>

                <div class="flex flex-wrap gap-2">
                    @foreach ($likelihoodLabels as $score => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="likelihood" value="{{ $score }}" x-model.number="likelihood" class="sr-only peer" required>
                            <div class="px-4 py-2 border-2 border-gray-300 rounded-lg text-sm font-medium peer-checked:border-[#1A365D] peer-checked:bg-[#F0F4F8] peer-checked:text-[#1A365D] hover:border-[#1A365D]/50 transition-colors">
                                {{ $score }}: {{ $label }}
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="mt-5">
                    <label for="likelihood_rationale" class="block text-sm font-medium text-gray-700 mb-2">Why this score?</label>
                    <textarea id="likelihood_rationale" name="likelihood_rationale" rows="2" maxlength="2000"
                              placeholder="Frequency of past occurrences, exposure, trend…"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('likelihood_rationale', $assessment->likelihood_rationale ?? '') }}</textarea>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Impact</h2>
                <p class="text-sm text-gray-500 mb-5">
                    Step 4 — how bad it would be across each dimension your organisation scores.
                    These aggregate by <span class="font-medium">{{ $scoringConfig['aggregation'] }}</span>.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach ($dimensions as $dimension)
                        @php [$label, $hint] = $dimensionMeta[$dimension] ?? [Str::headline($dimension), null]; @endphp
                        <div>
                            <label for="impact_{{ $dimension }}" class="block text-sm font-semibold text-gray-700 mb-2">{{ $label }}</label>
                            <select id="impact_{{ $dimension }}" name="impact_{{ $dimension }}" x-model.number="impacts.{{ $dimension }}"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                <option value="0">Not scored</option>
                                @foreach ($impactLabels as $score => $impactLabel)
                                    <option value="{{ $score }}">{{ $score }}: {{ $impactLabel }}</option>
                                @endforeach
                            </select>
                            @if ($hint) <p class="text-xs text-gray-500 mt-1">{{ $hint }}</p> @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-[#F0F4F8] rounded-xl border border-[#1A365D]/20 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Inherent risk</h2>
                <p class="text-sm text-gray-600 mb-4">
                    Step 5 — calculated, not entered: likelihood × aggregated impact.
                </p>
                <div class="flex items-baseline gap-3">
                    <span class="text-4xl font-bold text-[#1A365D]" x-text="inherentScore || '—'"></span>
                    <span class="text-sm text-gray-600">/ {{ $scoringConfig['rows'] * $scoringConfig['cols'] }}</span>
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-white border border-gray-300" x-text="ratingFor(inherentScore) || 'Not scored'"></span>
                </div>
            </div>

            <div class="flex justify-between">
                <button type="button" @click="stage = 1" class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</button>
                <button type="button" @click="stage = 3" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Continue to controls</button>
            </div>
        </section>

        {{-- ============================================================= --}}
        {{--  Stage 3 — Steps 6-7: existing controls and their effectiveness --}}
        {{-- ============================================================= --}}
        <section x-show="stage === 3" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Existing controls</h2>
                <p class="text-sm text-gray-500 mb-5">
                    Steps 6 and 7 — the controls mapped to this risk, rated as they stand today.
                    <span class="font-medium">Design</span> is whether the control would work if performed as written;
                    <span class="font-medium">operating</span> is whether it is actually being performed. The weaker of the two
                    is what the risk is really carrying, so that is what counts.
                </p>

                @if ($controls->isEmpty())
                    <div class="text-center py-10 border-2 border-dashed border-amber-200 bg-amber-50/50 rounded-lg">
                        <span class="material-symbols-outlined text-3xl text-amber-500">shield_question</span>
                        <p class="text-sm font-medium text-amber-800 mt-2">No controls are mapped to this risk.</p>
                        <p class="text-xs text-amber-700 mt-1 max-w-md mx-auto">
                            Residual risk cannot be derived without them, so it will equal inherent risk unless you
                            override it below with a documented reason.
                        </p>
                        <a href="{{ route('risk.register.show', $risk) }}" class="inline-block mt-3 text-sm text-[#1A365D] underline">Map controls to this risk</a>
                    </div>
                @else
                    <div class="overflow-x-auto -mx-6 px-6">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                    <th class="pb-2 pr-3 font-medium">Control</th>
                                    <th class="pb-2 px-3 font-medium">Acts on</th>
                                    <th class="pb-2 px-3 font-medium">Design</th>
                                    <th class="pb-2 px-3 font-medium">Operating</th>
                                    <th class="pb-2 pl-3 font-medium text-right">Effective</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="(control, index) in controls" :key="control.control_id">
                                    <tr>
                                        <td class="py-3 pr-3 align-top">
                                            <div class="flex items-start gap-2">
                                                <span x-show="control.is_key_control" title="Key control"
                                                      class="material-symbols-outlined text-base text-[#D4AF37] shrink-0">star</span>
                                                <div class="min-w-0">
                                                    <p class="font-medium text-gray-900" x-text="control.control_name"></p>
                                                    <p class="text-xs text-gray-500">
                                                        <span class="font-mono" x-text="control.control_code"></span>
                                                        <span x-show="control.control_weight != 1"> &middot; weight <span x-text="control.control_weight"></span></span>
                                                        <template x-if="control.last_test_date">
                                                            <span> &middot; last tested <span x-text="control.last_test_date?.slice(0, 10)"></span></span>
                                                        </template>
                                                    </p>
                                                    <p x-show="changedSincePrior(control)" class="text-xs text-amber-600 mt-0.5">
                                                        Changed since the last assessment
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 align-top">
                                            <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600"
                                                  x-text="control.axis === 'likelihood' ? 'Likelihood' : 'Impact'"></span>
                                        </td>
                                        <td class="py-3 px-3 align-top">
                                            <select :name="`controls[${control.control_id}][design_effectiveness]`" x-model="control.design_effectiveness"
                                                    class="w-full min-w-[10rem] px-2 py-1.5 border border-gray-300 rounded-lg text-sm">
                                                <option value="">Not rated</option>
                                                @foreach ($effectivenessRatings as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-3 px-3 align-top">
                                            <select :name="`controls[${control.control_id}][operating_effectiveness]`" x-model="control.operating_effectiveness"
                                                    class="w-full min-w-[10rem] px-2 py-1.5 border border-gray-300 rounded-lg text-sm">
                                                <option value="">Not rated</option>
                                                @foreach ($effectivenessRatings as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-3 pl-3 align-top text-right">
                                            <span class="font-semibold text-gray-900" x-text="controlPct(control) === null ? '—' : controlPct(control) + '%'"></span>
                                            <p x-show="finding(control)" class="text-xs text-amber-600 mt-0.5" x-text="finding(control)"></p>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex flex-wrap items-baseline gap-x-8 gap-y-2">
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Overall effectiveness</p>
                                <p class="text-2xl font-bold text-[#1A365D]" x-text="effectiveness.overall === null ? '—' : effectiveness.overall + '%'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Reducing likelihood</p>
                                <p class="text-lg font-semibold text-gray-700" x-text="effectiveness.likelihood === null ? '—' : effectiveness.likelihood + '%'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Reducing impact</p>
                                <p class="text-lg font-semibold text-gray-700" x-text="effectiveness.impact === null ? '—' : effectiveness.impact + '%'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Rated</p>
                                <p class="text-lg font-semibold text-gray-700"><span x-text="effectiveness.rated"></span> of <span x-text="controls.length"></span></p>
                            </div>
                        </div>
                        <p x-show="effectiveness.unratedKeyControls > 0" x-cloak class="mt-3 text-sm text-amber-700 flex items-center gap-2">
                            <span class="material-symbols-outlined text-base">warning</span>
                            <span><span x-text="effectiveness.unratedKeyControls"></span> key control(s) unrated — the residual score will not account for them.</span>
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex justify-between">
                <button type="button" @click="stage = 2" class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</button>
                <button type="button" @click="stage = 4" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Continue to residual risk</button>
            </div>
        </section>

        {{-- ============================================================= --}}
        {{--  Stage 4 — Step 8: residual risk                               --}}
        {{-- ============================================================= --}}
        <section x-show="stage === 4" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Residual risk</h2>
                <p class="text-sm text-gray-500 mb-5">
                    Step 8 — what remains after the controls above. Derived from inherent risk and control
                    effectiveness through your organisation's scoring profile, and recalculated on save.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Inherent</p>
                        <p class="text-3xl font-bold text-[#1A365D] mt-1" x-text="inherentScore || '—'"></p>
                        <p class="text-xs text-gray-500 mt-1">
                            L<span x-text="likelihood || '—'"></span> × I<span x-text="impactScore || '—'"></span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 flex flex-col justify-center items-center">
                        <span class="material-symbols-outlined text-gray-400">arrow_forward</span>
                        <p class="text-xs text-gray-500 mt-1 text-center">
                            less <span class="font-semibold" x-text="effectiveness.overall === null ? 'no rated controls' : effectiveness.overall + '% control effectiveness'"></span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Residual</p>
                        <p class="text-3xl font-bold text-[#1A365D] mt-1" x-text="residual.score || '—'"></p>
                        <p class="text-xs text-gray-500 mt-1">
                            L<span x-text="residual.likelihood || '—'"></span> × I<span x-text="residual.impact || '—'"></span>
                            &middot; <span x-text="ratingFor(residual.score) || 'not derivable'"></span>
                        </p>
                    </div>
                </div>

                <p x-show="effectiveness.overall === null" x-cloak class="mt-4 text-sm text-amber-700 flex items-start gap-2">
                    <span class="material-symbols-outlined text-base">info</span>
                    <span>No control has been rated, so residual risk cannot be derived. Rate the controls in the previous stage, or override below and say why.</span>
                </p>

                <div class="mt-6 border-t border-gray-200 pt-5">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="override_residual" value="1" x-model="override"
                               class="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Override the derived residual score</span>
                            <span class="block text-xs text-gray-500 mt-0.5">
                                Expert judgement is a legitimate input. Recorded as an override so a reviewer can see
                                which residual scores are arithmetic and which are judgement.
                            </span>
                        </span>
                    </label>

                    <div x-show="override" x-cloak class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="residual_likelihood" class="block text-sm font-medium text-gray-700 mb-2">Residual likelihood</label>
                            <select id="residual_likelihood" name="residual_likelihood" x-model.number="residualLikelihood" :disabled="!override"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                <option value="0">Select</option>
                                @foreach ($likelihoodLabels as $score => $label)
                                    <option value="{{ $score }}">{{ $score }}: {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="residual_impact" class="block text-sm font-medium text-gray-700 mb-2">Residual impact</label>
                            <select id="residual_impact" name="residual_impact" x-model.number="residualImpact" :disabled="!override"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                <option value="0">Select</option>
                                @foreach ($impactLabels as $score => $label)
                                    <option value="{{ $score }}">{{ $score }}: {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label for="residual_justification" class="block text-sm font-medium text-gray-700 mb-2">
                                Justification <span class="text-red-500">*</span>
                            </label>
                            <textarea id="residual_justification" name="residual_justification" rows="3" maxlength="2000" :disabled="!override"
                                      placeholder="What does the derived score miss? Compensating controls, recent events, expert judgement…"
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('residual_justification', $assessment->residual_justification ?? '') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-between">
                <button type="button" @click="stage = 3" class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</button>
                <button type="button" @click="stage = 5" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Continue to treatment</button>
            </div>
        </section>

        {{-- ============================================================= --}}
        {{--  Stage 5 — Steps 9-13: treatment, actions, owner, date, KRI    --}}
        {{-- ============================================================= --}}
        <section x-show="stage === 5" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Risk treatment</h2>
                <p class="text-sm text-gray-500 mb-5">Step 9 — the response to the residual risk you have just derived.</p>

                <div class="space-y-2">
                    @foreach ($strategies as $value => $label)
                        @php [$name, $explanation] = array_pad(explode(' — ', $label, 2), 2, ''); @endphp
                        <label class="flex items-start gap-3 p-3 border-2 rounded-lg cursor-pointer transition-colors"
                               :class="strategy === '{{ $value }}' ? 'border-[#1A365D] bg-[#F0F4F8]' : 'border-gray-200 hover:border-gray-300'">
                            <input type="radio" name="treatment_strategy" value="{{ $value }}" x-model="strategy"
                                   class="mt-0.5 border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                            <span>
                                <span class="block text-sm font-medium text-gray-800">{{ Str::headline($name) }}</span>
                                <span class="block text-xs text-gray-500">{{ $explanation }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-start justify-between gap-4 mb-1">
                    <h2 class="text-lg font-semibold text-[#1A365D]">Action plan</h2>
                    <button type="button" @click="addAction()"
                            class="shrink-0 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">add</span> Add action
                    </button>
                </div>
                <p class="text-sm text-gray-500 mb-5">
                    Steps 10, 11 and 12 — what will be done, by whom, by when. These become treatment plans in the
                    action register, so they appear in the owner's responsibilities and in overdue reporting.
                </p>

                @if ($actionPlans->isNotEmpty())
                    <div class="mb-5 rounded-lg border border-gray-200 divide-y divide-gray-100">
                        <p class="px-4 py-2 text-xs uppercase tracking-wide text-gray-500 bg-gray-50">Already open on this risk</p>
                        @foreach ($actionPlans as $plan)
                            <div class="px-4 py-3 flex items-center gap-3 text-sm">
                                <span class="font-mono text-xs text-gray-500">{{ $plan->treatment_code }}</span>
                                <span class="flex-1 min-w-0 truncate text-gray-800">{{ $plan->action_title }}</span>
                                <span class="text-xs text-gray-500">{{ $plan->owner?->name ?? 'Unassigned' }}</span>
                                <span class="text-xs text-gray-500">{{ $plan->target_date?->format('d M Y') ?? 'No date' }}</span>
                                <x-status-badge :status="$plan->status" />
                            </div>
                        @endforeach
                    </div>
                @endif

                <template x-if="actions.length === 0">
                    <div class="text-center py-8 border-2 border-dashed border-gray-200 rounded-lg">
                        <span class="material-symbols-outlined text-3xl text-gray-300">checklist</span>
                        <p class="text-sm text-gray-500 mt-2">No new actions raised by this assessment.</p>
                        <p class="text-xs text-gray-400 mt-1" x-show="strategy && strategy !== 'accept'">
                            A <span x-text="strategy"></span> strategy usually implies at least one action.
                        </p>
                    </div>
                </template>

                <div class="space-y-3">
                    <template x-for="(item, index) in actions" :key="index">
                        <div class="border border-gray-200 rounded-lg p-4 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Action <span class="text-red-500">*</span></label>
                                    <input type="text" :name="`actions[${index}][action_title]`" x-model="item.action_title" maxlength="200"
                                           placeholder="e.g. Implement maker-checker on entries above ₦5m"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <button type="button" @click="actions.splice(index, 1)" class="mt-6 text-red-600 hover:text-red-700">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Detail</label>
                                <textarea :name="`actions[${index}][action_description]`" x-model="item.action_description" rows="2" maxlength="5000"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></textarea>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Owner <span class="text-red-500">*</span></label>
                                    <select :name="`actions[${index}][owner_id]`" x-model="item.owner_id"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                        <option value="">Select owner</option>
                                        @foreach ($users as $user)
                                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Due date <span class="text-red-500">*</span></label>
                                    <input type="date" :name="`actions[${index}][target_date]`" x-model="item.target_date"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Priority</label>
                                    <select :name="`actions[${index}][priority]`" x-model="item.priority"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                        @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-1">Key risk indicators</h2>
                <p class="text-sm text-gray-500 mb-5">
                    Step 13 — what will tell you this risk is moving before it materialises again.
                </p>

                @if ($linkedKris->isEmpty() && $availableKris->isEmpty())
                    <div class="text-center py-8 border-2 border-dashed border-amber-200 bg-amber-50/50 rounded-lg">
                        <span class="material-symbols-outlined text-3xl text-amber-500">monitoring</span>
                        <p class="text-sm font-medium text-amber-800 mt-2">No KRI is monitoring this risk.</p>
                        <p class="text-xs text-amber-700 mt-1">An assessment without a forward indicator is a snapshot, not monitoring.</p>
                        <a href="{{ route('risk.kri.create', ['risk_id' => $risk->id]) }}" class="inline-block mt-3 text-sm text-[#1A365D] underline">Define a KRI for this risk</a>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($linkedKris->concat($availableKris) as $kri)
                            <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                                   :class="kriIds.includes({{ $kri->id }}) ? 'border-[#1A365D] bg-[#F0F4F8]' : 'border-gray-200'">
                                <input type="checkbox" name="kri_ids[]" value="{{ $kri->id }}" x-model.number="kriIds"
                                       class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                                <span class="font-mono text-xs text-gray-500">{{ $kri->kri_code }}</span>
                                <span class="flex-1 min-w-0 truncate text-sm text-gray-800">{{ $kri->name }}</span>
                                @if ($kri->risk_id === $risk->id)
                                    <span class="text-xs text-gray-500">already linked</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    <a href="{{ route('risk.kri.create', ['risk_id' => $risk->id]) }}" class="inline-block mt-4 text-sm text-[#1A365D] underline">Define a new KRI for this risk</a>
                @endif
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-[#1A365D] mb-4">Assessment rationale</h2>
                <div class="space-y-5">
                    <div>
                        <label for="rationale" class="block text-sm font-medium text-gray-700 mb-2">Rationale <span class="text-red-500">*</span></label>
                        <textarea id="rationale" name="rationale" rows="4" required maxlength="5000"
                                  placeholder="The reasoning behind the scoring, the control conclusions and the treatment decision…"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('rationale', $assessment?->assessment_notes) }}</textarea>
                    </div>
                    <div>
                        <label for="recommendations" class="block text-sm font-medium text-gray-700 mb-2">Recommendations</label>
                        <textarea id="recommendations" name="recommendations" rows="3" maxlength="5000"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('recommendations') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <button type="button" @click="stage = 4" class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</button>
                <div class="flex gap-2">
                    <a href="{{ route('risk.assessments.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</a>
                    <button type="submit" name="action" value="draft" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Save draft</button>
                    <button type="submit" name="action" value="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">send</span> Submit assessment
                    </button>
                </div>
            </div>
        </section>
    </form>
@endsection

@push('scripts')
<script>
/**
 * The running totals on the assessment form.
 *
 * These mirror RiskScoringService and AssessmentChainService so the numbers move
 * as the assessor works, but the server recomputes everything on save and its
 * answer is the one that is stored. Where a tenant has configured a custom
 * residual formula, this is an approximation and the form says so.
 */
function assessmentChain(initial) {
    return {
        stage: 1,
        config: initial.config,
        dimensions: initial.dimensions,
        causes: initial.causes,
        controls: initial.controls.map(control => ({
            ...control,
            design_effectiveness: control.design_effectiveness ?? '',
            operating_effectiveness: control.operating_effectiveness ?? '',
        })),
        likelihood: initial.likelihood,
        impacts: initial.impacts,
        strategy: initial.strategy,
        override: initial.override,
        residualLikelihood: initial.residualLikelihood,
        residualImpact: initial.residualImpact,
        actions: initial.actions.map(item => ({
            action_title: item.action_title ?? '',
            action_description: item.action_description ?? '',
            owner_id: item.owner_id ?? '',
            target_date: item.target_date ?? '',
            priority: item.priority ?? 'medium',
        })),
        kriIds: initial.kriIds,

        /* --- Step 4: impact aggregation ------------------------------- */
        get impactScore() {
            const scored = this.dimensions
                .map(dimension => Number(this.impacts[dimension]) || 0)
                .filter(value => value > 0);

            if (scored.length === 0) return 0;

            switch (this.config.aggregation) {
                case 'average':
                    return Math.round(scored.reduce((a, b) => a + b, 0) / scored.length);
                case 'worst_two': {
                    const top = [...scored].sort((a, b) => b - a).slice(0, 2);
                    return Math.round(top.reduce((a, b) => a + b, 0) / top.length);
                }
                case 'weighted': {
                    let weight = 0, total = 0;
                    this.dimensions.forEach(dimension => {
                        const value = Number(this.impacts[dimension]) || 0;
                        if (value <= 0) return;
                        const w = Number(this.config.weights[dimension] ?? 1);
                        weight += w;
                        total += w * value;
                    });
                    return weight > 0 ? Math.round(total / weight) : 0;
                }
                default:
                    return Math.max(...scored);
            }
        },

        /* --- Step 5: inherent risk ------------------------------------ */
        get inherentScore() {
            const likelihood = Math.min(Number(this.likelihood) || 0, this.config.rows);
            const impact = Math.min(this.impactScore, this.config.cols);
            return likelihood * impact;
        },

        /* --- Steps 6-7: control effectiveness ------------------------- */
        controlPct(control) {
            const map = this.config.effectiveness;
            const values = [control.design_effectiveness, control.operating_effectiveness]
                .filter(rating => rating && map[rating] !== undefined)
                .map(rating => Number(map[rating]));

            // The weaker of design and operating, matching the server.
            return values.length ? Math.min(...values) : null;
        },

        finding(control) {
            const map = this.config.effectiveness;
            const design = map[control.design_effectiveness];
            const operating = map[control.operating_effectiveness];
            if (design === undefined || operating === undefined) return null;
            if (design - operating >= 20) return 'Not performed as designed';
            if (operating - design >= 20) return 'Weak design';
            return null;
        },

        changedSincePrior(control) {
            if (!control.prior_design && !control.prior_operating) return false;
            return control.design_effectiveness !== control.prior_design
                || control.operating_effectiveness !== control.prior_operating;
        },

        aggregate(rows) {
            let weight = 0, total = 0;
            rows.forEach(control => {
                const pct = this.controlPct(control);
                if (pct === null) return;
                const w = Number(control.control_weight) || 1;
                if (w <= 0) return;
                weight += w;
                total += w * pct;
            });
            return weight > 0 ? Math.round((total / weight) * 100) / 100 : null;
        },

        get effectiveness() {
            return {
                overall: this.aggregate(this.controls),
                likelihood: this.aggregate(this.controls.filter(c => c.axis === 'likelihood')),
                impact: this.aggregate(this.controls.filter(c => c.axis === 'impact')),
                rated: this.controls.filter(c => this.controlPct(c) !== null).length,
                unratedKeyControls: this.controls.filter(c => c.is_key_control && this.controlPct(c) === null).length,
            };
        },

        /* --- Step 8: residual risk ------------------------------------ */
        get residual() {
            if (this.override) {
                const likelihood = Number(this.residualLikelihood) || 0;
                const impact = Number(this.residualImpact) || 0;
                return { likelihood, impact, score: likelihood * impact };
            }

            const overall = this.effectiveness.overall;
            const inherent = this.inherentScore;

            if (overall === null || inherent <= 0) {
                return { likelihood: 0, impact: 0, score: 0 };
            }

            // The platform default formula gives the target the score should
            // fall to. A tenant-configured formula is applied server-side; this
            // preview does not attempt to evaluate it.
            const target = Math.max(1, Math.round(inherent * (1 - overall / 100)));
            const reduction = Math.min(1, Math.max(0, target / inherent));

            const byLikelihood = this.effectiveness.likelihood ?? 0;
            const byImpact = this.effectiveness.impact ?? 0;
            const total = byLikelihood + byImpact;
            const split = total > 0 ? Math.min(0.85, Math.max(0.15, byLikelihood / total)) : 0.5;

            const clamp = (value, max) => Math.max(1, Math.min(max, Math.round(value)));

            const likelihood = clamp((Number(this.likelihood) || 0) * Math.pow(reduction, split), this.config.rows);
            const impact = clamp(this.impactScore * Math.pow(reduction, 1 - split), this.config.cols);

            // Score recomputed from the cell, so it always equals L × I.
            return { likelihood, impact, score: likelihood * impact };
        },

        ratingFor(score) {
            if (!score) return null;
            const band = (this.config.bands || []).find(b => score >= (b.min ?? -Infinity) && score <= (b.max ?? Infinity));
            return band?.label ?? null;
        },

        /* --- Editing helpers ------------------------------------------ */
        addCause() {
            this.causes.push({ id: null, description: '', cause_category_id: '', source: '', is_primary: false });
        },

        setPrimary(index) {
            if (!this.causes[index].is_primary) return;
            this.causes.forEach((cause, i) => { if (i !== index) cause.is_primary = false; });
        },

        addAction() {
            this.actions.push({ action_title: '', action_description: '', owner_id: '', target_date: '', priority: 'medium' });
        },

        /**
         * Jump to the stage holding the problem rather than failing silently on
         * a panel the assessor cannot see.
         */
        onSubmit(event) {
            const invalid = event.target.querySelector(':invalid');

            if (invalid) {
                const section = invalid.closest('section[x-show]');
                const match = section?.getAttribute('x-show')?.match(/stage === (\d)/);
                if (match) this.stage = Number(match[1]);
                return;
            }

            if (this.override && !event.target.residual_justification.value.trim()) {
                event.preventDefault();
                this.stage = 4;
                event.target.residual_justification.focus();
            }
        },
    };
}
</script>
@endpush
