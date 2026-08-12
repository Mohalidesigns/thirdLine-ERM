@extends('layouts.app')
@section('title', 'Question Library')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Question Library</span>
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
            <h1 class="text-xl font-bold text-gray-900">Question Library</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} {{ Str::plural('question', $total) }} available to reuse</p>
        </div>
    </div>

    @can('questionnaire.create')
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            <form method="POST" action="{{ route('risk.questionnaires.store-library') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">@csrf
                <div class="md:col-span-2"><label class="text-xs text-gray-500" for="question_text">Question</label><input id="question_text" type="text" name="question_text" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
                <div><label class="text-xs text-gray-500" for="category">Category</label><input id="category" type="text" name="category" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., Operational Risk"></div>
                <div><label class="text-xs text-gray-500" for="question_type">Type</label><select id="question_type" name="question_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="likert">Likert</option><option value="rating">Rating</option><option value="yes_no">Yes/No</option><option value="free_text">Free Text</option></select></div>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add to Library</button>
            </form>
        </div>
    @endcan

    {{-- WP-09: shared grid — see App\Grids\Definitions\QuestionLibraryGrid. --}}
    <x-data-grid grid="question_library" />
</div>
@endsection
