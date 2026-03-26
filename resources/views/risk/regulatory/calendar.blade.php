@extends('layouts.app')
@section('title', 'Regulatory Calendar')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Regulatory Calendar — {{ date('F Y', mktime(0,0,0,$month,1,$year)) }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('risk.regulatory.calendar', ['month' => $month == 1 ? 12 : $month-1, 'year' => $month == 1 ? $year-1 : $year]) }}" class="px-3 py-2 bg-gray-100 rounded-lg text-sm">&larr; Prev</a>
            <a href="{{ route('risk.regulatory.calendar', ['month' => $month == 12 ? 1 : $month+1, 'year' => $month == 12 ? $year+1 : $year]) }}" class="px-3 py-2 bg-gray-100 rounded-lg text-sm">Next &rarr;</a>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        @forelse($deadlines->groupBy(fn($d) => $d->deadline_date->format('Y-m-d')) as $date => $items)
        <div class="py-3 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
            <p class="text-sm font-semibold text-gray-700 mb-2">{{ \Carbon\Carbon::parse($date)->format('l, F j') }}</p>
            @foreach($items as $dl)
            <div class="ml-4 flex items-center gap-3 py-1">
                <span class="w-2 h-2 rounded-full {{ $dl->isOverdue() ? 'bg-red-500' : 'bg-green-500' }}"></span>
                <span class="text-sm">{{ $dl->title }}</span>
                <span class="badge bg-blue-50 text-blue-700 text-[10px]">{{ $dl->regulator }}</span>
                <span class="text-xs text-gray-400">{{ $dl->responsible?->name }}</span>
            </div>
            @endforeach
        </div>
        @empty
        <p class="text-center py-12 text-gray-400">No deadlines this month</p>
        @endforelse
    </div>
</div>
@endsection
