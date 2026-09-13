@php
    $badge = fn (?string $rating) => match (strtolower((string) $rating)) {
        'critical' => 'badge-critical',
        'high' => 'badge-high',
        'medium' => 'badge-medium',
        default => 'badge-low',
    };
@endphp

@if ($data['risks']->isEmpty())
    <div class="empty">No active risks are recorded in the register.</div>
@else
    <p class="muted" style="font-size: 8pt;">
        The {{ min($data['limit'], $data['risks']->count()) }} highest-scoring active risks by residual score.
    </p>

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
            @foreach ($data['risks'] as $risk)
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
