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
            <p class="text-xs text-gray-500 mb-6">These are the values the simulation setup form opens with. Any run may still override them.</p>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="default_iterations" class="block text-sm font-medium text-gray-700 mb-2">Default Iterations</label>
                    <select id="default_iterations" name="default_iterations" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach ($iterationChoices as $iter)
                            <option value="{{ $iter }}" {{ (int) old('default_iterations', $settings->default_iterations) === $iter ? 'selected' : '' }}>{{ number_format($iter) }}</option>
                        @endforeach
                    </select>
                    @error('default_iterations')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="default_horizon_years" class="block text-sm font-medium text-gray-700 mb-2">Default Time Horizon</label>
                    <select id="default_horizon_years" name="default_horizon_years" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach ($horizonChoices as $horizon)
                            <option value="{{ $horizon }}" {{ (int) old('default_horizon_years', $settings->default_horizon_years) === $horizon ? 'selected' : '' }}>{{ $horizon }} Year{{ $horizon > 1 ? 's' : '' }}</option>
                        @endforeach
                    </select>
                    @error('default_horizon_years')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Default Confidence Levels</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($confidenceChoices as $level)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="default_confidence_levels[]" value="{{ $level }}" class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                    {{ in_array($level, old('default_confidence_levels', $settings->default_confidence_levels)) ? 'checked' : '' }}>
                                <span class="text-sm text-gray-700">{{ $level }}%</span>
                            </label>
                        @endforeach
                    </div>
                    @error('default_confidence_levels')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- CBN Capital Parameters --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">CBN Capital Parameters</h2></div>
            <p class="text-xs text-gray-500 mb-6">
                The minimum CAR an ICAAP assessment is reconciled against when the assessment itself does not carry one.
                The CBN sets {{ config('quantification.default_minimum_car') }}% for a national or regional authorisation and
                {{ config('quantification.international_or_dsib_minimum_car') }}% for an international authorisation or a D-SIB designation.
                Nothing here infers which applies to this institution.
            </p>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="cbn_minimum_car" class="block text-sm font-medium text-gray-700 mb-2">Minimum CAR (%)</label>
                    <input type="number" step="0.1" id="cbn_minimum_car" name="cbn_minimum_car" value="{{ old('cbn_minimum_car', $settings->cbn_minimum_car) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    @error('cbn_minimum_car')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="cbn_conservation_buffer" class="block text-sm font-medium text-gray-700 mb-2">Capital Conservation Buffer (%)</label>
                    <input type="number" step="0.1" id="cbn_conservation_buffer" name="cbn_conservation_buffer" value="{{ old('cbn_conservation_buffer', $settings->cbn_conservation_buffer) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    @error('cbn_conservation_buffer')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.quantification.dashboard') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Save Settings</button>
        </div>
    </form>
@endsection
