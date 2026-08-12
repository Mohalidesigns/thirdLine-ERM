@extends('layouts.app')

@section('title', 'Edit · '.$dashboard->name)
@section('page-section', 'Configuration')
@section('page-title', $dashboard->name)

@section('breadcrumbs')
    <nav class="flex items-center gap-1 text-xs text-gray-500">
        <a href="{{ route('risk.dashboards.index') }}" class="hover:text-gray-800">Dashboards</a>
        <span class="text-gray-300">›</span>
        <span class="font-medium text-gray-800">{{ $dashboard->name }}</span>
    </nav>
@endsection

@section('content')
    <livewire:widgets.dashboard-builder :dashboard-id="$dashboard->id" />
@endsection
