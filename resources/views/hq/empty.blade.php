@extends('layouts.app')

@section('title', 'Business HQ')
@section('page-section', 'Business HQ')

@section('content')
    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
        <span class="material-symbols-outlined text-4xl text-gray-300">account_tree</span>
        <h1 class="mt-2 text-sm font-semibold text-gray-700">The organization graph is empty</h1>
        <p class="mx-auto mt-1 max-w-md text-xs text-gray-500">
            Business HQ renders a landing page for every node of the organization. Create business
            units or entities and they will appear here as navigable nodes.
        </p>
    </div>
@endsection
