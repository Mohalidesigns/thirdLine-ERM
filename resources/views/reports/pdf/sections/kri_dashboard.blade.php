<table class="kpi-grid">
    <tr>
        <td class="kpi" style="border-left-color: #c53030;">
            <div class="label">Red</div>
            <div class="value">{{ $data['red'] }}</div>
        </td>
        <td class="kpi" style="border-left-color: #d69e2e;">
            <div class="label">Amber</div>
            <div class="value">{{ $data['amber'] }}</div>
        </td>
        <td class="kpi" style="border-left-color: #2d7d46;">
            <div class="label">Green</div>
            <div class="value">{{ $data['green'] }}</div>
        </td>
        <td class="kpi" style="border-left-color: #8b95a1;">
            <div class="label">Not measured</div>
            <div class="value">{{ $data['unmeasured'] }}</div>
            <div class="note">no status recorded</div>
        </td>
    </tr>
</table>

@if ($data['kris']->isEmpty())
    <div class="empty">No key risk indicators are defined.</div>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width: 20mm;">Code</th>
                <th>Indicator</th>
                <th style="width: 26mm;">Owner</th>
                <th style="width: 22mm;">Frequency</th>
                <th style="width: 20mm;" class="num">Current</th>
                <th style="width: 22mm;">Last measured</th>
                <th style="width: 18mm;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['kris'] as $kri)
                @php
                    $status = strtolower((string) $kri->current_status);
                    $badge = match ($status) {
                        'red' => 'badge-critical',
                        'amber', 'yellow' => 'badge-medium',
                        'green' => 'badge-low',
                        default => null,
                    };
                @endphp
                <tr>
                    <td>{{ $kri->kri_code }}</td>
                    <td>{{ $kri->name }}</td>
                    <td>{{ $kri->owner?->name ?? 'Unassigned' }}</td>
                    <td>{{ ucfirst((string) $kri->measurement_frequency) }}</td>
                    <td class="num">
                        {{ $kri->current_value !== null ? number_format((float) $kri->current_value, 2) : '—' }}
                        {{ $kri->unit_of_measure }}
                    </td>
                    <td>{{ $kri->last_measurement_at ? \Carbon\Carbon::parse($kri->last_measurement_at)->format('d M Y') : 'Never' }}</td>
                    <td>
                        @if ($badge)
                            <span class="badge {{ $badge }}">{{ ucfirst($status) }}</span>
                        @else
                            <span class="muted">Not measured</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
