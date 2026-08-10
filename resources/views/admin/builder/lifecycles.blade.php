@extends('layouts.app')

@section('title', 'Lifecycles')
@section('page-section', 'Administration')
@section('page-title', 'Lifecycles')

@section('breadcrumbs')
    <a href="{{ route('admin.builder') }}" class="hover:text-[#1A365D]">Configuration Builder</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Lifecycles</span>
@endsection

@section('content')
    @livewire('admin.lifecycle-builder')
@endsection
