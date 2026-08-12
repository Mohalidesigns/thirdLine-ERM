@extends('layouts.app')
@section('title', 'Questionnaires')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Questionnaires</span>
@endsection
@section('content')
<div class="space-y-6">
    @if (session('success'))
        <div class="p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Questionnaire Library</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} {{ Str::plural('questionnaire', $total) }}</p>
        </div>
        @can('questionnaire.create')
            <a href="{{ route('risk.questionnaires.create') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90"><span class="material-symbols-outlined text-lg">add_circle</span> New Questionnaire</a>
        @endcan
    </div>

    {{-- WP-09: shared grid — see App\Grids\Definitions\QuestionnairesGrid. --}}
    <x-data-grid grid="questionnaires" />
</div>
@endsection
