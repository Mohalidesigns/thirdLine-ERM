@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Approval History</h1>
            <p class="text-muted mb-0">Historical record of all approval requests</p>
        </div>
        <a href="{{ route('risk.approvals.dashboard') }}" class="btn btn-outline-primary">Back to Dashboard</a>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('risk.approvals.history') }}" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Entity Type</label>
                    <select name="entity_type" class="form-select">
                        <option value="">All Entity Types</option>
                        @foreach ($entityTypes as $type)
                            <option value="{{ $type }}" @selected($entityType === $type)>
                                {{ $type }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($history->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-inbox text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3">No History</h5>
                <p class="text-muted">No approval history available yet.</p>
            </div>
        </div>
    @else
        <!-- History Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Entity</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Requested By</th>
                                <th>Reviewed By</th>
                                <th>Reviewed At</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $approval)
                                <tr>
                                    <td>
                                        <strong>{{ $approval->entity_type }} #{{ $approval->entity_id }}</strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">{{ ucfirst($approval->action) }}</span>
                                    </td>
                                    <td>
                                        @if ($approval->isApproved())
                                            <span class="badge bg-success">Approved</span>
                                        @elseif ($approval->isRejected())
                                            <span class="badge bg-danger">Rejected</span>
                                        @else
                                            <span class="badge bg-secondary">{{ ucfirst($approval->status) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $approval->requestedBy->name ?? 'Unknown' }}
                                        <br>
                                        <small class="text-muted">{{ $approval->requested_at->format('d M Y') }}</small>
                                    </td>
                                    <td>
                                        {{ $approval->reviewedBy->name ?? 'N/A' }}
                                    </td>
                                    <td>
                                        @if ($approval->reviewed_at)
                                            {{ $approval->reviewed_at->format('d M Y H:i') }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($approval->isRejected() && $approval->rejection_reason)
                                            <small class="text-danger" title="{{ $approval->rejection_reason }}">
                                                {{ Str::limit($approval->rejection_reason, 30) }}
                                            </small>
                                        @elseif ($approval->comments)
                                            <small class="text-muted" title="{{ $approval->comments }}">
                                                {{ Str::limit($approval->comments, 30) }}
                                            </small>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
