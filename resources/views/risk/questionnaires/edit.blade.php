@extends('layouts.app')
@section('title', 'Edit Questionnaire')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><a href="{{ route('risk.questionnaires.index') }}" class="hover:text-primary">Questionnaires</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Edit</span>
@endsection
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $questionnaire->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">v{{ $questionnaire->version }} &middot; {{ ucfirst($questionnaire->status) }}</p>
        </div>
        @if($questionnaire->status === 'draft')
        <form method="POST" action="{{ route('risk.questionnaires.publish', $questionnaire) }}">@csrf
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Publish</button>
        </form>
        @endif
    </div>

    {{-- Add Section --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Section</h3>
        <form method="POST" action="{{ route('risk.questionnaires.add-section', $questionnaire) }}" class="flex items-end gap-4">
            @csrf
            <div class="flex-1"><label class="text-xs text-gray-500">Section Title</label><input type="text" name="title" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., Operational Risk"></div>
            <div class="w-32"><label class="text-xs text-gray-500">Weight</label><input type="number" name="weight" value="1" step="0.01" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add Section</button>
        </form>
    </div>

    {{-- Sections & Questions --}}
    @foreach($questionnaire->sections as $section)
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">{{ $section->title }} <span class="text-xs text-gray-400 ml-2">Weight: {{ $section->weight }}</span></h3>
            <span class="text-xs text-gray-500">{{ $section->questions->count() }} questions</span>
        </div>

        {{-- Existing Questions --}}
        <div class="px-5 py-3 space-y-2">
            @foreach($section->questions as $q)
            <div class="flex items-center justify-between py-2 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                <div>
                    <p class="text-sm text-gray-800">{{ $q->question_text }}</p>
                    <p class="text-xs text-gray-400">{{ ucfirst(str_replace('_', ' ', $q->question_type)) }} {{ $q->is_required ? '(Required)' : '' }}</p>
                </div>
                <form method="POST" action="{{ route('risk.questionnaires.remove-question', $q) }}">@csrf @method('DELETE')
                    <button type="submit" class="text-red-400 hover:text-red-600"><span class="material-symbols-outlined text-lg">delete</span></button>
                </form>
            </div>
            @endforeach
        </div>

        {{-- Add Question --}}
        <div class="px-5 py-4 border-t border-gray-100 bg-gray-50 rounded-b-xl">
            <form method="POST" action="{{ route('risk.questionnaires.add-question', $section) }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <div class="md:col-span-2"><input type="text" name="question_text" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Enter question..."></div>
                    <div>
                        <select name="question_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="likert">Likert Scale</option><option value="rating">Rating (1-5)</option><option value="yes_no">Yes/No</option><option value="multiple_choice">Multiple Choice</option><option value="free_text">Free Text</option><option value="numeric">Numeric</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add Question</button>
                </div>
            </form>
        </div>
    </div>
    @endforeach

    @if($questionnaire->sections->isEmpty())
    <div class="text-center py-12 text-gray-400">
        <span class="material-symbols-outlined text-4xl mb-2">quiz</span>
        <p>Add sections above to start building your questionnaire</p>
    </div>
    @endif
</div>
@endsection
