@extends('layouts.app')

@section('title', 'Create Scenario - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Create Scenario')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.scenarios') }}" class="hover:text-[#1A365D]">Scenarios</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Create Scenario</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Create Risk Scenario</h1>
        <p class="text-sm text-gray-500 mt-1">Define a loss distribution scenario for quantitative risk analysis</p>
    </div>

    <form method="POST" action="{{ route('risk.quantification.store-scenario') }}">
        @csrf

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Scenario Details</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="lg:col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Scenario Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="e.g. Credit Default - Oil & Gas Sector" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('name') border-red-500 @enderror" required>
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description <span class="text-red-500">*</span></label>
                    <textarea id="description" name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror" required>{{ old('description') }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="risk_category" class="block text-sm font-medium text-gray-700 mb-2">Risk Category <span class="text-red-500">*</span></label>
                    <select id="risk_category" name="risk_category" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="">Select Category</option>
                        @foreach (['Credit Risk', 'Market Risk', 'Operational Risk', 'Liquidity Risk', 'Strategic Risk'] as $cat)
                            <option value="{{ $cat }}" {{ old('risk_category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                    @error('risk_category')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="linked_risk_id" class="block text-sm font-medium text-gray-700 mb-2">Linked Risk</label>
                    <select id="linked_risk_id" name="linked_risk_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Risk (Optional)</option>
                        @foreach (($risks ?? []) as $risk)
                            <option value="{{ $risk->id }}" {{ old('linked_risk_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ Str::limit($risk->title, 40) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">Distribution Parameters</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="distribution_type" class="block text-sm font-medium text-gray-700 mb-2">Distribution Type <span class="text-red-500">*</span></label>
                    {{-- WP-08. This list used to offer normal, poisson, pareto, weibull
                         and beta alongside log-normal. MonteCarloService has one severity
                         draw — lognormalRandom() — and calls it unconditionally, so
                         picking "Pareto" stored the word 'pareto' and then simulated a
                         log-normal. A scenario calibrated for a heavy tail was quantified
                         with a light one, and nothing said so. The five come back when the
                         engine implements them. --}}
                    <select id="distribution_type" name="distribution_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="lognormal" selected>Log-Normal</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        Log-normal is the only severity distribution the simulation engine implements. Normal,
                        Poisson, Pareto, Weibull and Beta are not offered because selecting them would still have
                        run a log-normal.
                    </p>
                    @error('distribution_type')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="frequency_per_year" class="block text-sm font-medium text-gray-700 mb-2">Expected Frequency (per year) <span class="text-red-500">*</span></label>
                    <input type="number" step="0.1" id="frequency_per_year" name="frequency_per_year" value="{{ old('frequency_per_year') }}" placeholder="e.g. 2.5"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                    @error('frequency_per_year')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="mean" class="block text-sm font-medium text-gray-700 mb-2">Mean Loss (₦) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">&#8358;</span>
                        <input type="number" id="mean" name="mean" value="{{ old('mean') }}" placeholder="e.g. 50000000"
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                    </div>
                    @error('mean')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="std_dev" class="block text-sm font-medium text-gray-700 mb-2">Standard Deviation (₦) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">&#8358;</span>
                        <input type="number" id="std_dev" name="std_dev" value="{{ old('std_dev') }}" placeholder="e.g. 15000000"
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                    </div>
                    @error('std_dev')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="min_loss" class="block text-sm font-medium text-gray-700 mb-2">Minimum Loss (₦)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">&#8358;</span>
                        <input type="number" id="min_loss" name="min_loss" value="{{ old('min_loss') }}" class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                </div>
                <div>
                    <label for="max_loss" class="block text-sm font-medium text-gray-700 mb-2">Maximum Loss (₦)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">&#8358;</span>
                        <input type="number" id="max_loss" name="max_loss" value="{{ old('max_loss') }}" class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.quantification.scenarios') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Create Scenario</button>
        </div>
    </form>
@endsection
