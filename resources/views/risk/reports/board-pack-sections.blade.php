@extends('layouts.app')

@section('title', 'Board Pack Sections - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Board Pack Sections')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.reports.library') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Board Pack Sections</span>
@endsection

@section('content')
    <div class="max-w-3xl">
        <div class="mb-6">
            <h1 class="text-xl font-bold text-[#1A365D]">Board pack sections</h1>
            <p class="text-sm text-gray-500 mt-1">
                Choose which sections your board pack contains and the order they appear in. Cover and contents are
                always produced.
            </p>
        </div>

        @if (session('success'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                    @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('risk.reports.board-pack.sections.update') }}">
            @csrf
            @method('PUT')

            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 text-xs font-medium text-gray-600">
                    Use the arrows to reorder. Unticked sections are left out of the pack.
                </div>

                <ul id="sectionList" class="divide-y divide-gray-100">
                    @php
                        // Selected sections first, in their configured order,
                        // then anything not currently included.
                        $ordered = array_merge(
                            $selected,
                            array_values(array_diff(array_keys($available), $selected))
                        );
                    @endphp

                    @foreach ($ordered as $key)
                        <li class="flex items-center gap-3 px-5 py-3" data-key="{{ $key }}">
                            <input type="checkbox" name="sections[]" value="{{ $key }}"
                                   @checked(in_array($key, $selected, true))
                                   class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/20">
                            <span class="flex-1 text-sm text-gray-800">{{ $available[$key] }}</span>
                            <button type="button" class="move-up text-gray-400 hover:text-[#1A365D]" title="Move up">
                                <span class="material-symbols-outlined text-base">arrow_upward</span>
                            </button>
                            <button type="button" class="move-down text-gray-400 hover:text-[#1A365D]" title="Move down">
                                <span class="material-symbols-outlined text-base">arrow_downward</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('risk.reports.library') }}"
                   class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                <button type="submit"
                        class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">
                    Save section order
                </button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('sectionList');

    // The submitted order is the DOM order of the checked inputs, so moving a
    // row is all it takes to reorder the pack.
    list.addEventListener('click', function (event) {
        const up = event.target.closest('.move-up');
        const down = event.target.closest('.move-down');
        if (!up && !down) { return; }

        const row = event.target.closest('li');
        if (up && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        }
        if (down && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }
    });
});
</script>
@endpush
