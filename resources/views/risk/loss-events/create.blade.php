@extends('layouts.app')

@section('title', 'Log New Loss Event - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Log New Event</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Log New Loss Event</h1>
            <p class="text-sm text-gray-500 mt-1">Capture operational loss event details for Basel II/III and CBN ORMS compliance</p>
        </div>
        <a href="{{ url('/risk/loss-events') }}" class="flex items-center gap-1 text-xs text-gray-500 hover:text-[#1A365D]">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Register
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

    {{-- Step Indicator --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
        <div class="flex items-center justify-between">
            @foreach ([
                ['step' => 1, 'label' => 'Basic Information', 'icon' => 'info'],
                ['step' => 2, 'label' => 'Classification', 'icon' => 'category'],
                ['step' => 3, 'label' => 'Financial Impact', 'icon' => 'payments'],
            ] as $stepInfo)
                <div class="flex items-center gap-3 cursor-pointer step-indicator" data-step="{{ $stepInfo['step'] }}" onclick="goToStep({{ $stepInfo['step'] }})">
                    <div id="stepCircle{{ $stepInfo['step'] }}" class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition-all
                        {{ $stepInfo['step'] === 1 ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-gray-400' }}">
                        {{ $stepInfo['step'] }}
                    </div>
                    <div class="hidden sm:block">
                        <div id="stepLabel{{ $stepInfo['step'] }}" class="text-xs font-semibold transition-all {{ $stepInfo['step'] === 1 ? 'text-[#1A365D]' : 'text-gray-400' }}">
                            {{ $stepInfo['label'] }}
                        </div>
                        <div class="text-[10px] text-gray-400">Step {{ $stepInfo['step'] }} of 3</div>
                    </div>
                </div>
                @if ($stepInfo['step'] < 3)
                    <div class="flex-1 h-px bg-gray-200 mx-4"></div>
                @endif
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ url('/risk/loss-events') }}" enctype="multipart/form-data" id="lossEventForm">
        @csrf

        {{-- Hidden currency field --}}
        <input type="hidden" name="currency" value="NGN">

        {{-- Step 1: Basic Information --}}
        <div id="step1" class="step-content">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
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
                        <input type="text" name="event_title" value="{{ old('event_title') }}" required
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
                                  placeholder="Detailed description of what happened, how it was discovered, and immediate actions taken">{{ old('event_description') }}</textarea>
                        @error('event_description')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Date of Loss (Occurrence) --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Date of Loss (Occurrence) <span class="text-red-500">*</span>
                        </label>
                        <input type="date" name="date_of_loss" value="{{ old('date_of_loss') }}" required
                               class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('date_of_loss') border-red-300 @enderror">
                        @error('date_of_loss')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Date of Discovery --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Date of Discovery <span class="text-red-500">*</span>
                        </label>
                        <input type="date" name="date_discovered" value="{{ old('date_discovered', date('Y-m-d')) }}" required
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
                        <select name="business_unit_id" required
                                class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('business_unit_id') border-red-300 @enderror">
                            <option value="">Select Business Unit</option>
                            @foreach (($businessUnits ?? []) as $unit)
                                <option value="{{ $unit->id }}" {{ old('business_unit_id') == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                            @endforeach
                        </select>
                        @error('business_unit_id')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Reported By / Responsible Officer --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Reported By <span class="text-red-500">*</span>
                        </label>
                        <select name="reported_by" required
                                class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('reported_by') border-red-300 @enderror">
                            <option value="">Select Reporter</option>
                            @foreach (($users ?? []) as $user)
                                <option value="{{ $user->id }}" {{ old('reported_by') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                            @endforeach
                        </select>
                        @error('reported_by')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Linked Risk --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Linked Risk</label>
                        <select name="risk_id"
                                class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                            <option value="">None</option>
                            @foreach (($risks ?? []) as $risk)
                                <option value="{{ $risk->id }}" {{ old('risk_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ $risk->title }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 2: Classification --}}
        <div id="step2" class="step-content hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
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
                        <select name="basel_event_type" required
                                class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('basel_event_type') border-red-300 @enderror">
                            <option value="">Select Basel Event Type</option>
                            @foreach ([
                                'internal_fraud' => 'Internal Fraud',
                                'external_fraud' => 'External Fraud',
                                'employment_practices' => 'Employment Practices & Workplace Safety',
                                'clients_products' => 'Clients, Products & Business Practices',
                                'damage_physical_assets' => 'Damage to Physical Assets',
                                'business_disruption' => 'Business Disruption & System Failures',
                                'execution_delivery' => 'Execution, Delivery & Process Management',
                            ] as $val => $label)
                                <option value="{{ $val }}" {{ old('basel_event_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('basel_event_type')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Event Type (Loss Category) --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Event Type <span class="text-red-500">*</span>
                        </label>
                        <select name="event_type" required
                                class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('event_type') border-red-300 @enderror">
                            <option value="">Select Event Type</option>
                            @foreach ([
                                'actual_loss' => 'Actual Loss',
                                'potential_loss' => 'Potential Loss',
                                'near_miss' => 'Near Miss',
                                'gain_event' => 'Gain Event',
                            ] as $val => $label)
                                <option value="{{ $val }}" {{ old('event_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('event_type')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- CBN Loss Category --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">CBN Loss Category</label>
                        <input type="text" name="cbn_loss_category" value="{{ old('cbn_loss_category') }}"
                               class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               placeholder="e.g., Compliance Failure, Technology Risk">
                    </div>

                    {{-- Severity --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Severity <span class="text-red-500">*</span>
                        </label>
                        <select name="severity" required
                                class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('severity') border-red-300 @enderror">
                            <option value="">Select Severity</option>
                            @foreach ([
                                'insignificant' => 'Insignificant',
                                'minor' => 'Minor',
                                'moderate' => 'Moderate',
                                'major' => 'Major',
                                'catastrophic' => 'Catastrophic',
                            ] as $val => $label)
                                <option value="{{ $val }}" {{ old('severity') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('severity')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Root Cause Summary --}}
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Root Cause Summary</label>
                        <textarea name="root_cause_summary" rows="3"
                                  class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                  placeholder="Initial root cause assessment">{{ old('root_cause_summary') }}</textarea>
                    </div>

                    {{-- Corrective Action Summary --}}
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Corrective Action Summary</label>
                        <textarea name="corrective_action_summary" rows="3"
                                  class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                  placeholder="Corrective actions taken or planned">{{ old('corrective_action_summary') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 3: Financial Impact --}}
        <div id="step3" class="step-content hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center gap-2 mb-5">
                    <span class="material-symbols-outlined text-[#D4AF37]">payments</span>
                    <h2 class="text-base font-bold text-[#1A365D]">Financial Impact</h2>
                </div>

                {{-- Regulatory Threshold Alert --}}
                <div id="regulatoryAlert" class="hidden mb-5 p-4 rounded-lg border border-red-200 bg-red-50">
                    <div class="flex items-center gap-2 text-red-700 text-sm font-semibold mb-1">
                        <span class="material-symbols-outlined text-lg">warning</span>
                        Regulatory Reporting Threshold Exceeded
                    </div>
                    <p id="regulatoryAlertText" class="text-xs text-red-600"></p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Gross Loss Amount --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Gross Loss Amount (NGN) <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-medium">&#8358;</span>
                            <input type="number" step="0.01" name="gross_loss_amount" id="grossLossAmount"
                                   value="{{ old('gross_loss_amount', '0') }}" required
                                   class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('gross_loss_amount') border-red-300 @enderror"
                                   placeholder="0.00"
                                   oninput="checkRegulatoryThreshold(this.value)">
                        </div>
                        @error('gross_loss_amount')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Recovery Amount --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Recovery Amount (NGN)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-medium">&#8358;</span>
                            <input type="number" step="0.01" name="recovery_amount"
                                   value="{{ old('recovery_amount') }}"
                                   class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                   placeholder="0.00">
                        </div>
                    </div>

                    {{-- Insurance Recovery --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Insurance Recovery (NGN)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-medium">&#8358;</span>
                            <input type="number" step="0.01" name="insurance_recovery"
                                   value="{{ old('insurance_recovery') }}"
                                   class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                   placeholder="0.00">
                        </div>
                    </div>

                    {{-- Placeholder for alignment --}}
                    <div></div>
                </div>
            </div>

            {{-- Regulatory Flags --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6 mt-6">
                <div class="flex items-center gap-2 mb-5">
                    <span class="material-symbols-outlined text-red-500">gavel</span>
                    <h2 class="text-base font-bold text-[#1A365D]">Regulatory Reporting Flags</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer hover:border-[#1A365D]/30">
                        <input type="checkbox" name="is_regulatory_reportable" value="1" {{ old('is_regulatory_reportable') ? 'checked' : '' }}
                               class="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                        <div>
                            <div class="text-xs font-semibold text-gray-700">Regulatory Reportable</div>
                            <div class="text-[10px] text-gray-500 mt-0.5">Flag this event for regulatory reporting (CBN/NFIU/NDIC)</div>
                        </div>
                    </label>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Regulatory Body</label>
                        <input type="text" name="regulatory_body" value="{{ old('regulatory_body') }}"
                               class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               placeholder="e.g., CBN, NFIU, NDIC">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Reporting Deadline</label>
                        <input type="date" name="reporting_deadline" value="{{ old('reporting_deadline') }}"
                               class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                </div>
            </div>
        </div>

        {{-- Navigation Buttons --}}
        <div class="flex items-center justify-between mt-6">
            <button type="button" id="prevBtn" onclick="changeStep(-1)"
                    class="hidden flex items-center gap-2 px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-lg">arrow_back</span>
                Previous
            </button>
            <div class="ml-auto flex items-center gap-3">
                <a href="{{ url('/risk/loss-events') }}" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="button" id="nextBtn" onclick="changeStep(1)"
                        class="flex items-center gap-2 px-6 py-2.5 bg-[#1A365D] text-white rounded-lg text-sm font-semibold hover:bg-[#2D4A7A] transition">
                    Next Step
                    <span class="material-symbols-outlined text-lg">arrow_forward</span>
                </button>
                <button type="submit" id="submitBtn"
                        class="hidden flex items-center gap-2 px-6 py-2.5 bg-[#2D7D46] text-white rounded-lg text-sm font-semibold hover:bg-[#236B38] transition">
                    <span class="material-symbols-outlined text-lg">check</span>
                    Submit Loss Event
                </button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
let currentStep = 1;
const totalSteps = 3;

function goToStep(step) {
    if (step < 1 || step > totalSteps) return;
    currentStep = step;
    updateStepUI();
}

function changeStep(direction) {
    const newStep = currentStep + direction;
    if (newStep < 1 || newStep > totalSteps) return;
    currentStep = newStep;
    updateStepUI();
}

function updateStepUI() {
    // Show/hide step content
    for (let i = 1; i <= totalSteps; i++) {
        const stepEl = document.getElementById('step' + i);
        const circleEl = document.getElementById('stepCircle' + i);
        const labelEl = document.getElementById('stepLabel' + i);

        if (stepEl) stepEl.classList.toggle('hidden', i !== currentStep);

        if (circleEl) {
            circleEl.className = 'w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition-all ' +
                (i === currentStep ? 'bg-[#1A365D] text-white' :
                 i < currentStep ? 'bg-[#2D7D46] text-white' : 'bg-gray-100 text-gray-400');
            circleEl.innerHTML = i < currentStep ? '<span class="material-symbols-outlined text-sm">check</span>' : i;
        }

        if (labelEl) {
            labelEl.className = 'text-xs font-semibold transition-all ' +
                (i === currentStep ? 'text-[#1A365D]' :
                 i < currentStep ? 'text-[#2D7D46]' : 'text-gray-400');
        }
    }

    // Show/hide nav buttons
    document.getElementById('prevBtn').classList.toggle('hidden', currentStep === 1);
    document.getElementById('nextBtn').classList.toggle('hidden', currentStep === totalSteps);
    document.getElementById('submitBtn').classList.toggle('hidden', currentStep !== totalSteps);

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function checkRegulatoryThreshold(value) {
    const amount = parseFloat(value) || 0;
    const alertEl = document.getElementById('regulatoryAlert');
    const alertTextEl = document.getElementById('regulatoryAlertText');

    if (amount >= 10000000) {
        alertEl.classList.remove('hidden');
        alertTextEl.textContent = 'This amount exceeds the CBN mandatory notification threshold of \u20A610,000,000. A CBN notification must be filed within 24 hours of discovery.';
    } else if (amount >= 5000000) {
        alertEl.classList.remove('hidden');
        alertTextEl.textContent = 'This amount exceeds \u20A65,000,000. Please review if NFIU Suspicious Transaction Report (STR) filing is required.';
    } else {
        alertEl.classList.add('hidden');
    }
}

// Before form submission: remove 'required' from hidden step fields so browser doesn't block
document.getElementById('lossEventForm').addEventListener('submit', function(e) {
    document.querySelectorAll('.step-content.hidden [required]').forEach(function(el) {
        el.removeAttribute('required');
        el.dataset.wasRequired = '1';
    });
});
</script>
@endpush
