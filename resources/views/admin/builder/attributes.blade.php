@extends('layouts.app')

@section('title', 'Fields — ' . $objectType->name)
@section('page-section', 'Administration')
@section('page-title', 'Fields')

@section('breadcrumbs')
    <a href="{{ route('admin.builder') }}" class="hover:text-[#1A365D]">Configuration Builder</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('admin.builder.object-types') }}" class="hover:text-[#1A365D]">Object Types</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $objectType->name }}</span>
@endsection

@section('content')
    @livewire('admin.attribute-builder', ['objectTypeId' => $objectType->id])
@endsection
