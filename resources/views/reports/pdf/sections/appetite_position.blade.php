@if ($data['appetites']->isEmpty())
    <div class="empty">
        No risk appetite statements have been set. The Board cannot be shown a position against an appetite that does
        not exist in the system.
    </div>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width: 32mm;">Category</th>
                <th style="width: 20mm;">Appetite</th>
                <th>Statement</th>
                <th style="width: 24mm;">Tolerance metric</th>
                <th style="width: 18mm;" class="num">Target</th>
                <th style="width: 18mm;" class="num">Current</th>
                <th style="width: 18mm;">Position</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['appetites'] as $appetite)
                @php
                    // "Within" is only asserted when both a limit and a current
                    // position are recorded. Anything else reads as not
                    // measured — never as compliant by default.
                    $current = $appetite->current_position;
                    $limit = $appetite->max_tolerance;
                    $state = ($current === null || $limit === null)
                        ? null
                        : ((float) $current <= (float) $limit);
                @endphp
                <tr>
                    <td>{{ $appetite->category?->name ?? '—' }}</td>
                    <td>{{ ucfirst((string) $appetite->appetite_level) }}</td>
                    <td>{{ \Illuminate\Support\Str::limit((string) $appetite->appetite_statement, 160) }}</td>
                    <td>{{ $appetite->tolerance_metric ?? '—' }}</td>
                    <td class="num">
                        {{ $limit !== null ? number_format((float) $limit, 2) : '—' }}
                        {{ $appetite->unit_of_measure }}
                    </td>
                    <td class="num">{{ $current !== null ? number_format((float) $current, 2) : '—' }}</td>
                    <td>
                        @if ($state === null)
                            <span class="muted">Not measured</span>
                        @elseif ($state)
                            <span class="badge badge-low">Within</span>
                        @else
                            <span class="badge badge-critical">Breached</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="note">
        A category shows "Not measured" where either the tolerance limit or the current position is absent. It is not
        counted as compliant.
    </div>
@endif
