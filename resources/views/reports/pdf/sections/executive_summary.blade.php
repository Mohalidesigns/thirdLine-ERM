@php
    /** Money is stored in kobo throughout; divide by 100 for naira. */
    $naira = fn (int $kobo) => '₦'.number_format($kobo / 100, 2);
@endphp

<table class="kpi-grid">
    <tr>
        <td class="kpi">
            <div class="label">Active risks</div>
            <div class="value">{{ number_format($data['total_risks']) }}</div>
        </td>
        <td class="kpi">
            <div class="label">Critical residual</div>
            <div class="value">{{ number_format($data['critical_risks']) }}</div>
            <div class="note">{{ number_format($data['high_risks']) }} rated High</div>
        </td>
        <td class="kpi">
            <div class="label">Open issues</div>
            <div class="value">{{ number_format($data['open_issues']) }}</div>
        </td>
        <td class="kpi">
            <div class="label">Indicators in breach</div>
            <div class="value">{{ number_format($data['red_kris']) }}</div>
            <div class="note">KRIs currently red</div>
        </td>
    </tr>
</table>

<h2>Position</h2>

<p>
    The register carries <strong>{{ number_format($data['total_risks']) }}</strong> active risks, of which
    <strong>{{ number_format($data['critical_risks']) }}</strong> carry a Critical residual rating and
    <strong>{{ number_format($data['high_risks']) }}</strong> a High rating.
    <strong>{{ number_format($data['open_issues']) }}</strong> issues remain open or in progress, and
    <strong>{{ number_format($data['overdue_treatments']) }}</strong> treatment plans have passed their target date
    without being closed.
</p>

<p>
    Gross operational losses recorded year to date total
    <strong>{{ $naira($data['ytd_loss_kobo']) }}</strong>.
    <strong>{{ number_format($data['red_kris']) }}</strong> key risk indicators are currently reading red.
</p>

@if ($data['car_actual'] !== null)
    <p>
        Capital adequacy stands at <strong>{{ number_format($data['car_actual'], 2) }}%</strong>
        @if ($data['car_minimum'] !== null)
            against a regulatory minimum of {{ number_format($data['car_minimum'], 2) }}%, placing the institution
            <strong>{{ $data['car_actual'] >= $data['car_minimum'] ? 'above' : 'below' }}</strong> the required floor
        @endif
        @if ($data['icaap_period']) (ICAAP period {{ $data['icaap_period'] }}) @endif.
    </p>
@else
    <div class="note">
        No ICAAP assessment is on record, so no capital adequacy position is stated here. This is an absence of data,
        not a nil return.
    </div>
@endif
