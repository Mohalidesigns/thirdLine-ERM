@extends('layouts.app')

@section('title', 'Relationship Types')
@section('page-section', 'Administration')
@section('page-title', 'Relationship Types')

@section('breadcrumbs')
    <a href="{{ route('admin.builder') }}" class="hover:text-[#1A365D]">Configuration Builder</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Relationship Types</span>
@endsection

@section('content')
    @livewire('admin.relationship-type-builder')
@endsection
