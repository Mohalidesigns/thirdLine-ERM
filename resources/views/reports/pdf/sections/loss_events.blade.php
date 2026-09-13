@php
    $naira = fn ($kobo) => '₦'.number_format(((int) $kobo) / 100, 2);
    $netKobo = $data['gross_kobo'] - $data['recovered_kobo'];
@endphp

<p class="muted" style="font-size: 8pt;">
    Loss events dated between {{ $data['period_start']->format('d M Y') }} and the position date on the cover.
</p>

<table class="kpi-grid">
    <tr>
        <td class="kpi">
            <div class="label">Events</div>
            <div class="value">{{ number_format($data['count']) }}</div>
        </td>
        <td class="kpi">
            <div class="label">Gross loss</div>
            <div class="value" style="font-size: 12pt;">{{ $naira($data['gross_kobo']) }}</div>
        </td>
        <td class="kpi">
            <div class="label">Recovered</div>
            <div class="value" style="font-size: 12pt;">{{ $naira($data['recovered_kobo']) }}</div>
        </td>
        <td class="kpi">
            <div class="label">Net loss</div>
            <div class="value" style="font-size: 12pt;">{{ $naira($netKobo) }}</div>
        </td>
    </tr>
</table>

@if ($data['count'] === 0)
    <div class="empty">No loss events are recorded for this period.</div>
@else
    <h2>By Basel Level 1 category</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Category</th>
                <th style="width: 18mm;" class="num">Events</th>
                <th style="width: 30mm;" class="num">Gross loss</th>
                <th style="width: 30mm;" class="num">Recovered</th>
                <th style="width: 30mm;" class="num">Net</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['by_category'] as $category => $row)
                <tr>
                    <td>{{ ucwords(strtolower(str_replace('_', ' ', (string) $category))) }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ $naira($row['gross_kobo']) }}</td>
                    <td class="num">{{ $naira($row['recovered_kobo']) }}</td>
                    <td class="num">{{ $naira($row['gross_kobo'] - $row['recovered_kobo']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Largest events</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 22mm;">Reference</th>
                <th>Event</th>
                <th style="width: 22mm;">Date of loss</th>
                <th style="width: 28mm;" class="num">Gross loss</th>
                <th style="width: 20mm;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['largest'] as $event)
                <tr>
                    <td>{{ $event->event_reference }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($event->title, 70) }}</td>
                    <td>{{ $event->date_of_loss ? \Carbon\Carbon::parse($event->date_of_loss)->format('d M Y') : '—' }}</td>
                    <td class="num">{{ $naira($event->gross_loss_amount_kobo) }}</td>
                    <td>{{ ucwords(strtolower(str_replace('_', ' ', (string) $event->current_status))) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
