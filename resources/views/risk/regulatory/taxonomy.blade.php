@extends('layouts.app')
@section('title', 'Risk Taxonomy')
@section('content')
<div class="space-y-6">
    <h1 class="text-xl font-bold text-gray-900">Risk Taxonomy Management</h1>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Taxonomy Tree</h3>
            @forelse($taxonomies as $node)
                @include('risk.regulatory._taxonomy-node', ['node' => $node, 'depth' => 0])
            @empty
                <p class="text-gray-400 text-sm py-8 text-center">No taxonomy nodes yet. Add one to start.</p>
            @endforelse
        </div>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Taxonomy Node</h3>
            <form method="POST" action="{{ route('risk.regulatory.store-taxonomy') }}" class="space-y-3">@csrf
                <div><label class="text-xs text-gray-500">Name</label><input type="text" name="name" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
                <div><label class="text-xs text-gray-500">Description</label><textarea name="description" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea></div>
                <div><label class="text-xs text-gray-500">Framework</label><select name="framework" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="">None</option><option value="Basel III">Basel III</option><option value="COSO ERM">COSO ERM</option><option value="ISO 31000">ISO 31000</option><option value="CBN ORMS">CBN ORMS</option></select></div>
                <div><label class="text-xs text-gray-500">Parent Node</label><select name="parent_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="">Root Level</option>
                    @foreach(\App\Models\RiskTaxonomy::where('organization_id', auth()->user()->organization_id)->get() as $t)
                        <option value="{{ $t->id }}">{{ str_repeat('— ', $t->depth) }}{{ $t->name }}</option>
                    @endforeach</select></div>
                <button type="submit" class="w-full px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add Node</button>
            </form>
        </div>
    </div>
</div>
@endsection
