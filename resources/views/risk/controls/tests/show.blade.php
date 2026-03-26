@extends('layouts.app')
@section('title', 'Control Test: ' . $controlTest->test_code)
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.control-tests.index') }}" class="hover:text-primary">Control Tests</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">{{ $controlTest->test_code }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $controlTest->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $controlTest->test_code }} &middot; {{ ucfirst(str_replace('_', ' ', $controlTest->test_type)) }}</p>
        </div>
        <div class="flex gap-2">
            @if($controlTest->status === 'scheduled')
                <form method="POST" action="{{ route('risk.control-tests.start', $controlTest) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Start Test</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main Info --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Test Details</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-500">Control:</span> <span class="font-medium">{{ $controlTest->control?->name }}</span></div>
                    <div><span class="text-gray-500">Test Type:</span> <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $controlTest->test_type)) }}</span></div>
                    <div><span class="text-gray-500">Tester:</span> <span class="font-medium">{{ $controlTest->tester?->name ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Reviewer:</span> <span class="font-medium">{{ $controlTest->reviewer?->name ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Scheduled:</span> <span class="font-medium">{{ $controlTest->scheduled_date->format('M d, Y') }}</span></div>
                    <div><span class="text-gray-500">Completed:</span> <span class="font-medium">{{ $controlTest->completed_date?->format('M d, Y') ?? '—' }}</span></div>
                </div>
                @if($controlTest->description)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-sm text-gray-700">{{ $controlTest->description }}</p>
                    </div>
                @endif
            </div>

            {{-- Complete Test Form --}}
            @if($controlTest->status === 'in_progress')
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Record Test Results</h3>
                <form method="POST" action="{{ route('risk.control-tests.complete', $controlTest) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Result <span class="text-red-500">*</span></label>
                        <select name="result" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="effective">Effective</option>
                            <option value="partially_effective">Partially Effective</option>
                            <option value="ineffective">Ineffective</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Score (0-100)</label>
                        <input type="number" name="score" min="0" max="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Findings</label>
                        <textarea name="findings" rows="4" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Describe test findings..."></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Recommendations</label>
                        <textarea name="recommendations" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Recommendations for improvement..."></textarea>
                    </div>
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Complete Test</button>
                </form>
            </div>
            @endif

            {{-- Results Display --}}
            @if($controlTest->status === 'completed' || $controlTest->status === 'pending_review')
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Test Results</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-500">Result:</span>
                        @if($controlTest->result === 'effective')
                            <span class="badge bg-green-100 text-green-700 text-sm">Effective</span>
                        @elseif($controlTest->result === 'partially_effective')
                            <span class="badge bg-yellow-100 text-yellow-700 text-sm">Partially Effective</span>
                        @else
                            <span class="badge bg-red-100 text-red-700 text-sm">Ineffective</span>
                        @endif
                        @if($controlTest->score)
                            <span class="text-sm font-semibold ml-2">Score: {{ $controlTest->score }}/100</span>
                        @endif
                    </div>
                    @if($controlTest->findings)
                        <div class="pt-3 border-t border-gray-100">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Findings</h4>
                            <p class="text-sm text-gray-700">{{ $controlTest->findings }}</p>
                        </div>
                    @endif
                    @if($controlTest->recommendations)
                        <div class="pt-3 border-t border-gray-100">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Recommendations</h4>
                            <p class="text-sm text-gray-700">{{ $controlTest->recommendations }}</p>
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Status Card --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Status</h3>
                @php $statusColors = ['scheduled'=>'gray','in_progress'=>'blue','pending_review'=>'yellow','completed'=>'green','cancelled'=>'red']; @endphp
                <span class="badge bg-{{ $statusColors[$controlTest->status] ?? 'gray' }}-100 text-{{ $statusColors[$controlTest->status] ?? 'gray' }}-700 text-sm">
                    {{ ucfirst(str_replace('_', ' ', $controlTest->status)) }}
                </span>
            </div>

            {{-- Evidence --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Evidence</h3>
                @foreach($controlTest->evidence as $ev)
                    <div class="flex items-center gap-2 py-2 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                        <span class="material-symbols-outlined text-gray-400 text-lg">attach_file</span>
                        <div>
                            <p class="text-xs font-medium text-gray-700">{{ $ev->file_name }}</p>
                            <p class="text-[10px] text-gray-400">{{ $ev->uploader?->name }} &middot; {{ $ev->created_at->format('M d') }}</p>
                        </div>
                    </div>
                @endforeach

                @if(in_array($controlTest->status, ['in_progress', 'pending_review']))
                <form method="POST" action="{{ route('risk.control-tests.upload-evidence', $controlTest) }}" enctype="multipart/form-data" class="mt-3 pt-3 border-t border-gray-100">
                    @csrf
                    <input type="file" name="file" required class="text-xs w-full">
                    <input type="text" name="description" placeholder="Description..." class="w-full border border-gray-200 rounded px-2 py-1 text-xs mt-2">
                    <button type="submit" class="mt-2 px-3 py-1 bg-primary text-white text-xs rounded hover:bg-opacity-90">Upload</button>
                </form>
                @endif
            </div>

            {{-- Related Risks --}}
            @if($controlTest->control?->risks?->count())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Related Risks</h3>
                @foreach($controlTest->control->risks as $risk)
                    <a href="{{ route('risk.register.show', $risk) }}" class="block py-2 text-xs text-primary hover:underline {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                        {{ $risk->risk_code }} — {{ Str::limit($risk->title, 35) }}
                    </a>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
