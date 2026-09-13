@extends('reports.pdf.layout')

@php
    $naira = fn ($kobo) => '₦'.number_format(((int) $kobo) / 100, 2);
    $badge = fn (?string $rating) => match (strtolower((string) $rating)) {
        'critical' => 'badge-critical',
        'high' => 'badge-high',
        'medium' => 'badge-medium',
        default => 'badge-low',
    };
@endphp

@section('body')

    <div class="section-block">
        <a name="section-position"></a>
        <h1 class="section">1. Risk position</h1>

        <table class="kpi-grid">
            <tr>
                <td class="kpi">
                    <div class="label">Active risks</div>
                    <div class="value">{{ number_format($summary['total_risks']) }}</div>
                </td>
                <td class="kpi" style="border-left-color: #c53030;">
                    <div class="label">Critical residual</div>
                    <div class="value">{{ number_format($summary['critical']) }}</div>
                    <div class="note">{{ number_format($summary['high']) }} High</div>
                </td>
                <td class="kpi">
                    <div class="label">Gross loss YTD</div>
                    <div class="value" style="font-size: 12pt;">{{ $naira($summary['ytd_loss_kobo']) }}</div>
                </td>
                <td class="kpi" style="border-left-color: #d69e2e;">
                    <div class="label">Open issues</div>
                    <div class="value">{{ number_format($summary['open_issues']) }}</div>
                </td>
            </tr>
        </table>

        @if ($summary['unrated'] > 0)
            <div class="note">
                {{ number_format($summary['unrated']) }} active risk(s) carry no residual rating and are therefore
                absent from the Critical and High counts above. The position is understated by that much.
            </div>
        @endif

        <h2>Treatment</h2>
        <p>
            {{ number_format($summary['treatments_completed']) }} of
            {{ number_format($summary['treatments_total']) }} treatment plans are complete
            @if ($summary['treatments_total'] > 0)
                ({{ number_format($summary['treatments_completed'] / $summary['treatments_total'] * 100, 1) }}%)
            @endif.
            <strong>{{ number_format($summary['treatments_overdue']) }}</strong> have passed their target date without
            being closed.
        </p>
    </div>

    <div class="section-block section-break">
        <a name="section-profile"></a>
        <h1 class="section">2. Profile by category</h1>

        @if ($byCategory->isEmpty())
            <div class="empty">No active risks are recorded.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th style="width: 20mm;" class="num">Risks</th>
                        <th style="width: 20mm;" class="num">Critical</th>
                        <th style="width: 20mm;" class="num">High</th>
                        <th style="width: 30mm;" class="num">Mean residual score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byCategory as $category => $row)
                        <tr>
                            <td>{{ $category }}</td>
                            <td class="num">{{ number_format($row['count']) }}</td>
                            <td class="num">{{ number_format($row['critical']) }}</td>
                            <td class="num">{{ number_format($row['high']) }}</td>
                            <td class="num">
                                {{ $row['mean_residual'] !== null ? number_format($row['mean_residual'], 1) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="muted" style="font-size: 7.5pt;">
                A dash in the mean column means no risk in that category carries a residual score.
            </p>
        @endif
    </div>

    <div class="section-block section-break">
        <a name="section-top-risks"></a>
        <h1 class="section">3. Top risks</h1>

        @if ($topRisks->isEmpty())
            <div class="empty">No active risks are recorded.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 18mm;">Code</th>
                        <th>Risk</th>
                        <th style="width: 26mm;">Category</th>
                        <th style="width: 26mm;">Owner</th>
                        <th style="width: 16mm;" class="num">Inherent</th>
                        <th style="width: 16mm;" class="num">Residual</th>
                        <th style="width: 18mm;">Rating</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topRisks as $risk)
                        <tr>
                            <td>{{ $risk->risk_code }}</td>
                            <td>{{ $risk->title }}</td>
                            <td>{{ $risk->category?->name ?? '—' }}</td>
                            <td>{{ $risk->riskOwner?->name ?? 'Unassigned' }}</td>
                            <td class="num">{{ $risk->inherent_score ?? '—' }}</td>
                            <td class="num">{{ $risk->residual_score ?? '—' }}</td>
                            <td>
                                @if ($risk->residual_rating)
                                    <span class="badge {{ $badge($risk->residual_rating) }}">{{ $risk->residual_rating }}</span>
                                @else
                                    <span class="muted">Not rated</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section-block section-break">
        <a name="section-indicators"></a>
        <h1 class="section">4. Indicators and controls</h1>

        <h2>Key risk indicators</h2>
        <p>
            {{ number_format($summary['red_kris']) }} of {{ number_format($summary['total_kris']) }} defined indicators
            are currently reading red.
        </p>

        <h2>Control effectiveness</h2>
        @if ($summary['controls_effective_pct'] === null)
            <p>
                None of the {{ number_format($summary['controls_total']) }} recorded controls carries an effectiveness
                rating, so no effectiveness figure can be stated.
            </p>
        @else
            <p>
                <strong>{{ number_format($summary['controls_effective_pct'], 1) }}%</strong> of rated controls are
                assessed as effective, measured across the
                {{ number_format($summary['controls_rated']) }} of
                {{ number_format($summary['controls_total']) }} controls that carry a rating.
            </p>
            @if ($summary['controls_rated'] < $summary['controls_total'])
                <div class="note">
                    {{ number_format($summary['controls_total'] - $summary['controls_rated']) }} control(s) have never
                    been rated and are excluded from the percentage above rather than counted as failures.
                </div>
            @endif
        @endif
    </div>

@endsection
