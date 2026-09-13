@extends('reports.pdf.layout')

@php
    $badge = fn (?string $rating) => match (strtolower((string) $rating)) {
        'critical' => 'badge-critical',
        'high' => 'badge-high',
        'medium' => 'badge-medium',
        default => 'badge-low',
    };
@endphp

@section('body')

    <div class="section-block">
        <a name="section-register"></a>
        <h1 class="section">Risk register</h1>

        <div class="note">
            <strong>Scope of this extract.</strong>
            @foreach ($filters as $filter)
                {{ $filter }}@if (! $loop->last); @endif
            @endforeach
            <br>{{ number_format($risks->count()) }} risk(s) included.
        </div>

        @if ($risks->isEmpty())
            <div class="empty">No risks match the selected filters.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 18mm;">Code</th>
                        <th>Risk</th>
                        <th style="width: 24mm;">Category</th>
                        <th style="width: 24mm;">Business unit</th>
                        <th style="width: 24mm;">Owner</th>
                        <th style="width: 14mm;" class="num">Inh.</th>
                        <th style="width: 14mm;" class="num">Res.</th>
                        <th style="width: 18mm;">Rating</th>
                        <th style="width: 18mm;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($risks as $risk)
                        <tr>
                            <td>{{ $risk->risk_code }}</td>
                            <td>{{ $risk->title }}</td>
                            <td>{{ $risk->category?->name ?? '—' }}</td>
                            <td>{{ $risk->businessUnit?->name ?? '—' }}</td>
                            <td>{{ $risk->riskOwner?->name ?? 'Unassigned' }}</td>
                            <td class="num">{{ $risk->inherent_score ?? '—' }}</td>
                            <td class="num">{{ $risk->residual_score ?? '—' }}</td>
                            <td>
                                @if ($risk->residual_rating)
                                    <span class="badge {{ $badge($risk->residual_rating) }}">{{ $risk->residual_rating }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>{{ ucfirst((string) $risk->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

@endsection
