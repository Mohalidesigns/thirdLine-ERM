@extends('reports.pdf.layout')

{{--
    The CBN Cyber Framework Appendix II §1.4 register — FR-RPT-01, AC-13.

    THE REGISTER IS THIRTY COLUMNS WIDE AND A PAGE IS NOT. Printing all thirty
    side by side on A3 landscape gives each about a centimetre, which is a
    table nobody can read presented as though it were complete. The rows are
    therefore printed THREE TIMES, once per column group, in the same order,
    each keyed by the engagement reference — so the reader can follow one
    arrangement across the three tables, and each table is legible.

    ROW-FOR-ROW RECONCILIATION IS ABOUT ROWS. AC-13 requires that the export
    contain the same arrangements as the filtered view it was taken from, in
    the same order. Each of the three tables holds every row, so the count on
    the cover, the count on the screen and the length of each table agree.

    THE COVER CARRIES THE FILTERS. A register naming no filters reads as the
    whole population; this one states what was excluded, so a short register is
    read as a narrow view rather than as a small vendor estate.
--}}

@php
    $groups = collect($columns)->groupBy('group');

    // The key column repeats in every table so a reader can join them by eye.
    $key = ['key' => 'reference', 'label' => 'Engagement'];

    $tables = [
        'Provider and arrangement' => $groups->get('Provider', collect())->concat($groups->get('Arrangement', collect())),
        'Data, connections and access' => $groups->get('Data', collect())->concat($groups->get('Access', collect())),
        'Assurance and residual risk' => $groups->get('Assurance', collect())->concat($groups->get('Risk', collect())),
    ];

    $bandBadge = fn ($band) => match (strtolower((string) $band)) {
        'critical' => 'badge badge-critical',
        'high' => 'badge badge-high',
        'moderate', 'medium' => 'badge badge-medium',
        'low' => 'badge badge-low',
        default => 'muted',
    };
@endphp

@section('body')

    <div class="section-block">
        <a name="section-summary"></a>
        <h1 class="section">Register summary</h1>

        <table class="kpi-grid">
            <tr>
                <td class="kpi">
                    <div class="label">Arrangements</div>
                    <div class="value">{{ $summary['total'] }}</div>
                    <div class="note">ICT services, outsourcing and cloud</div>
                </td>
                <td class="kpi">
                    <div class="label">Critical tier</div>
                    <div class="value">{{ $summary['critical_tier'] }}</div>
                    <div class="note">{{ $summary['supports_critical_function'] }} support a critical function</div>
                </td>
                <td class="kpi">
                    <div class="label">Undocumented connections</div>
                    <div class="value">{{ $summary['undocumented_connections'] }}</div>
                    <div class="note">arrangements with at least one</div>
                </td>
                <td class="kpi">
                    <div class="label">Access past its end date</div>
                    <div class="value">{{ $summary['access_grants_overdue'] }}</div>
                    <div class="note">grants expired but not revoked</div>
                </td>
            </tr>
        </table>

        <div class="note">
            Assurance evidence is <strong>expired</strong> for {{ $summary['evidence_expired'] }}
            {{ Str::plural('arrangement', $summary['evidence_expired']) }} and
            <strong>absent</strong> for {{ $summary['evidence_none'] }}. Those are different findings: the first is a
            lapse to chase, the second is a control that was never evidenced at all.
        </div>
    </div>

    @foreach ($tables as $heading => $groupColumns)
        <div class="section-block section-break">
            <a name="section-{{ Str::slug($heading) }}"></a>
            <h1 class="section">{{ $heading }}</h1>

            @if ($rows->isEmpty())
                <div class="empty">No arrangements match this view.</div>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            <th>{{ $key['label'] }}</th>
                            @foreach ($groupColumns as $column)
                                @continue($column['key'] === $key['key'])
                                <th>{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><strong>{{ $row[$key['key']] }}</strong></td>
                                @foreach ($groupColumns as $column)
                                    @continue($column['key'] === $key['key'])
                                    @php $value = $row[$column['key']] ?? null; @endphp
                                    <td class="{{ is_numeric($value) ? 'num' : '' }}">
                                        @if ($column['key'] === 'residual_band' || $column['key'] === 'tier')
                                            <span class="{{ $bandBadge($value) }}">{{ $value }}</span>
                                        @elseif ($value === null || $value === '')
                                            <span class="muted">—</span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

@endsection
