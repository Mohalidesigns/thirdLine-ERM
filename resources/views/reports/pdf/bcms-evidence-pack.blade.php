@extends('reports.pdf.layout')

{{--
    The regulator evidence pack — one section per clause, in the order
    ClauseComplianceMatrixService returns them (already clause-ordered).

    EVERY SECTION PRINTS, WHATEVER ITS STATE. A red or amber section prints
    its own honest sentence in place of an artefact — omitting it would be
    the false completeness phase-11-spec criterion 12 exists to catch.
--}}

@section('body')

    <div class="section-block">
        <a name="index"></a>
        <h1 class="section">{{ $framework_label }}</h1>

        <table class="data">
            <tbody>
                <tr><td style="width: 45mm;"><strong>Organisation</strong></td><td>{{ $organization?->name }}</td></tr>
                <tr><td><strong>Period</strong></td><td>{{ $from }} to {{ $to }}</td></tr>
                <tr><td><strong>Generated</strong></td><td>{{ now()->toDayDateTimeString() }}</td></tr>
                <tr><td><strong>Sections</strong></td><td>{{ count($packSections) }}</td></tr>
            </tbody>
        </table>
        @if (! empty($obligationsCurrentState))
            {{-- QA ruling on the build() programme picker: bcms_programme_obligations carries no dated
                 applicability history, so WHICH obligations govern a past pack is bound, but WHAT each
                 one currently says (applies, its rationale) is not — labelled the same way dr_systems is. --}}
            <p><em>Applicability and exclusion rationales are shown as currently recorded, not reconstructed for the period.</em></p>
        @endif
    </div>

    <div class="section-block">
        <h1 class="section">Index — clause, state, page</h1>
        <table class="data">
            <thead>
                <tr><th>Clause</th><th>Title</th><th>State</th></tr>
            </thead>
            <tbody>
                @foreach ($packSections as $i => $section)
                    <tr>
                        <td>{{ $section['code'] }}</td>
                        <td>{{ $section['title'] }}</td>
                        <td>{{ ucfirst($section['state']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @foreach ($packSections as $section)
        <div class="section-block">
            <a name="sec-{{ $section['code'] }}"></a>
            <h1 class="section">{{ $section['code'] }} — {{ $section['title'] }}</h1>
            <p>
                <strong>State:</strong> {{ ucfirst($section['state']) }}
                @if ($section['mandatory'])
                    &mdash; <strong>mandatory documented record</strong>
                @endif
            </p>
            <p>{{ $section['artefact'] }}</p>
            @if (! empty($section['last_evidenced']))
                <p><em>Last evidenced: {{ $section['last_evidenced'] }}</em></p>
            @endif
            {{--
                R3 follow-up (gate 1 code review #2): a row whose denominator
                cannot be reconstructed for a past period (which processes
                are Tier 1/critical RIGHT NOW — no history table exists) is
                labelled exactly the way `cbnContent()`'s own `dr_systems`
                block is, rather than presented as though it were
                reconstructed for the period on the cover.
            --}}
            @if (! empty($section['current_state']))
                {{-- QA re-gate #11 item 3: a row can be current-state for more
                     than one reason (the Process catalogue's own denominator,
                     AAR finalisation being reversible with no trace) — a
                     per-row reason string, when the service supplies one,
                     replaces the generic sentence rather than sitting beside it. --}}
                <p><em>{{ $section['current_state_reason'] ?? 'Current state as at '.now()->toDateString().', not reconstructed for the period.' }}</em></p>
            @endif
        </div>
    @endforeach

    {{--
        B15 (gate 1 code review #1) — criterion 2's own examiner test: "show
        me your last DR test report and the corrective actions arising".
    --}}
    <div class="section-block">
        <a name="dr-test-report"></a>
        <h1 class="section">DR test report and corrective-action chain</h1>
        @if ($drTestReport === null)
            <p>No DR test is on file as at {{ $periodAsAt->toDateString() }}.</p>
        @else
            <table class="data">
                <tbody>
                    <tr><td style="width: 45mm;"><strong>Test type</strong></td><td>{{ $drTestReport['test_type'] }}</td></tr>
                    <tr><td><strong>Test date</strong></td><td>{{ $drTestReport['test_date'] }}</td></tr>
                    <tr><td><strong>RTO actual</strong></td><td>{{ $drTestReport['rto_actual_minutes'] ?? '—' }} minute(s)</td></tr>
                    <tr><td><strong>RPO actual</strong></td><td>{{ $drTestReport['rpo_actual_minutes'] ?? '—' }} minute(s)</td></tr>
                    <tr><td><strong>Met objectives</strong></td><td>{{ $drTestReport['met_objectives'] === null ? '—' : ($drTestReport['met_objectives'] ? 'Yes' : 'No') }}</td></tr>
                    <tr><td><strong>Rollback required</strong></td><td>{{ $drTestReport['rollback_required'] ? 'Yes' : 'No' }}</td></tr>
                </tbody>
            </table>

            @if ($drTestReport['finding'] === null)
                <p>No finding is recorded against this test.</p>
            @else
                <p><strong>Finding:</strong> {{ $drTestReport['finding']['reference'] }} — {{ $drTestReport['finding']['description'] }} ({{ $drTestReport['finding']['status'] }})</p>
                @if (! empty($drTestReport['finding']['current_state']))
                    <p><em>{{ $drTestReport['finding']['current_state_reason'] }}</em></p>
                @endif

                @if (empty($drTestReport['corrective_actions']))
                    <p>No corrective action has been raised from this finding.</p>
                @else
                    <table class="data">
                        <thead><tr><th>Action</th><th>Status</th><th>Due</th><th>Completed</th><th>Age (days)</th></tr></thead>
                        <tbody>
                            @foreach ($drTestReport['corrective_actions'] as $action)
                                <tr>
                                    <td>{{ $action['title'] }}</td>
                                    <td>{{ $action['status'] }}</td>
                                    <td>{{ $action['due_date'] ?? '—' }}</td>
                                    <td>{{ $action['completed_at'] ?? '—' }}</td>
                                    <td>{{ $action['age_days'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (! empty($drTestReport['corrective_actions'][0]['current_state']))
                        <p><em>{{ $drTestReport['corrective_actions'][0]['current_state_reason'] }}</em></p>
                    @endif
                @endif
            @endif
        @endif
    </div>

    {{-- §3.3 — the CBN CSF pack's own examiner order, over content the standard clause sections above do not already carry. --}}
    @if (! empty($cbnContent))
        <div class="section-block">
            <a name="cbn-dr-register"></a>
            <h1 class="section">CBN §3.3(1) — DR/failover system register</h1>
            @if (! empty($cbnContent['dr_systems_label']))
                <p><em>{{ $cbnContent['dr_systems_label'] }}</em></p>
            @endif
            <table class="data">
                <thead><tr><th>System</th><th>RTO target (h)</th><th>RPO target (min)</th><th>Last test</th><th>Last RTO actual (min)</th><th>Met objectives</th><th>Next due</th><th>Overdue</th></tr></thead>
                <tbody>
                    @forelse ($cbnContent['dr_systems'] as $system)
                        <tr>
                            <td>{{ $system['name'] }}</td>
                            <td>{{ $system['rto_target_hours'] ?? '—' }}</td>
                            <td>{{ $system['rpo_target_minutes'] ?? '—' }}</td>
                            <td>{{ $system['last_test_date'] ?? '—' }}</td>
                            <td>{{ $system['last_test_rto_actual_minutes'] ?? '—' }}</td>
                            <td>{{ $system['last_test_met_objectives'] === null ? '—' : ($system['last_test_met_objectives'] ? 'Yes' : 'No') }}</td>
                            <td>{{ $system['next_test_due'] ?? '—' }}</td>
                            <td>{{ $system['overdue'] ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No DR system is on record.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="section-block">
            <a name="cbn-dr-tests"></a>
            <h1 class="section">CBN §3.3(2) — DR and failover tests for the period</h1>
            <table class="data">
                <thead><tr><th>Type</th><th>Date</th><th>RTO actual (min)</th><th>RPO actual (min)</th><th>Met objectives</th><th>Breach</th></tr></thead>
                <tbody>
                    @forelse ($cbnContent['dr_tests'] as $test)
                        <tr>
                            <td>{{ $test['test_type'] }}</td>
                            <td>{{ $test['test_date'] }}</td>
                            <td>{{ $test['rto_actual_minutes'] ?? '—' }}</td>
                            <td>{{ $test['rpo_actual_minutes'] ?? '—' }}</td>
                            <td>{{ $test['met_objectives'] === null ? '—' : ($test['met_objectives'] ? 'Yes' : 'No') }}</td>
                            <td>{{ $test['breach'] ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No DR or failover test fell inside this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="section-block">
            <a name="cbn-incidents"></a>
            <h1 class="section">CBN §3.3(4) — incident register and notification timing</h1>
            @if (! empty($cbnContent['incidents_current_state_label']))
                <p><em>{{ $cbnContent['incidents_current_state_label'] }}</em></p>
            @endif
            @forelse ($cbnContent['incidents'] as $incident)
                <p><strong>{{ $incident['reference'] }}</strong> — {{ $incident['title'] }} (detected {{ $incident['detected_at'] }}, declared {{ $incident['declared_at'] ?? '—' }}, status {{ $incident['status'] }})</p>
                @if (empty($incident['notifications']))
                    <p>No regulator notification obligation is recorded for this incident.</p>
                @else
                    <table class="data">
                        <thead><tr><th>Regulator</th><th>Due</th><th>Submitted</th><th>Within window</th></tr></thead>
                        <tbody>
                            @foreach ($incident['notifications'] as $notification)
                                <tr>
                                    <td>{{ $notification['regulator'] }}</td>
                                    <td>{{ $notification['due_at'] ?? '—' }}</td>
                                    <td>{{ $notification['submitted_at'] ?? '—' }}</td>
                                    <td>{{ $notification['within_window'] === null ? '—' : ($notification['within_window'] ? 'Yes' : 'No') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @empty
                <p>No real (non-exercise) incident fell inside this period.</p>
            @endforelse
        </div>
    @endif

@endsection
