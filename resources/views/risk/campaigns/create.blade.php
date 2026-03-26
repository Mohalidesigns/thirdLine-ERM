@extends('layouts.app')
@section('title', 'Create Campaign')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.campaigns.index') }}" class="hover:text-primary">Campaigns</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Create</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Create Assessment Campaign</h1>

    <form method="POST" action="{{ route('risk.campaigns.store') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Campaign Title <span class="text-red-500">*</span></label>
            <input type="text" name="title" value="{{ old('title') }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., Q1 2026 RCSA Campaign">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">{{ old('description') }}</textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Campaign Type <span class="text-red-500">*</span></label>
                <select name="campaign_type" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="rcsa">RCSA</option>
                    <option value="fraud_risk">Fraud Risk Assessment</option>
                    <option value="compliance">Compliance Assessment</option>
                    <option value="new_product">New Product/Service Risk</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Questionnaire</label>
                <select name="questionnaire_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">None (Free-form)</option>
                    @foreach($questionnaires as $q)
                        <option value="{{ $q->id }}">{{ $q->title }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date <span class="text-red-500">*</span></label>
                <input type="date" name="start_date" value="{{ old('start_date', date('Y-m-d')) }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date <span class="text-red-500">*</span></label>
                <input type="date" name="end_date" value="{{ old('end_date') }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default Reviewer</label>
                <select name="reviewer_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">Select Reviewer</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
            <a href="{{ route('risk.campaigns.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">Create Campaign</button>
        </div>
    </form>
</div>
@endsection
