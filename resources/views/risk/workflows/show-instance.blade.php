@extends('layouts.app')
@section('title', 'Workflow Instance')
@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <h1 class="text-xl font-bold text-gray-900">{{ $instance->definition?->name }}</h1>
    <p class="text-sm text-gray-500">{{ $instance->entity_type }} #{{ $instance->entity_id }} &middot; Started {{ $instance->started_at?->diffForHumans() }}</p>

    {{-- Progress --}}
    @php $stages = $instance->definition?->stages ?? []; $totalStages = count($stages); @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center gap-2">
            @foreach($stages as $idx => $stage)
                <div class="flex-1 text-center">
                    <div class="w-8 h-8 mx-auto rounded-full flex items-center justify-center text-sm font-bold {{ $idx < $instance->current_stage ? 'bg-green-500 text-white' : ($idx == $instance->current_stage && $instance->status === 'active' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-500') }}">{{ $idx + 1 }}</div>
                    <p class="text-xs mt-1 {{ $idx <= $instance->current_stage ? 'text-gray-700 font-medium' : 'text-gray-400' }}">{{ $stage['name'] ?? 'Stage '.($idx+1) }}</p>
                </div>
                @if(!$loop->last)<div class="w-8 h-0.5 bg-{{ $idx < $instance->current_stage ? 'green' : 'gray' }}-300 mt-[-16px]"></div>@endif
            @endforeach
        </div>
    </div>

    {{-- Actions Timeline --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Action History</h3>
        @foreach($instance->actions as $action)
        <div class="flex gap-4 py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
            <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 {{ $action->action === 'approve' ? 'bg-green-100' : ($action->action === 'reject' ? 'bg-red-100' : 'bg-gray-100') }}">
                <span class="material-symbols-outlined text-sm {{ $action->action === 'approve' ? 'text-green-600' : ($action->action === 'reject' ? 'text-red-600' : 'text-gray-500') }}">{{ $action->action === 'approve' ? 'check' : ($action->action === 'reject' ? 'close' : 'comment') }}</span>
            </div>
            <div>
                <p class="text-sm"><span class="font-medium">{{ $action->actor?->name }}</span> <span class="text-gray-500">{{ $action->action }}d</span> <span class="text-gray-400">{{ $action->stage_name }}</span></p>
                @if($action->comments)<p class="text-xs text-gray-600 mt-1">{{ $action->comments }}</p>@endif
                <p class="text-xs text-gray-400 mt-1">{{ $action->acted_at->diffForHumans() }}</p>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Act --}}
    @if($instance->status === 'active')
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Take Action</h3>
        <form method="POST" action="{{ route('risk.workflows.act', $instance) }}" class="space-y-3">@csrf
            <textarea name="comments" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Comments..."></textarea>
            <div class="flex gap-2">
                <button type="submit" name="action" value="approve" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium">Approve</button>
                <button type="submit" name="action" value="reject" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium">Reject</button>
                <button type="submit" name="action" value="comment" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium">Comment Only</button>
            </div>
        </form>
    </div>
    @endif
</div>
@endsection
