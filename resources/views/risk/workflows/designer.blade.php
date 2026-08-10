@extends('layouts.app')

@section('title', 'Workflow designer')
@section('page-section', 'Workflows')
@section('page-title', 'Workflow designer')

@section('breadcrumbs')
    <a href="{{ route('risk.workflows.dashboard') }}" class="hover:text-[#1A365D]">Workflows</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.workflows.definitions') }}" class="hover:text-[#1A365D]">Definitions</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Designer</span>
@endsection

@section('content')
    <div class="mb-4">
        <h2 class="text-2xl font-bold text-gray-900">Workflow designer</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Draw the process: who decides what, in which order, by when, and what happens when a deadline passes.
            Publishing creates a new version — anything already running stays on the version it started with, so
            a change here cannot alter a decision already in progress.
        </p>
    </div>

    @livewire('admin.workflow-designer', ['definitionId' => $definitionId])
@endsection
