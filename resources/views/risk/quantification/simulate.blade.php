@extends('layouts.app')

@section('title', 'Run Simulation - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Run Simulation')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Run Simulation</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Configure Monte Carlo Simulation</h1>
        <p class="text-sm text-gray-500 mt-1">Set up simulation parameters and select scenarios to include</p>
    </div>

    <form method="POST" action="{{ route('risk.quantification.run-simulation') }}">
        @csrf

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Simulation Configuration</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Simulation Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', 'Simulation - ' . now()->format('Y-m-d H:i')) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="iterations" class="block text-sm font-medium text-gray-700 mb-2">Number of Iterations <span class="text-red-500">*</span></label>
                    <select id="iterations" name="iterations" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="1000" {{ old('iterations') == '1000' ? 'selected' : '' }}>1,000 (Quick)</option>
                        <option value="10000" {{ old('iterations', '10000') == '10000' ? 'selected' : '' }}>10,000 (Standard)</option>
                        <option value="50000" {{ old('iterations') == '50000' ? 'selected' : '' }}>50,000 (High precision)</option>
                        <option value="100000" {{ old('iterations') == '100000' ? 'selected' : '' }}>100,000 (Maximum precision)</option>
                    </select>
                    @error('iterations')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="time_horizon" class="block text-sm font-medium text-gray-700 mb-2">Time Horizon <span class="text-red-500">*</span></label>
                    <select id="time_horizon" name="time_horizon" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="1" {{ old('time_horizon', '1') == '1' ? 'selected' : '' }}>1 Year</option>
                        <option value="3" {{ old('time_horizon') == '3' ? 'selected' : '' }}>3 Years</option>
                        <option value="5" {{ old('time_horizon') == '5' ? 'selected' : '' }}>5 Years</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Confidence Levels</label>
                    <div class="flex flex-wrap gap-3">
                        @foreach ([90, 95, 99, 99.5, 99.9] as $level)
                            <label class="flex items-center gap-2"><input type="checkbox" name="confidence_levels[]" value="{{ $level }}" class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]" {{ in_array($level, old('confidence_levels', [95, 99, 99.5])) ? 'checked' : '' }}><span class="text-sm text-gray-700">{{ $level }}%</span></label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">Select Scenarios</h2></div>
            <p class="text-sm text-gray-500 mb-4">Choose which risk scenarios to include in this simulation</p>

            @forelse (($scenarios ?? []) as $scenario)
                <label class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg mb-3 cursor-pointer hover:bg-blue-50 transition-colors">
                    <input type="checkbox" name="scenario_ids[]" value="{{ $scenario->id }}" class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                           {{ in_array($scenario->id, old('scenario_ids', request('scenario') ? [$scenario->id] : [])) ? 'checked' : '' }}>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-700">{{ $scenario->name }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $scenario->risk_category }} &middot; {{ ucfirst($scenario->distribution_type) }} &middot; Mean: ₦{{ number_format($scenario->mean ?? 0) }}</p>
                    </div>
                    <span class="badge bg-blue-100 text-blue-700">{{ ucfirst($scenario->distribution_type ?? '-') }}</span>
                </label>
            @empty
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">category</span>
                    <p class="text-sm">No scenarios available. <a href="{{ route('risk.quantification.create-scenario') }}" class="text-[#1A365D] hover:underline">Create a scenario first</a></p>
                </div>
            @endforelse
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.quantification.dashboard') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">play_arrow</span> Run Simulation
            </button>
        </div>
    </form>
@endsection
