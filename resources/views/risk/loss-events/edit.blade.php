@extends('layouts.app')

@section('title', 'Edit ' . ($lossEvent->event_reference ?? 'Loss Event') . ' - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <a href="{{ url('/risk/loss-events/' . $lossEvent->id) }}" class="hover:text-[#1A365D]">{{ $lossEvent->event_reference ?? 'Detail' }}</a>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Edit</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Edit Loss Event</h1>
            <p class="text-sm text-gray-500 mt-1">Update details for {{ $lossEvent->event_reference }}</p>
        </div>
        <a href="{{ url('/risk/loss-events/' . $lossEvent->id) }}" class="flex items-center gap-1 text-xs text-gray-500 hover:text-[#1A365D]">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Event
        </a>
    </div>

    {{-- Validation Errors --}}
    @if ($errors->any())
        <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 rounded-lg">
            <div class="flex items-center gap-2 text-red-700 text-sm font-medium mb-2">
                <span class="material-symbols-outlined text-lg">error</span>
                Please correct the following errors:
            </div>
            <ul class="list-disc list-inside text-xs text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/risk/loss-events/' . $lossEvent->id) }}" id="lossEventEditForm">
        @csrf
        @method('PUT')

        {{-- Basic Information --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">info</span>
                <h2 class="text-base font-bold text-[#1A365D]">Basic Information</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Event Title --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Event Title <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="event_title" value="{{ old('event_title', $lossEvent->title ?? $lossEvent->event_title) }}" required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('event_title') border-red-300 @enderror"
                           placeholder="Brief title describing the loss event">
                    @error('event_title')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Description <span class="text-red-500">*</span>
                    </label>
                    <textarea name="event_description" rows="4" required
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('event_description') border-red-300 @enderror"
                              placeholder="Detailed description of what happened">{{ old('event_description', $lossEvent->description ?? $lossEvent->event_description) }}</textarea>
                    @error('event_description')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Date of Loss --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Date of Loss <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="date_of_loss"
                           value="{{ old('date_of_loss', $lossEvent->date_of_loss?->format('Y-m-d')) }}" required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('date_of_loss') border-red-300 @enderror">
                    @error('date_of_loss')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Date Discovered --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Date Discovered <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="date_discovered"
                           value="{{ old('date_discovered', $lossEvent->date_discovered?->format('Y-m-d')) }}" required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('date_discovered') border-red-300 @enderror">
                    @error('date_discovered')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Business Unit --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Business Unit <span class="text-red-500">*</span>
                    </label>
                    @php $currentBU = old('business_unit_id', $lossEvent->business_unit_id); @endphp
                    <select name="business_unit_id" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('business_unit_id') border-red-300 @enderror">
                        <option value="">Select Business Unit</option>
                        @foreach (($businessUnits ?? []) as $unit)
                            <option value="{{ $unit->id }}" {{ $currentBU == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('business_unit_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Linked Risk --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Linked Risk</label>
                    @php $currentRisk = old('risk_id', $lossEvent->risk_register_id); @endphp
                    <select name="risk_id"
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach (($risks ?? []) as $risk)
                            <option value="{{ $risk->id }}" {{ $currentRisk == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ $risk->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Classification --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">category</span>
                <h2 class="text-base font-bold text-[#1A365D]">Classification</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Basel Event Type --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Basel Event Type <span class="text-red-500">*</span>
                    </label>
                    @php $currentBasel = old('basel_event_type', $lossEvent->basel_event_type); @endphp
                    <select name="basel_event_type" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('basel_event_type') border-red-300 @enderror">
                        <option value="">Select Type</option>
                        @foreach ([
                            'internal_fraud' => 'Internal Fraud',
                            'external_fraud' => 'External Fraud',
                            'employment_practices' => 'Employment Practices & Workplace Safety',
                            'clients_products' => 'Clients, Products & Business Practices',
                            'damage_physical_assets' => 'Damage to Physical Assets',
                            'business_disruption' => 'Business Disruption & System Failures',
                            'execution_delivery' => 'Execution, Delivery & Process Management',
                        ] as $val => $label)
                            <option value="{{ $val }}" {{ $currentBasel === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('basel_event_type')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- CBN Loss Category --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">CBN Loss Category</label>
                    <input type="text" name="cbn_loss_category" value="{{ old('cbn_loss_category', $lossEvent->cbn_loss_category) }}"
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                           placeholder="e.g., Compliance Failure">
                </div>

                {{-- Event Type --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Event Type <span class="text-red-500">*</span>
                    </label>
                    @php $currentType = old('event_type', $lossEvent->event_type ?? $lossEvent->loss_category); @endphp
                    <select name="event_type" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('event_type') border-red-300 @enderror">
                        <option value="">Select Type</option>
                        @foreach ([
                            'actual_loss' => 'Actual Loss',
                            'potential_loss' => 'Potential Loss',
                            'near_miss' => 'Near Miss',
                            'gain_event' => 'Gain Event',
                        ] as $val => $label)
                            <option value="{{ $val }}" {{ $currentType === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('event_type')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Severity --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Severity <span class="text-red-500">*</span>
                    </label>
                    @php $currentSev = old('severity', strtolower($lossEvent->severity ?? $lossEvent->event_severity ?? '')); @endphp
                    <select name="severity" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('severity') border-red-300 @enderror">
                        <option value="">Select Severity</option>
                        @foreach (['insignificant' => 'Insignificant', 'minor' => 'Minor', 'moderate' => 'Moderate', 'major' => 'Major', 'catastrophic' => 'Catastrophic'] as $val => $label)
                            <option value="{{ $val }}" {{ $currentSev === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('severity')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Financial Impact --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">payments</span>
                <h2 class="text-base font-bold text-[#1A365D]">Financial Impact</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Currency --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Currency <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="currency" value="{{ old('currency', $lossEvent->currency ?? 'NGN') }}" required maxlength="3"
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>

                {{-- Gross Loss Amount --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Gross Loss Amount <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-medium">&#8358;</span>
                        <input type="number" step="0.01" name="gross_loss_amount"
                               value="{{ old('gross_loss_amount', $lossEvent->gross_loss_amount ?? 0) }}" required
                               class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('gross_loss_amount') border-red-300 @enderror"
                               placeholder="0.00">
                    </div>
                    @error('gross_loss_amount')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Recovery Amount --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Recovery Amount</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-medium">&#8358;</span>
                        <input type="number" step="0.01" name="recovery_amount"
                               value="{{ old('recovery_amount', $lossEvent->recovery_amount ?? 0) }}"
                               class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               placeholder="0.00">
                    </div>
                </div>

                {{-- Insurance Recovery --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Insurance Recovery</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-medium">&#8358;</span>
                        <input type="number" step="0.01" name="insurance_recovery"
                               value="{{ old('insurance_recovery', $lossEvent->insurance_recovery ?? 0) }}"
                               class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               placeholder="0.00">
                    </div>
                </div>
            </div>
        </div>

        {{-- Root Cause & Corrective Action --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">psychology</span>
                <h2 class="text-base font-bold text-[#1A365D]">Root Cause & Corrective Action</h2>
            </div>

            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Root Cause Summary</label>
                    <textarea name="root_cause_summary" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Summary of the root cause analysis">{{ old('root_cause_summary', $lossEvent->root_cause_summary ?? $lossEvent->initial_root_cause) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Corrective Action Summary</label>
                    <textarea name="corrective_action_summary" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Summary of corrective actions taken or planned">{{ old('corrective_action_summary', $lossEvent->corrective_action_summary) }}</textarea>
                </div>
            </div>
        </div>

        {{-- Regulatory Flags --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-red-500">gavel</span>
                <h2 class="text-base font-bold text-[#1A365D]">Regulatory Reporting Flags</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer hover:border-[#1A365D]/30">
                    <input type="checkbox" name="is_regulatory_reportable" value="1"
                           {{ old('is_regulatory_reportable', $lossEvent->is_regulatory_reportable ?? $lossEvent->cbn_reportable) ? 'checked' : '' }}
                           class="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                    <div>
                        <div class="text-xs font-semibold text-gray-700">Regulatory Reportable</div>
                        <div class="text-[10px] text-gray-500 mt-0.5">Flag this event for regulatory reporting</div>
                    </div>
                </label>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Regulatory Body</label>
                    <input type="text" name="regulatory_body" value="{{ old('regulatory_body', $lossEvent->regulatory_body) }}"
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                           placeholder="e.g., CBN, NFIU, NDIC">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Reporting Deadline</label>
                    <input type="date" name="reporting_deadline"
                           value="{{ old('reporting_deadline', $lossEvent->reporting_deadline?->format('Y-m-d') ?? '') }}"
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ url('/risk/loss-events/' . $lossEvent->id) }}"
               class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                Cancel
            </a>
            <button type="submit"
                    class="flex items-center gap-2 px-6 py-2.5 bg-[#1A365D] text-white rounded-lg text-sm font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-lg">save</span>
                Save Changes
            </button>
        </div>
    </form>
@endsection
