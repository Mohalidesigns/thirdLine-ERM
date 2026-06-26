@extends('layouts.app')

@section('title', 'Organization Settings')

@section('page-section', 'Administration')
@section('page-title', 'Organization Settings')

@section('content')
<div x-data="{ activeTab: 'profile' }">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Organization Settings</h2>
        <p class="text-sm text-gray-500 mt-1">Configure organization profile, risk settings, and preferences</p>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-t-lg shadow border-b border-gray-100">
        <div class="flex border-b border-gray-200">
            <button
                @click="activeTab = 'profile'"
                :class="{ 'tab-active': activeTab === 'profile', 'tab-inactive': activeTab !== 'profile' }"
                class="px-6 py-3 font-medium transition"
            >
                <span class="material-symbols-outlined inline text-[18px] mr-2">business</span>
                Organization Profile
            </button>
            <button
                @click="activeTab = 'thresholds'"
                :class="{ 'tab-active': activeTab === 'thresholds', 'tab-inactive': activeTab !== 'thresholds' }"
                class="px-6 py-3 font-medium transition"
            >
                <span class="material-symbols-outlined inline text-[18px] mr-2">settings</span>
                Regulatory Thresholds
            </button>
            <button
                @click="activeTab = 'risk'"
                :class="{ 'tab-active': activeTab === 'risk', 'tab-inactive': activeTab !== 'risk' }"
                class="px-6 py-3 font-medium transition"
            >
                <span class="material-symbols-outlined inline text-[18px] mr-2">assessment</span>
                Risk Scoring
            </button>
            <button
                @click="activeTab = 'notifications'"
                :class="{ 'tab-active': activeTab === 'notifications', 'tab-inactive': activeTab !== 'notifications' }"
                class="px-6 py-3 font-medium transition"
            >
                <span class="material-symbols-outlined inline text-[18px] mr-2">notifications</span>
                Notifications
            </button>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="bg-white rounded-b-lg shadow">
        <!-- Organization Profile Tab -->
        <div x-show="activeTab === 'profile'" class="p-6">
            <form method="POST" action="{{ route('admin.settings.profile') }}" class="max-w-2xl space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="org_name" class="block text-sm font-medium text-gray-700 mb-2">Organization Name</label>
                    <input
                        type="text"
                        id="org_name"
                        name="org_name"
                        placeholder="Enter organization name"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                </div>

                <div>
                    <label for="org_code" class="block text-sm font-medium text-gray-700 mb-2">Organization Code</label>
                    <input
                        type="text"
                        id="org_code"
                        name="org_code"
                        placeholder="e.g., ORG-001"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="industry" class="block text-sm font-medium text-gray-700 mb-2">Industry</label>
                        <input
                            type="text"
                            id="industry"
                            name="industry"
                            placeholder="e.g., Financial Services"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        >
                    </div>

                    <div>
                        <label for="country" class="block text-sm font-medium text-gray-700 mb-2">Country</label>
                        <input
                            type="text"
                            id="country"
                            name="country"
                            placeholder="Nigeria"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        >
                    </div>
                </div>

                <div>
                    <label for="regulatory_framework" class="block text-sm font-medium text-gray-700 mb-2">Regulatory Framework</label>
                    <input
                        type="text"
                        id="regulatory_framework"
                        name="regulatory_framework"
                        placeholder="e.g., Basel III, CBN ORMS"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                </div>

                <button
                    type="submit"
                    class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
                >
                    Save Profile
                </button>
            </form>
        </div>

        <!-- Regulatory Thresholds Tab -->
        <div x-show="activeTab === 'thresholds'" class="p-6">
            <form method="POST" action="{{ route('admin.settings.thresholds') }}" class="max-w-2xl space-y-6">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="critical_threshold" class="block text-sm font-medium text-gray-700 mb-2">Critical Risk Threshold</label>
                        <div class="relative">
                            <input
                                type="number"
                                id="critical_threshold"
                                name="critical_threshold"
                                min="0"
                                max="100"
                                placeholder="80"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                            >
                            <span class="absolute right-3 top-2.5 text-gray-500">%</span>
                        </div>
                    </div>

                    <div>
                        <label for="high_threshold" class="block text-sm font-medium text-gray-700 mb-2">High Risk Threshold</label>
                        <div class="relative">
                            <input
                                type="number"
                                id="high_threshold"
                                name="high_threshold"
                                min="0"
                                max="100"
                                placeholder="60"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                            >
                            <span class="absolute right-3 top-2.5 text-gray-500">%</span>
                        </div>
                    </div>

                    <div>
                        <label for="medium_threshold" class="block text-sm font-medium text-gray-700 mb-2">Medium Risk Threshold</label>
                        <div class="relative">
                            <input
                                type="number"
                                id="medium_threshold"
                                name="medium_threshold"
                                min="0"
                                max="100"
                                placeholder="40"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                            >
                            <span class="absolute right-3 top-2.5 text-gray-500">%</span>
                        </div>
                    </div>

                    <div>
                        <label for="low_threshold" class="block text-sm font-medium text-gray-700 mb-2">Low Risk Threshold</label>
                        <div class="relative">
                            <input
                                type="number"
                                id="low_threshold"
                                name="low_threshold"
                                min="0"
                                max="100"
                                placeholder="20"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                            >
                            <span class="absolute right-3 top-2.5 text-gray-500">%</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="capital_requirement_percentage" class="block text-sm font-medium text-gray-700 mb-2">Capital Requirement Percentage</label>
                    <div class="relative">
                        <input
                            type="number"
                            id="capital_requirement_percentage"
                            name="capital_requirement_percentage"
                            min="0"
                            max="100"
                            placeholder="10"
                            step="0.01"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        >
                        <span class="absolute right-3 top-2.5 text-gray-500">%</span>
                    </div>
                </div>

                <button
                    type="submit"
                    class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
                >
                    Save Thresholds
                </button>
            </form>
        </div>

        <!-- Risk Scoring Tab -->
        <div x-show="activeTab === 'risk'" class="p-6">
            <form method="POST" action="{{ route('admin.settings.risk') }}" class="max-w-2xl space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="scoring_methodology" class="block text-sm font-medium text-gray-700 mb-2">Scoring Methodology</label>
                    <select
                        id="scoring_methodology"
                        name="scoring_methodology"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                        <option value="">Select methodology...</option>
                        <option value="basic">Basic (Probability x Impact)</option>
                        <option value="advanced">Advanced (Weighted factors)</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="probability_scale" class="block text-sm font-medium text-gray-700 mb-2">Probability Scale</label>
                        <input
                            type="number"
                            id="probability_scale"
                            name="probability_scale"
                            min="1"
                            max="10"
                            placeholder="5"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        >
                    </div>

                    <div>
                        <label for="impact_scale" class="block text-sm font-medium text-gray-700 mb-2">Impact Scale</label>
                        <input
                            type="number"
                            id="impact_scale"
                            name="impact_scale"
                            min="1"
                            max="10"
                            placeholder="5"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        >
                    </div>
                </div>

                <div>
                    <label for="calculation_method" class="block text-sm font-medium text-gray-700 mb-2">Calculation Method</label>
                    <select
                        id="calculation_method"
                        name="calculation_method"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                        <option value="">Select method...</option>
                        <option value="multiplication">Multiplication</option>
                        <option value="weighted_average">Weighted Average</option>
                        <option value="matrix">Matrix-based</option>
                    </select>
                </div>

                <div>
                    <label for="review_frequency" class="block text-sm font-medium text-gray-700 mb-2">Review Frequency</label>
                    <select
                        id="review_frequency"
                        name="review_frequency"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                        <option value="">Select frequency...</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="semi-annual">Semi-Annual</option>
                        <option value="annual">Annual</option>
                        <option value="continuous">Continuous</option>
                    </select>
                </div>

                <button
                    type="submit"
                    class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
                >
                    Save Risk Settings
                </button>
            </form>
        </div>

        <!-- Notifications Tab -->
        <div x-show="activeTab === 'notifications'" class="p-6">
            <form method="POST" action="{{ route('admin.settings.notifications') }}" class="max-w-2xl space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="notification_email" class="block text-sm font-medium text-gray-700 mb-2">Notification Email Address</label>
                    <input
                        type="email"
                        id="notification_email"
                        name="notification_email"
                        placeholder="admin@example.com"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                </div>

                <div class="space-y-3">
                    <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input
                            type="checkbox"
                            name="critical_risk_notification"
                            value="1"
                            class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                        >
                        <span class="text-sm font-medium text-gray-900">Notify on Critical Risk Detection</span>
                    </label>

                    <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input
                            type="checkbox"
                            name="approval_required_notification"
                            value="1"
                            class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                        >
                        <span class="text-sm font-medium text-gray-900">Notify on Approval Required</span>
                    </label>

                    <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input
                            type="checkbox"
                            name="deadline_approaching_notification"
                            value="1"
                            class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                        >
                        <span class="text-sm font-medium text-gray-900">Notify on Approaching Deadline</span>
                    </label>

                    <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input
                            type="checkbox"
                            name="report_ready_notification"
                            value="1"
                            class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                        >
                        <span class="text-sm font-medium text-gray-900">Notify When Report is Ready</span>
                    </label>
                </div>

                <button
                    type="submit"
                    class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
                >
                    Save Preferences
                </button>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush
