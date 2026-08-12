@extends('layouts.app')

@section('title', 'Regulatory Circulars')
@section('page-section', 'Regulatory')
@section('page-title', 'Regulatory Circulars')

@section('content')
<div class="space-y-6">
    @if (session('success'))
        <div class="p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Regulatory Circulars</h1>
            <p class="text-sm text-gray-500 mt-1">Circulars issued by the regulators, and where the institution stands on each.</p>
        </div>
        @can('regulatory.manage')
            <a href="{{ route('risk.regulatory.create-circular') }}"
               class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Record Circular</a>
        @endcan
    </div>

    {{-- WP-09: the register is the shared grid — see
         App\Grids\Definitions\RegulatoryCircularsGrid. The regulator and
         compliance-status filters the controller has always read from the
         query string are declared there, and now actually render. --}}
    <x-data-grid grid="circulars" />
</div>
@endsection
