@extends('reports.pdf.layout')

{{--
    The PDF written when an assessment is submitted.

    IT IS EVIDENCE, NOT A REPORT. It is rendered once, at the moment of
    submission, and never regenerated — a PDF produced later from live rows is
    not what was filed, it is a re-render under whatever the data has since
    become. That is why RcsaSubmissionService writes it to storage rather than
    offering it as a download that renders on demand.

    The columns are the workbook's, in the workbook's order, so somebody
    holding this next to SB_RCSA Template 2026 is reading the same document.
--}}

@php
    $band = fn (?string $level) => match ($level) {
        'very_high' => 'badge-critical',
        'high' => 'badge-high',
        'medium' => 'badge-medium',
        default => 'badge-low',
    };

    $aboveAppetite = $lines->filter(fn ($line) => str_starts_with((string) $line->appetite_status, 'Above risk appetite'));
@endphp

@section('body')

    <div class="section-block">
        <a name="section-summary"></a>
        <h1 class="section">Risk & Control Self-Assessment</h1>

        <div class="note">
            <strong>{{ $businessUnit?->name ?? 'Business unit' }}</strong> — {{ $cycle->name }}<br>
            Period {{ $cycle->period_start?->toFormattedDateString() }} to {{ $cycle->period_end?->toFormattedDateString() }}.
            Submitted {{ $assessment->submitted_at?->toDayDateTimeString() ?? now()->toDayDateTimeString() }}.<br>
            {{ $lines->count() }} risk(s) assessed, {{ $aboveAppetite->count() }} above risk appetite.
        </div>

        @if ($lines->isEmpty())
            <div class="empty">This assessment contains no risks.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Risk No.</th>
                        <th>Process</th>
                        <th>Potential Risk</th>
                        <th>Category</th>
                        <th>L</th>
                        <th>I</th>
                        <th>Inherent</th>
                        <th>Existing Control</th>
                        <th>Control Effectiveness</th>
                        <th>Residual</th>
                        <th>Treatment</th>
                        <th>Risk Appetite Alignment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>{{ $line->risk_no }}</td>
                            <td>
                                {{ $line->process_name ?? '—' }}
                                @if ($line->sub_process_name)
                                    <br><small>{{ $line->sub_process_name }}</small>
                                @endif
                            </td>
                            <td>{{ $line->potential_risk }}</td>
                            <td>{{ $line->risk_category ?? '—' }}</td>
                            <td>{{ $line->inherent_likelihood ?? '—' }}</td>
                            <td>{{ $line->inherent_impact ?? '—' }}</td>
                            <td>
                                {{ $line->inherent_score ?? '—' }}
                                <span class="badge {{ $band($line->inherent_level) }}">
                                    {{ str_replace('_', ' ', (string) $line->inherent_level) }}
                                </span>
                            </td>
                            <td>{{ $line->existing_control ?? '—' }}</td>
                            <td>
                                {{ $line->control_effectiveness ?? '—' }}
                                @if ($line->ce_modifier !== null)
                                    <br><small>{{ $line->ce_modifier }}%</small>
                                @endif
                            </td>
                            <td>
                                {{ $line->residual_score !== null ? number_format((float) $line->residual_score, 2) : '—' }}
                                <span class="badge {{ $band($line->residual_level) }}">
                                    {{ str_replace('_', ' ', (string) $line->residual_level) }}
                                </span>
                            </td>
                            <td>{{ ucfirst((string) ($line->treatment_override ?? $line->risk_treatment ?? '—')) }}</td>
                            <td>{{ $line->appetite_status ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- The treatment plan, which is what the workbook's columns U, V and W carry. --}}
    @if ($aboveAppetite->isNotEmpty())
        <div class="section-block">
            <a name="section-plans"></a>
            <h1 class="section">Risk treatment plan</h1>

            <div class="note">
                Every risk above appetite, with the control to be implemented, who is accountable and by when.
            </div>

            <table class="data">
                <thead>
                    <tr>
                        <th>Risk No.</th>
                        <th>Potential Risk</th>
                        <th>Residual</th>
                        <th>Control To Be Implemented</th>
                        <th>Person to Act / Risk Owner</th>
                        <th>Implementation Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($aboveAppetite as $line)
                        @forelse ($line->actionPlans as $plan)
                            <tr>
                                <td>{{ $loop->first ? $line->risk_no : '' }}</td>
                                <td>{{ $loop->first ? $line->potential_risk : '' }}</td>
                                <td>{{ $loop->first ? number_format((float) $line->residual_score, 2) : '' }}</td>
                                <td>{{ $plan->control_to_implement }}</td>
                                <td>{{ $plan->owner?->name ?? 'Unassigned' }}</td>
                                <td>{{ $plan->target_date?->toFormattedDateString() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td>{{ $line->risk_no }}</td>
                                <td>{{ $line->potential_risk }}</td>
                                <td>{{ number_format((float) $line->residual_score, 2) }}</td>
                                <td colspan="3"><em>No action plan recorded.</em></td>
                            </tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
