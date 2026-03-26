@extends('layouts.app')
@section('title', 'Create Workflow')
@section('content')
<div class="max-w-3xl mx-auto" x-data="{ stages: [{ name: '', approver_role: 'risk-officer' }] }">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Create Workflow Definition</h1>
    <form method="POST" action="{{ route('risk.workflows.store-definition') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">@csrf
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Workflow Name</label><input type="text" name="name" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea></div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Entity Type</label>
            <select name="entity_type" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="risk">Risk</option><option value="treatment_plan">Treatment Plan</option><option value="loss_event">Loss Event</option><option value="issue">Issue</option><option value="control_test">Control Test</option><option value="campaign">Campaign</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Approval Stages</label>
            <template x-for="(stage, index) in stages" :key="index">
                <div class="flex items-center gap-3 mb-3">
                    <span class="text-xs text-gray-400 w-6" x-text="index + 1"></span>
                    <input type="text" x-model="stage.name" :name="'stages['+index+'][name]'" required placeholder="Stage name..." class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <select x-model="stage.approver_role" :name="'stages['+index+'][approver_role]'" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <option value="risk-officer">Risk Officer</option><option value="chief-risk-officer">Chief Risk Officer</option><option value="business-unit-manager">BU Manager</option><option value="compliance-officer">Compliance Officer</option><option value="approver">Approver</option>
                    </select>
                    <button type="button" @click="stages.splice(index, 1)" x-show="stages.length > 1" class="text-red-400 hover:text-red-600"><span class="material-symbols-outlined text-lg">delete</span></button>
                </div>
            </template>
            <button type="button" @click="stages.push({ name: '', approver_role: 'risk-officer' })" class="text-sm text-primary hover:underline">+ Add Stage</button>
        </div>
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.workflows.definitions') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium">Create Workflow</button></div>
    </form>
</div>
@endsection
