@extends('layouts.app')
@section('title', 'Campaign: ' . $campaign->campaign_code)
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.campaigns.index') }}" class="hover:text-primary">Campaigns</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">{{ $campaign->campaign_code }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $campaign->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $campaign->campaign_code }} &middot; {{ ucfirst(str_replace('_', ' ', $campaign->campaign_type)) }} &middot; {{ $campaign->start_date->format('M d') }} – {{ $campaign->end_date->format('M d, Y') }}</p>
        </div>
        <div class="flex gap-2">
            @if($campaign->status === 'draft')
                <form method="POST" action="{{ route('risk.campaigns.launch', $campaign) }}">@csrf
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Launch Campaign</button>
                </form>
            @elseif(in_array($campaign->status, ['active', 'in_progress']))
                <form method="POST" action="{{ route('risk.campaigns.close', $campaign) }}">@csrf
                    <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700">Close Campaign</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Progress.

         Two segments, not one. The headline percentage stays "approved", which
         is what completion_pct means everywhere else in the product — but work
         that respondents have handed in and nobody has reviewed yet now has its
         own band, so a campaign whose unit has finished no longer reads 0% and
         looks abandoned. --}}
    @php $progress = $campaign->progressBreakdown(); @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-gray-700">Campaign Progress</span>
            <span class="text-sm font-bold text-gray-900">{{ number_format($progress['completed_pct'], 0) }}%</span>
        </div>
        <div class="w-full h-3 bg-gray-100 rounded-full overflow-hidden flex"
             role="progressbar"
             aria-valuenow="{{ (int) $progress['completed_pct'] }}" aria-valuemin="0" aria-valuemax="100"
             aria-label="{{ $progress['completed'] }} approved and {{ $progress['awaiting_review'] }} awaiting review of {{ $progress['total'] }} {{ Str::plural('assignment', $progress['total']) }}">
            <div class="h-full bg-green-500 transition-all" style="width:{{ $progress['completed_pct'] }}%"></div>
            <div class="h-full bg-blue-400 transition-all" style="width:{{ $progress['awaiting_review_pct'] }}%"></div>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 mt-2 text-xs text-gray-500">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                {{ $progress['completed'] }} approved
            </span>
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                {{ $progress['awaiting_review'] }} awaiting review
            </span>
            <span class="text-gray-400">of {{ $progress['total'] }} {{ Str::plural('assignment', $progress['total']) }}</span>
        </div>
    </div>

    {{-- Assignments --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">Assignments</h3>
        </div>

        <table class="data-table">
            <thead>
                <tr><th>Business Unit</th><th>Respondent</th><th>Reviewer</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($campaign->assignments as $assign)
                <tr>
                    <td>{{ $assign->businessUnit?->name }}</td>
                    <td>{{ $assign->respondent?->name }}</td>
                    <td>{{ $assign->reviewer?->name ?? '—' }}</td>
                    <td class="{{ $assign->due_date->isPast() && !in_array($assign->status, ['approved','submitted']) ? 'text-red-600 font-semibold' : '' }}">{{ $assign->due_date->format('M d, Y') }}</td>
                    <td>
                        @php $ac = ['pending'=>'gray','in_progress'=>'yellow','submitted'=>'blue','under_review'=>'purple','approved'=>'green','rejected'=>'red']; @endphp
                        <span class="badge bg-{{ $ac[$assign->status] ?? 'gray' }}-100 text-{{ $ac[$assign->status] ?? 'gray' }}-700">{{ ucfirst(str_replace('_', ' ', $assign->status)) }}</span>
                    </td>
                    <td>
                        {{-- Whatever the status, if there are lines recorded there
                             is something to read. This link used to be missing
                             entirely, which is why a submitted worksheet looked
                             like it had gone nowhere. --}}
                        @if($assign->responses_count > 0)
                            <a href="{{ route('risk.campaigns.submission', $assign) }}" class="text-xs text-primary hover:underline">View submission</a>
                        @endif

                        @if(in_array($assign->status, ['pending', 'in_progress', 'rejected']))
                            <a href="{{ route('risk.campaigns.respond', $assign) }}" class="text-xs text-primary hover:underline {{ $assign->responses_count > 0 ? 'ml-2' : '' }}">Respond</a>
                        @elseif($assign->status === 'submitted')
                            <form method="POST" action="{{ route('risk.campaigns.review-assignment', $assign) }}" class="inline-flex gap-1 ml-2">
                                @csrf
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="text-xs text-green-600 hover:underline">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('risk.campaigns.review-assignment', $assign) }}" class="inline-flex gap-1 ml-2">
                                @csrf
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="text-xs text-red-600 hover:underline">Reject</button>
                            </form>
                        @elseif($assign->responses_count === 0)
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center py-6 text-gray-400">No assignments yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add Assignment Form --}}
    @if(in_array($campaign->status, ['draft', 'active']))
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Add Assignment</h3>
        <form method="POST" action="{{ route('risk.campaigns.add-assignment', $campaign) }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            @csrf
            <div>
                <label class="text-xs text-gray-500">Business Unit</label>
                <select name="business_unit_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">Select</option>
                    @foreach(\App\Models\BusinessUnit::where('organization_id', auth()->user()->organization_id)->get() as $bu)
                        <option value="{{ $bu->id }}">{{ $bu->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">Respondent</label>
                <select name="respondent_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">Select</option>
                    @foreach(\App\Models\User::where('organization_id', auth()->user()->organization_id)->where('is_active', true)->get() as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">Due Date</label>
                <input type="date" name="due_date" value="{{ $campaign->end_date->format('Y-m-d') }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">Add</button>
        </form>
    </div>
    @endif
</div>
@endsection
