@extends('reports.pdf.layout')

{{--
    The resilience board pack — nine sections, in the blueprint's own order.
    Assembled from stored, terminal rows only; nothing here is recomputed.
--}}

@section('body')

    <div class="section-block">
        <h1 class="section">Resilience board pack — {{ $year }}</h1>
        <table class="data">
            <tbody>
                <tr><td style="width: 45mm;"><strong>Organisation</strong></td><td>{{ $organization?->name }}</td></tr>
                <tr><td><strong>Generated</strong></td><td>{{ now()->toDayDateTimeString() }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="section-block">
        <h1 class="section">1. Resilience posture summary</h1>
        @foreach ($posture_summary['sentences'] as $sentence)
            <p>{{ $sentence }}</p>
        @endforeach
    </div>

    <div class="section-block">
        <h1 class="section">2. Maturity trend</h1>
        @forelse ($maturity_trend as $point)
            <p>{{ $point['assessed_at'] }}: {{ $point['overall_score'] ?? '—' }}</p>
        @empty
            <p>No maturity assessment has been run in {{ $year }}.</p>
        @endforelse
    </div>

    <div class="section-block">
        <h1 class="section">3. Exercise programme completion</h1>
        <p>
            @if ($exercise_completion['planned'] === null)
                No approved exercise programme exists for {{ $year }}.
            @else
                {{ $exercise_completion['completed'] }} of {{ $exercise_completion['planned'] }} planned exercises completed.
            @endif
        </p>
    </div>

    <div class="section-block">
        <h1 class="section">4. Plan currency</h1>
        <p>
            @if ($plan_currency === null)
                No plans on record, so currency is undefined.
            @else
                {{ $plan_currency }}% of approved plans are inside their review cycle.
            @endif
        </p>
    </div>

    <div class="section-block">
        <h1 class="section">5. Top RTO gaps</h1>
        {{-- Live-browser follow-up to B2: "shortfall none recorded hours" read as a stray unit; "no shortfall recorded" carries no unit, and a real figure is pluralised correctly. --}}
        @forelse ($top_rto_gaps as $gap)
            <p>{{ $gap['name'] }} — required {{ $gap['rto_required_hours'] ?? '—' }}h,
                achievable {{ $gap['rto_achievable_hours'] ?? '—' }}h,
                @if (($gap['shortfall_hours'] ?? null) === null)
                    no shortfall recorded
                @else
                    shortfall {{ $gap['shortfall_hours'] }} {{ (float) $gap['shortfall_hours'] === 1.0 ? 'hour' : 'hours' }}
                @endif
            </p>
        @empty
            <p>No process currently has a strategy shortfall against its required RTO.</p>
        @endforelse
    </div>

    <div class="section-block">
        <h1 class="section">6. Open nonconformities</h1>
        @forelse ($open_nonconformities as $nc)
            <p>{{ $nc['reference'] }} — {{ $nc['description'] }} ({{ $nc['corrective_action_count'] }} corrective action(s))</p>
        @empty
            <p>No nonconformity is currently open.</p>
        @endforelse
    </div>

    <div class="section-block">
        <h1 class="section">7. Incident summary</h1>
        <p>{{ $incident_summary['count'] }} incident(s) declared in {{ $year }}.</p>
    </div>

    <div class="section-block">
        <h1 class="section">8. Resilience KRI dashboard</h1>
        <table class="data">
            <thead><tr><th>Code</th><th>Name</th><th>Target</th><th>Current</th><th>Last measured</th></tr></thead>
            <tbody>
                @foreach ($kris as $kri)
                    <tr>
                        <td>{{ $kri['kri_code'] }}</td>
                        <td>{{ $kri['name'] }}</td>
                        <td>{{ $kri['target'] }}{{ $kri['unit'] }}</td>
                        <td>{{ $kri['linked'] ? ($kri['current_value'] ?? 'not yet measured') : 'not linked' }}</td>
                        <td>{{ $kri['last_measured_at'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section-block">
        <h1 class="section">9. Management review inputs</h1>
        <p>
            @if ($management_review === null)
                No management review has been approved for {{ $year }}. Clause 9.3 requires an annual review; none is on record yet.
            @else
                {{ $management_review['title'] }}, held {{ $management_review['held_on'] }}, approved by {{ $management_review['approved_by'] }}.
            @endif
        </p>
    </div>

@endsection
