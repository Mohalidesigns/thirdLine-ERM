@extends('layouts.app')
@section('title', 'Create Questionnaire')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><a href="{{ route('risk.questionnaires.index') }}" class="hover:text-primary">Questionnaires</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Create</span>
@endsection
@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Create Questionnaire</h1>
    <form method="POST" action="{{ route('risk.questionnaires.store') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">
        @csrf
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label><input type="text" name="title" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea></div>
        <div class="grid grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="questionnaire_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="rcsa">RCSA</option><option value="fraud_risk">Fraud Risk</option><option value="compliance">Compliance</option><option value="new_product">New Product</option><option value="vendor">Vendor</option><option value="custom">Custom</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Scoring Method</label>
                <select name="scoring_method" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="average">Average</option><option value="weighted">Weighted</option><option value="highest">Highest</option><option value="sum">Sum</option>
                </select>
            </div>
        </div>
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.questionnaires.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium">Create</button></div>
    </form>
</div>
@endsection
