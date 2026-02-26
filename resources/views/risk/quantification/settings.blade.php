@extends('layouts.app')

@section('title', 'Quantification Settings - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Settings')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Settings</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Quantification Settings</h1>
        <p class="text-sm text-gray-500 mt-1">Configure Monte Carlo simulation parameters, thresholds, and CBN regulatory parameters</p>
    </div>

    <form method="POST" action="{{ route('risk.quantification.update-settings') }}">
        @csrf
        @method('PUT')

        {{-- Simulation Defaults --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Simulation Defaults</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="default_iterations" class="block text-sm font-medium text-gray-700 mb-2">Default Iterations</label>
                    <select id="default_iterations" name="default_iterations" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach ([1000, 5000, 10000, 50000, 100000] as $iter)
                            <option value="{{ $iter }}" {{ old('default_iterations', $settings->default_iterations ?? 10000) == $iter ? 'selected' : '' }}>{{ number_format($iter) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="default_confidence" class="block text-sm font-medium text-gray-700 mb-2">Default Confidence Level (%)</label>
                    <input type="number" step="0.1" id="default_confidence" name="default_confidence" value="{{ old('default_confidence', $settings->default_confidence ?? 99.5) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
                <div>
                    <label for="default_time_horizon" class="block text-sm font-medium text-gray-700 mb-2">Default Time Horizon (Years)</label>
                    <select id="default_time_horizon" name="default_time_horizon" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach ([1, 3, 5] as $horizon)
                            <option value="{{ $horizon }}" {{ old('default_time_horizon', $settings->default_time_horizon ?? 1) == $horizon ? 'selected' : '' }}>{{ $horizon }} Year{{ $horizon > 1 ? 's' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="seed" class="block text-sm font-medium text-gray-700 mb-2">Random Seed (for reproducibility)</label>
                    <input type="number" id="seed" name="seed" value="{{ old('seed', $settings->seed ?? '') }}" placeholder="Leave blank for random" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
            </div>
        </div>

        {{-- CBN Regulatory Parameters --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">CBN Regulatory Parameters</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="cbn_min_car" class="block text-sm font-medium text-gray-700 mb-2">CBN Minimum CAR (%)</label>
                    <input type="number" step="0.1" id="cbn_min_car" name="cbn_min_car" value="{{ old('cbn_min_car', $settings->cbn_min_car ?? 10.0) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
                <div>
                    <label for="target_car" class="block text-sm font-medium text-gray-700 mb-2">Target CAR (%)</label>
                    <input type="number" step="0.1" id="target_car" name="target_car" value="{{ old('target_car', $settings->target_car ?? 15.0) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
                <div>
                    <label for="conservation_buffer" class="block text-sm font-medium text-gray-700 mb-2">Capital Conservation Buffer (%)</label>
                    <input type="number" step="0.1" id="conservation_buffer" name="conservation_buffer" value="{{ old('conservation_buffer', $settings->conservation_buffer ?? 2.5) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
                <div>
                    <label for="countercyclical_buffer" class="block text-sm font-medium text-gray-700 mb-2">Countercyclical Buffer (%)</label>
                    <input type="number" step="0.1" id="countercyclical_buffer" name="countercyclical_buffer" value="{{ old('countercyclical_buffer', $settings->countercyclical_buffer ?? 0) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
            </div>
        </div>

        {{-- Alert Thresholds --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div><h2 class="text-lg font-semibold text-[#1A365D]">Alert Thresholds</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                    <label class="flex items-center gap-2 mb-2"><span class="w-3 h-3 rounded-full bg-green-500"></span><span class="text-sm font-semibold text-green-700">Green (CAR above)</span></label>
                    <input type="number" step="0.1" name="alert_green" value="{{ old('alert_green', $settings->alert_green ?? 15) }}" class="w-full px-3 py-2 border border-green-300 rounded-lg text-sm">
                </div>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <label class="flex items-center gap-2 mb-2"><span class="w-3 h-3 rounded-full bg-yellow-500"></span><span class="text-sm font-semibold text-yellow-700">Amber (CAR above)</span></label>
                    <input type="number" step="0.1" name="alert_amber" value="{{ old('alert_amber', $settings->alert_amber ?? 12) }}" class="w-full px-3 py-2 border border-yellow-300 rounded-lg text-sm">
                </div>
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                    <label class="flex items-center gap-2 mb-2"><span class="w-3 h-3 rounded-full bg-red-500"></span><span class="text-sm font-semibold text-red-700">Red (CAR below)</span></label>
                    <input type="number" step="0.1" name="alert_red" value="{{ old('alert_red', $settings->alert_red ?? 10) }}" class="w-full px-3 py-2 border border-red-300 rounded-lg text-sm">
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.quantification.dashboard') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Save Settings</button>
        </div>
    </form>
@endsection
