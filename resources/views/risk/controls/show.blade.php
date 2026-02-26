@extends('layouts.app')

@section('title', ($control->name ?? 'Control Detail') . ' - GRC Risk Management')
@section('page-section', 'Controls')
@section('page-title', 'Control Detail')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.controls.index') }}" class="hover:text-[#1A365D]">Controls</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ Str::limit($control->name ?? 'Detail', 30) }}</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-[#1A365D]">{{ $control->control_code ?? 'CTL-' . $control->id }}</h1>
                    <span class="badge {{ ($control->control_type ?? '') === 'preventive' ? 'bg-blue-100 text-blue-700' : (($control->control_type ?? '') === 'detective' ? 'bg-purple-100 text-purple-700' : (($control->control_type ?? '') === 'corrective' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-700')) }}">{{ ucfirst($control->control_type ?? '-') }}</span>
                    <span class="badge {{ ($control->effectiveness_rating ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($control->effectiveness_rating ?? '') === 'partially_effective' ? 'bg-yellow-100 text-yellow-700' : (($control->effectiveness_rating ?? '') === 'ineffective' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600')) }}">{{ ucfirst(str_replace('_', ' ', $control->effectiveness_rating ?? 'not tested')) }}</span>
                    <span class="badge {{ ($control->status ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : (($control->status ?? '') === 'under_review' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">{{ ucfirst(str_replace('_', ' ', $control->status ?? 'active')) }}</span>
                </div>
                <p class="text-sm text-gray-600 mt-1">{{ $control->name }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.controls.edit', $control) }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">edit</span> Edit</a>
                <form method="POST" action="{{ route('risk.controls.destroy', $control) }}" onsubmit="return confirm('Are you sure you want to delete this control?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 border border-red-300 rounded-lg text-sm text-red-600 hover:bg-red-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">delete</span> Delete</button>
                </form>
                <a href="{{ route('risk.controls.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Control Description</h3>
                <p class="text-sm text-gray-700 leading-relaxed">{{ $control->description ?? 'No description provided.' }}</p>
            </div>

            {{-- Linked Risks --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Linked Risks</h3></div>
                <table class="data-table">
                    <thead><tr><th>Risk Code</th><th>Title</th><th>Residual Rating</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse (($control->risks ?? []) as $risk)
                            <tr>
                                <td class="font-medium text-[#1A365D]"><a href="{{ route('risk.register.show', $risk) }}" class="hover:underline">{{ $risk->risk_code }}</a></td>
                                <td class="text-xs">{{ Str::limit($risk->title, 40) }}</td>
                                <td><x-risk-badge :rating="$risk->residual_rating ?? 'medium'" /></td>
                                <td><x-status-badge :status="$risk->status ?? 'open'" /></td>
                            </tr>
                        @empty <tr><td colspan="4" class="text-center py-6 text-gray-400">No linked risks</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Control Information</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Type</dt><dd class="text-xs font-medium">{{ ucfirst($control->control_type ?? '-') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Nature</dt><dd class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $control->control_nature ?? '-')) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Frequency</dt><dd class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $control->frequency ?? '-')) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Owner</dt><dd class="text-xs font-medium">{{ $control->owner->name ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Business Unit</dt><dd class="text-xs font-medium">{{ $control->businessUnit->name ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Effectiveness</dt><dd class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $control->effectiveness_rating ?? '-')) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Status</dt><dd class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $control->status ?? 'active')) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Last Tested</dt><dd class="text-xs">{{ $control->last_test_date?->format('d M Y') ?? 'Never' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Next Test Due</dt><dd class="text-xs">{{ $control->next_test_due?->format('d M Y') ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Created</dt><dd class="text-xs">{{ $control->created_at?->format('d M Y') ?? '-' }}</dd></div>
                </dl>
            </div>
        </div>
    </div>
@endsection
