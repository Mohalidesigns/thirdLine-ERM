@extends('layouts.app')
@section('title', 'Questionnaire: ' . $questionnaire->title)
@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <h1 class="text-xl font-bold text-gray-900">{{ $questionnaire->title }}</h1>
    @foreach($questionnaire->sections as $section)
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ $section->title }}</h3>
        @foreach($section->questions as $q)
        <div class="py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
            <p class="text-sm text-gray-800">{{ $loop->iteration }}. {{ $q->question_text }} @if($q->is_required)<span class="text-red-500">*</span>@endif</p>
            <p class="text-xs text-gray-400 mt-1">{{ ucfirst(str_replace('_', ' ', $q->question_type)) }}</p>
        </div>
        @endforeach
    </div>
    @endforeach
</div>
@endsection
