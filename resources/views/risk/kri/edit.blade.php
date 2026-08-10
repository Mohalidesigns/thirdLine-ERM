@extends('layouts.app')

@section('title', 'Edit KRI - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Edit KRI')

@php
    // Compute single threshold values from min/max pairs for form display
    $isHigherWorse = ($kri->direction ?? $kri->threshold_direction ?? 'higher_worse') === 'higher_is_worse'
                  || ($kri->direction ?? $kri->threshold_direction ?? 'higher_worse') === 'higher_worse';
    $greenVal = $isHigherWorse ? $kri->green_threshold_max : $kri->green_threshold_min;
    $amberVal = $isHigherWorse ? $kri->amber_threshold_max : $kri->amber_threshold_max;
    $redVal   = $isHigherWorse ? $kri->red_threshold_min   : $kri->red_threshold_max;

    // Map direction to the controller's enum values
    $directionValue = 'higher_is_worse';
    $rawDir = $kri->direction ?? $kri->threshold_direction ?? '';
    if (in_array($rawDir, ['lower_is_worse', 'lower_worse'])) {
        $directionValue = 'lower_is_worse';
    }
@endphp

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.index') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.show', $kri) }}" class="hover:text-[#1A365D]">{{ Str::limit($kri->kri_name ?? $kri->name, 25) }}</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Edit</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Edit KRI: {{ $kri->kri_name ?? $kri->name }}</h1>
        <p class="text-sm text-gray-500 mt-1">Update key risk indicator configuration and thresholds</p>
    </div>

    <form method="POST" action="{{ route('risk.kri.update', $kri) }}">
        @csrf
        @method('PUT')

        {{--
            WP-05 TASK 2 — rendered from object_attributes on the
            KeyRiskIndicator type.

            risk_id is excluded: KriController::update() does not accept it, so
            offering it here would be an editable field that silently never
            saves. Re-pointing an indicator at a different risk is a move, not
            an edit, and has its own action.
        --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <x-dynamic-form type="KeyRiskIndicator" :record="$kri" :omit="['risk_id']" />
        </div>


        <div class="flex items-center justify-between">
            <a href="{{ route('risk.kri.show', $kri) }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Update KRI</button>
        </div>
    </form>
@endsection
