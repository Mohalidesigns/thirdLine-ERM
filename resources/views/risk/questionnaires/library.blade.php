@extends('layouts.app')
@section('title', 'Question Library')
@section('content')
<div class="space-y-6">
    <h1 class="text-xl font-bold text-gray-900">Question Library</h1>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <form method="POST" action="{{ route('risk.questionnaires.store-library') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">@csrf
            <div class="md:col-span-2"><label class="text-xs text-gray-500">Question</label><input type="text" name="question_text" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
            <div><label class="text-xs text-gray-500">Category</label><input type="text" name="category" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., Operational Risk"></div>
            <div><label class="text-xs text-gray-500">Type</label><select name="question_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="likert">Likert</option><option value="rating">Rating</option><option value="yes_no">Yes/No</option><option value="free_text">Free Text</option></select></div>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add to Library</button>
        </form>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Category</th><th>Question</th><th>Type</th><th>Usage</th></tr></thead><tbody>
            @forelse($library as $q)
            <tr><td><span class="badge bg-blue-50 text-blue-700">{{ $q->category }}</span></td><td class="text-sm">{{ Str::limit($q->question_text, 80) }}</td><td class="text-xs">{{ ucfirst(str_replace('_', ' ', $q->question_type)) }}</td><td>{{ $q->usage_count }}</td></tr>
            @empty
            <tr><td colspan="4" class="text-center py-8 text-gray-400">No questions in library</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div>{{ $library->links() }}</div>
</div>
@endsection
