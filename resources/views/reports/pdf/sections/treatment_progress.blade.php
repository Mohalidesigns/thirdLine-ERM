@if ($data['total'] === 0)
    <div class="empty">No treatment plans are recorded.</div>
@else
    <table class="kpi-grid">
        <tr>
            <td class="kpi">
                <div class="label">Plans</div>
                <div class="value">{{ number_format($data['total']) }}</div>
            </td>
            <td class="kpi" style="border-left-color: #2d7d46;">
                <div class="label">Completed</div>
                <div class="value">{{ number_format($data['completed']) }}</div>
                <div class="note">
                    {{ $data['completion_pct'] !== null ? number_format($data['completion_pct'], 1).'% of all plans' : '' }}
                </div>
            </td>
            <td class="kpi" style="border-left-color: #d69e2e;">
                <div class="label">In progress</div>
                <div class="value">{{ number_format($data['in_progress']) }}</div>
            </td>
            <td class="kpi" style="border-left-color: #c53030;">
                <div class="label">Overdue</div>
                <div class="value">{{ number_format($data['overdue']) }}</div>
                <div class="note">target date passed</div>
            </td>
        </tr>
    </table>

    @if ($data['overdue'] > 0)
        <h2>Overdue plans requiring attention</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th style="width: 34mm;">Risk</th>
                    <th style="width: 26mm;">Owner</th>
                    <th style="width: 22mm;">Target date</th>
                    <th style="width: 18mm;" class="num">Progress</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['overdue_plans'] as $plan)
                    <tr>
                        <td>{{ \Illuminate\Support\Str::limit($plan->action_title ?? $plan->treatment_title ?? $plan->treatment_code, 60) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($plan->risk?->title ?? '—', 40) }}</td>
                        <td>{{ $plan->owner?->name ?? 'Unassigned' }}</td>
                        <td>{{ $plan->target_date ? \Carbon\Carbon::parse($plan->target_date)->format('d M Y') : '—' }}</td>
                        <td class="num">{{ $plan->progress_pct !== null ? $plan->progress_pct.'%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>No treatment plan has passed its target date.</p>
    @endif
@endif
