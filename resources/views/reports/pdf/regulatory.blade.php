@extends('reports.pdf.layout')

@php
    $naira = fn ($kobo) => '₦'.number_format(((int) $kobo) / 100, 2);
@endphp

@section('body')

    <div class="section-block">
        <a name="section-filings"></a>
        <h1 class="section">1. Filing obligations</h1>

        <table class="kpi-grid">
            <tr>
                <td class="kpi">
                    <div class="label">Obligations on file</div>
                    <div class="value">{{ number_format($summary['deadlines_total']) }}</div>
                </td>
                <td class="kpi" style="border-left-color: #c53030;">
                    <div class="label">Overdue</div>
                    <div class="value">{{ number_format($summary['deadlines_overdue']) }}</div>
                </td>
                <td class="kpi">
                    <div class="label">Directives</div>
                    <div class="value">{{ number_format($summary['circulars_total']) }}</div>
                    <div class="note">{{ number_format($summary['circulars_non_compliant']) }} not yet compliant</div>
                </td>
                <td class="kpi">
                    <div class="label">Loss events YTD</div>
                    <div class="value">{{ number_format($summary['losses_ytd']) }}</div>
                    <div class="note">{{ $naira($summary['losses_gross_kobo']) }} gross</div>
                </td>
            </tr>
        </table>

        @if ($deadlines->isEmpty())
            <div class="empty">
                No regulatory filing obligations are recorded. This is an empty register, not a nil return.
            </div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 20mm;">Regulator</th>
                        <th>Return</th>
                        <th style="width: 22mm;">Frequency</th>
                        <th style="width: 22mm;">Deadline</th>
                        <th style="width: 22mm;">Status</th>
                        <th style="width: 28mm;">Responsible</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deadlines as $deadline)
                        <tr>
                            <td>{{ $deadline->regulator }}</td>
                            <td>{{ $deadline->title }}</td>
                            <td>{{ ucfirst((string) $deadline->frequency) }}</td>
                            <td>{{ $deadline->deadline_date?->format('d M Y') ?? '—' }}</td>
                            <td>
                                @if ($deadline->isOverdue())
                                    <span class="badge badge-critical">Overdue</span>
                                @else
                                    {{ ucwords(str_replace('_', ' ', (string) $deadline->status)) }}
                                @endif
                            </td>
                            <td>{{ $deadline->responsible?->name ?? 'Unassigned' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section-block section-break">
        <a name="section-directives"></a>
        <h1 class="section">2. Regulator directives</h1>

        @if ($circulars->isEmpty())
            <div class="empty">No regulator circulars are recorded.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 20mm;">Regulator</th>
                        <th style="width: 32mm;">Reference</th>
                        <th>Title</th>
                        <th style="width: 22mm;">Issued</th>
                        <th style="width: 22mm;">Effective</th>
                        <th style="width: 20mm;">Impact</th>
                        <th style="width: 24mm;">Compliance</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($circulars as $circular)
                        <tr>
                            <td>{{ $circular->regulator }}</td>
                            <td>{{ $circular->circular_ref }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($circular->title, 70) }}</td>
                            <td>{{ $circular->date_issued?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $circular->effective_date?->format('d M Y') ?? '—' }}</td>
                            <td>{{ ucfirst((string) $circular->impact_level) }}</td>
                            <td>
                                {{ ucwords(str_replace('_', ' ', (string) $circular->compliance_status)) }}
                                @if ($circular->compliance_pct !== null)
                                    ({{ number_format((float) $circular->compliance_pct, 0) }}%)
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section-block section-break">
        <a name="section-losses"></a>
        <h1 class="section">3. Reportable loss events</h1>

        @if ($losses->isEmpty())
            <div class="empty">No loss events are recorded for the current year.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 24mm;">Reference</th>
                        <th>Event</th>
                        <th style="width: 22mm;">Date of loss</th>
                        <th style="width: 32mm;">Basel L1</th>
                        <th style="width: 28mm;" class="num">Gross loss</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($losses as $loss)
                        <tr>
                            <td>{{ $loss->event_reference }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($loss->title, 60) }}</td>
                            <td>{{ $loss->date_of_loss ? \Carbon\Carbon::parse($loss->date_of_loss)->format('d M Y') : '—' }}</td>
                            <td>{{ ucwords(strtolower(str_replace('_', ' ', (string) $loss->basel_l1_category))) }}</td>
                            <td class="num">{{ $naira($loss->gross_loss_amount_kobo) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="note">
            Statutory reporting thresholds and deadlines are evaluated by the regulatory threshold engine against each
            event as it is recorded. This section lists the events; it does not restate those determinations.
        </div>
    </div>

@endsection
