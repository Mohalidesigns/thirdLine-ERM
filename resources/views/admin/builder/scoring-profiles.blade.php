@extends('layouts.app')

@section('title', 'Scoring Profiles')
@section('page-section', 'Administration')
@section('page-title', 'Scoring Profiles')

@section('breadcrumbs')
    <a href="{{ route('admin.builder') }}" class="hover:text-[#1A365D]">Configuration Builder</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Scoring Profiles</span>
@endsection

@section('content')
    @livewire('admin.scoring-profile-builder')
@endsection
