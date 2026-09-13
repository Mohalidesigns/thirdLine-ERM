@if ($data['total_open'] === 0)
    <div class="empty">No issues are currently open or in progress.</div>
@else
    <p class="muted" style="font-size: 8pt;">
        Age is measured from the date the issue was raised to the position date on the cover.
    </p>

    <table class="data">
        <thead>
            <tr>
                <th>Age band</th>
                <th style="width: 24mm;" class="num">Open issues</th>
                <th style="width: 24mm;" class="num">Share</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['buckets'] as $band => $count)
                <tr>
                    <td>{{ $band }}</td>
                    <td class="num">{{ number_format($count) }}</td>
                    <td class="num">
                        {{ $data['total_open'] > 0 ? number_format($count / $data['total_open'] * 100, 1).'%' : '—' }}
                    </td>
                </tr>
            @endforeach
            <tr style="font-weight: bold; background: #f4f6f8;">
                <td>Total</td>
                <td class="num">{{ number_format($data['total_open']) }}</td>
                <td class="num">100.0%</td>
            </tr>
        </tbody>
    </table>

    <p style="font-size: 8pt;">
        <strong>{{ number_format($data['overdue']) }}</strong> of these have passed their remediation due date.
    </p>

    <h2>Oldest open issues</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 24mm;">Reference</th>
                <th>Issue</th>
                <th style="width: 26mm;">Owner</th>
                <th style="width: 20mm;">Priority</th>
                <th style="width: 22mm;">Raised</th>
                <th style="width: 22mm;">Due</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['oldest'] as $issue)
                <tr>
                    <td>{{ $issue->issue_reference }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($issue->title, 70) }}</td>
                    <td>{{ $issue->owner?->name ?? 'Unassigned' }}</td>
                    <td>{{ ucfirst((string) $issue->priority) }}</td>
                    <td>{{ $issue->created_at?->format('d M Y') ?? '—' }}</td>
                    <td>
                        {{ $issue->remediation_due_date
                            ? \Carbon\Carbon::parse($issue->remediation_due_date)->format('d M Y')
                            : '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
