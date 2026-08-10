@extends('layouts.app')

@section('title', 'Edit Control - GRC Risk Management')
@section('page-section', 'Controls')
@section('page-title', 'Edit Control')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.controls.index') }}" class="hover:text-[#1A365D]">Controls</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.controls.show', $control) }}" class="hover:text-[#1A365D]">{{ Str::limit($control->name, 20) }}</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Edit</span>
@endsection

@section('content')
    {{-- WP-05 TASK 2 — rendered from object_attributes; see controls/create. --}}
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Edit Control</h1>
        <p class="text-sm text-gray-500 mt-1">Update control details for "{{ $control->name }}"</p>
    </div>

    <form method="POST" action="{{ route('risk.controls.update', $control) }}">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Control Details</h2>
            </div>

            <x-dynamic-form type="Control" :record="$control" />
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.controls.show', $control) }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Update Control</button>
        </div>
    </form>
@endsection
