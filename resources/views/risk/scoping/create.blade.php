@extends('layouts.app')

@section('title', 'Create Entity - GRC Risk Management')
@section('page-section', 'Scoping')
@section('page-title', 'Create Entity')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.scoping.dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Scoping</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.scoping.index') }}" class="text-gray-500 hover:text-[#1A365D]">Entity Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Create Entity</span>
@endsection

@section('content')
    {{-- Validation Errors --}}
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-red-600">error</span>
                <span class="text-sm font-semibold text-red-700">Please correct the following errors:</span>
            </div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Create New Entity</h1>
        <p class="text-sm text-gray-500 mt-1">Define a new organizational entity within the risk management scope</p>
    </div>

    <form method="POST" action="{{ route('risk.scoping.store') }}">
        @csrf

        {{-- Section 1: Basic Information --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Basic Information</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Entity Code (auto-generated) --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Entity Code</label>
                    <input type="text" value="ENT-AUTO-GENERATED" disabled
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50 text-gray-500">
                    <p class="text-xs text-gray-500 mt-1">Auto-generated on save</p>
                </div>

                {{-- Entity Type --}}
                <div>
                    <label for="entity_type_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Entity Type <span class="text-red-500">*</span>
                    </label>
                    <select id="entity_type_id" name="entity_type_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('entity_type_id') border-red-500 @enderror">
                        <option value="">Select Type</option>
                        @foreach ($entityTypes as $type)
                            <option value="{{ $type->id }}" {{ old('entity_type_id') == $type->id ? 'selected' : '' }}>
                                {{ $type->name }} (L{{ $type->level }})
                            </option>
                        @endforeach
                    </select>
                    @error('entity_type_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Entity Name --}}
                <div class="lg:col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                        Entity Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}"
                           placeholder="e.g., Retail Banking Division"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('name') border-red-500 @enderror" required>
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Parent Entity --}}
                <div class="lg:col-span-2">
                    <label for="parent_id" class="block text-sm font-medium text-gray-700 mb-2">Parent Entity</label>
                    <select id="parent_id" name="parent_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('parent_id') border-red-500 @enderror">
                        <option value="">None (Root Entity)</option>
                        @foreach ($parentEntities as $pe)
                            <option value="{{ $pe->id }}" {{ old('parent_id') == $pe->id ? 'selected' : '' }}>
                                {{ $pe->entity_code }} &mdash; {{ $pe->name }} ({{ $pe->entityType->name ?? '' }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Leave empty for Group-level (root) entities</p>
                    @error('parent_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Description --}}
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="description" name="description" rows="3"
                              placeholder="Entity purpose, mandate, and key responsibilities..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror">{{ old('description') }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 2: Ownership & Delegation --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Ownership & Delegation</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Entity Owner --}}
                <div>
                    <label for="owner_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Entity Owner <span class="text-red-500">*</span>
                    </label>
                    <select id="owner_id" name="owner_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('owner_id') border-red-500 @enderror">
                        <option value="">Select Owner</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('owner_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Delegate Owner --}}
                <div>
                    <label for="delegate_owner_id" class="block text-sm font-medium text-gray-700 mb-2">Delegate Owner</label>
                    <select id="delegate_owner_id" name="delegate_owner_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('delegate_owner_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Status --}}
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <div class="flex gap-6">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="status" value="active" {{ old('status', 'active') === 'active' ? 'checked' : '' }}
                                   class="rounded-full border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                            <span class="text-sm text-gray-700">Active</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="status" value="inactive" {{ old('status') === 'inactive' ? 'checked' : '' }}
                                   class="rounded-full border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                            <span class="text-sm text-gray-700">Inactive</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 3: Regulatory Scope --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Regulatory Scope</h2>
            </div>
            <p class="text-sm text-gray-500 mb-4">Select applicable regulatory frameworks for this entity:</p>

            @php
                $frameworks = ['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'BOFIA', 'SEC Rules'];
                $oldFrameworks = old('regulatory_frameworks', []);
            @endphp

            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($frameworks as $framework)
                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer transition-colors">
                        <input type="checkbox" name="regulatory_frameworks[]" value="{{ $framework }}"
                               {{ in_array($framework, $oldFrameworks) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                        <span class="text-sm font-medium text-gray-700">{{ $framework }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Section 4: Risk Appetite --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">4</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Risk Appetite</h2>
            </div>

            {{-- Overall Risk Appetite --}}
            <div class="mb-6">
                <label for="risk_appetite_level" class="block text-sm font-medium text-gray-700 mb-2">Overall Risk Appetite</label>
                <select id="risk_appetite_level" name="risk_appetite_level"
                        class="w-full max-w-md px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    <option value="">Select Appetite Level</option>
                    @foreach (['averse' => 'Averse', 'minimal' => 'Minimal', 'cautious' => 'Cautious', 'open' => 'Open', 'hungry' => 'Hungry'] as $val => $label)
                        <option value="{{ $val }}" {{ old('risk_appetite_level') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Category-Level Appetites --}}
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-[#1A365D] mb-4">Category-Level Risk Appetite</h4>

                @php
                    $categories = ['credit' => 'Credit Risk', 'operational' => 'Operational Risk', 'market' => 'Market Risk', 'compliance' => 'Compliance Risk', 'technology' => 'Technology Risk'];
                    $oldCategoryAppetites = old('category_appetites', []);
                @endphp

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach ($categories as $key => $label)
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">{{ $label }}</label>
                            <select name="category_appetites[{{ $key }}]"
                                    class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                <option value="">Not Set</option>
                                @foreach (['averse' => 'Averse', 'minimal' => 'Minimal', 'cautious' => 'Cautious', 'open' => 'Open', 'hungry' => 'Hungry'] as $val => $lbl)
                                    <option value="{{ $val }}" {{ ($oldCategoryAppetites[$key] ?? '') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.scoping.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">save</span>
                Create Entity
            </button>
        </div>
    </form>
@endsection
