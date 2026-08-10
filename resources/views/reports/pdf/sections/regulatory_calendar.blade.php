@if ($data['total'] === 0)
    <div class="empty">No regulatory filing deadlines are recorded.</div>
@else
    @if ($data['overdue']->isNotEmpty())
        <h2 style="color: #a02222;">Overdue filings</h2>
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 22mm;">Regulator</th>
                    <th>Return</th>
                    <th style="width: 24mm;">Frequency</th>
                    <th style="width: 24mm;">Due</th>
                    <th style="width: 30mm;">Responsible</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['overdue'] as $deadline)
                    <tr>
                        <td>{{ $deadline->regulator }}</td>
                        <td>{{ $deadline->title }}</td>
                        <td>{{ ucfirst((string) $deadline->frequency) }}</td>
                        <td>{{ $deadline->deadline_date?->format('d M Y') }}</td>
                        <td>{{ $deadline->responsible?->name ?? 'Unassigned' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Due within 90 days</h2>
    @if ($data['upcoming']->isEmpty())
        <p>Nothing falls due in the next 90 days.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 22mm;">Regulator</th>
                    <th>Return</th>
                    <th style="width: 24mm;">Frequency</th>
                    <th style="width: 24mm;">Due</th>
                    <th style="width: 30mm;">Responsible</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['upcoming'] as $deadline)
                    <tr>
                        <td>{{ $deadline->regulator }}</td>
                        <td>{{ $deadline->title }}</td>
                        <td>{{ ucfirst((string) $deadline->frequency) }}</td>
                        <td>{{ $deadline->deadline_date?->format('d M Y') }}</td>
                        <td>{{ $deadline->responsible?->name ?? 'Unassigned' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif
