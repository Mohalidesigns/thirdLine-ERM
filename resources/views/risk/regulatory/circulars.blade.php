@extends('layouts.app')
@section('title', 'Regulatory Circulars')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Regulatory Circulars</h1>
        <a href="{{ route('risk.regulatory.create-circular') }}" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Record Circular</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Regulator</th><th>Reference</th><th>Title</th><th>Impact</th><th>Compliance</th><th>Date Issued</th></tr></thead><tbody>
            @forelse($circulars as $c)
            <tr class="cursor-pointer" onclick="window.location='{{ route('risk.regulatory.show-circular', $c) }}'">
                <td><span class="badge bg-blue-50 text-blue-700">{{ $c->regulator }}</span></td>
                <td class="font-mono text-xs">{{ $c->circular_ref }}</td>
                <td class="font-medium">{{ Str::limit($c->title, 50) }}</td>
                <td>@php $ic = ['critical'=>'red','high'=>'orange','medium'=>'yellow','low'=>'green']; @endphp<span class="badge bg-{{ $ic[$c->impact_level] ?? 'gray' }}-100 text-{{ $ic[$c->impact_level] ?? 'gray' }}-700">{{ ucfirst($c->impact_level) }}</span></td>
                <td>@php $cc = ['compliant'=>'green','partially_compliant'=>'yellow','non_compliant'=>'red','not_assessed'=>'gray','not_applicable'=>'gray']; @endphp<span class="badge bg-{{ $cc[$c->compliance_status] ?? 'gray' }}-100 text-{{ $cc[$c->compliance_status] ?? 'gray' }}-700">{{ ucfirst(str_replace('_',' ',$c->compliance_status)) }}</span></td>
                <td class="text-xs">{{ $c->date_issued->format('M d, Y') }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center py-8 text-gray-400">No circulars recorded</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div>{{ $circulars->links() }}</div>
</div>
@endsection
