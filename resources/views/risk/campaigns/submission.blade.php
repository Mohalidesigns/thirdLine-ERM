@extends('layouts.app')
@section('title', 'Submission: ' . $campaign->campaign_code)
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.campaigns.show', $campaign) }}" class="hover:text-primary">{{ $campaign->campaign_code }}</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Submission</span>
@endsection

@section('content')
@php
    /*
     * Two shapes land in campaign_responses and this screen has to read both:
     *
     *   - RCSA worksheet lines (RcsaController::storeWorksheet) are free text.
     *     risk_id is null and everything the respondent typed — description,
     *     category, the inherent pair, the controls, the action plan — sits in
     *     questionnaire_data.
     *   - Campaign respond-form lines (CampaignController::submitResponse) point
     *     at a register risk and leave questionnaire_data null.
     *
     * Normalising here keeps the table below reading from one shape.
     */
    $lines = $assignment->responses->map(function ($response) {
        $data = $response->questionnaire_data ?? [];

        return (object) [
            'title' => $data['description']
                ?? $response->risk?->title
                ?? $response->control?->control_title
                ?? '—',
            'reference' => $response->risk?->risk_code ?? $response->control?->control_code,
            'category' => $data['category'] ?? $response->risk?->category?->name,
            'inherent_likelihood' => $data['inherent_likelihood'] ?? null,
            'inherent_impact' => $data['inherent_impact'] ?? null,
            'inherent_score' => $data['inherent_score'] ?? null,
            'inherent_rating' => $data['inherent_rating'] ?? null,
            // The stored columns are the residual position — see the comment in
            // storeWorksheet(). questionnaire_data repeats them so the reduction
            // stays auditable; the columns stay authoritative here.
            'residual_likelihood' => $response->likelihood_score,
            'residual_impact' => $response->impact_score,
            'residual_score' => $response->overall_score,
            'residual_rating' => $response->rating,
            'control_effectiveness' => $response->control_effectiveness,
            'existing_controls' => $data['existing_controls'] ?? null,
            'action_plan' => $data['action_plan'] ?? $response->comments,
        ];
    });

    $statusColours = [
        'pending' => 'gray', 'in_progress' => 'yellow', 'submitted' => 'blue',
        'under_review' => 'purple', 'approved' => 'green', 'rejected' => 'red',
    ];
    $statusColour = $statusColours[$assignment->status] ?? 'gray';

    $effectivenessLabels = [
        'effective' => ['Effective', 'text-green-700 bg-green-50'],
        'partially_effective' => ['Partially effective', 'text-yellow-700 bg-yellow-50'],
        'ineffective' => ['Ineffective', 'text-red-700 bg-red-50'],
        'not_tested' => ['Not tested', 'text-gray-600 bg-gray-100'],
    ];
@endphp

<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $campaign->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $campaign->campaign_code }} &middot;
                {{ $assignment->businessUnit?->name ?? 'Unassigned unit' }} &middot;
                submitted by {{ $assignment->respondent?->name ?? 'Unknown' }}
            </p>
        </div>
        <a href="{{ route('risk.campaigns.show', $campaign) }}"
           class="px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 whitespace-nowrap">
            Back to campaign
        </a>
    </div>

    {{-- Assignment header --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <dt class="text-xs text-gray-500">Status</dt>
                <dd class="mt-1">
                    <span class="badge bg-{{ $statusColour }}-100 text-{{ $statusColour }}-700">
                        {{ ucfirst(str_replace('_', ' ', $assignment->status)) }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Submitted</dt>
                <dd class="mt-1 text-sm font-medium text-gray-900">
                    {{ $assignment->submitted_at?->format('d M Y, H:i') ?? 'Not yet submitted' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Due</dt>
                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $assignment->due_date?->format('d M Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Reviewer</dt>
                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $assignment->reviewer?->name ?? '—' }}</dd>
            </div>
        </dl>

        @if($assignment->reviewer_notes)
            <div class="mt-4 pt-4 border-t border-gray-100">
                <dt class="text-xs text-gray-500">Reviewer notes</dt>
                <dd class="mt-1 text-sm text-gray-700">{{ $assignment->reviewer_notes }}</dd>
            </div>
        @endif
    </div>

    {{-- Submitted lines --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">Submitted lines</h3>
            <span class="text-xs text-gray-500">{{ $lines->count() }} {{ Str::plural('line', $lines->count()) }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Risk</th>
                        <th>Category</th>
                        <th>Inherent</th>
                        <th>Residual</th>
                        <th>Control effectiveness</th>
                        <th>Existing controls</th>
                        <th>Action plan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lines as $line)
                        <tr>
                            <td>
                                <span class="text-sm font-medium text-gray-900">{{ $line->title }}</span>
                                @if($line->reference)
                                    <span class="block text-[11px] text-gray-400 mt-0.5">{{ $line->reference }}</span>
                                @endif
                            </td>
                            <td class="text-sm text-gray-600">{{ $line->category ?? '—' }}</td>
                            <td>
                                @if($line->inherent_score !== null)
                                    <span class="text-xs text-gray-500">{{ $line->inherent_likelihood }} &times; {{ $line->inherent_impact }} = {{ $line->inherent_score }}</span>
                                    @if($line->inherent_rating)
                                        <x-risk-badge :rating="$line->inherent_rating" class="ml-1" />
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td>
                                @if($line->residual_score !== null)
                                    <span class="text-xs text-gray-500">{{ $line->residual_likelihood }} &times; {{ $line->residual_impact }} = {{ $line->residual_score }}</span>
                                    @if($line->residual_rating)
                                        <x-risk-badge :rating="$line->residual_rating" class="ml-1" />
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td>
                                @php $eff = $effectivenessLabels[$line->control_effectiveness] ?? null; @endphp
                                @if($eff)
                                    <span class="badge {{ $eff[1] }}">{{ $eff[0] }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="text-sm text-gray-600 max-w-[220px]">{{ $line->existing_controls ?? '—' }}</td>
                            <td class="text-sm text-gray-600 max-w-[220px]">{{ $line->action_plan ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-8 text-gray-400 text-sm">
                                Nothing recorded against this assignment yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Review actions, so a reviewer never has to go back to the campaign
         page to act on what they have just read. --}}
    @if($assignment->status === 'submitted' && auth()->user()->can('campaign.review'))
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Review</h3>
            <form method="POST" action="{{ route('risk.campaigns.review-assignment', $assignment) }}" class="space-y-3">
                @csrf
                <div>
                    <label for="reviewer_notes" class="text-xs text-gray-500">Notes (optional)</label>
                    <textarea id="reviewer_notes" name="reviewer_notes" rows="2"
                              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                              placeholder="Why you are approving or rejecting this submission"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" name="action" value="approve"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Approve</button>
                    <button type="submit" name="action" value="reject"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700">Reject</button>
                </div>
            </form>
        </div>
    @endif
</div>
@endsection
