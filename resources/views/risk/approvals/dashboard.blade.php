@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Approval Dashboard</h1>
            <p class="text-muted mb-0">Review and manage pending approvals</p>
        </div>
        <a href="{{ route('risk.approvals.history') }}" class="btn btn-outline-primary">View History</a>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Pending</p>
                            <h4 class="mb-0 text-warning">{{ $stats['pending'] }}</h4>
                        </div>
                        <i class="fas fa-hourglass-half text-warning opacity-50" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Approved</p>
                            <h4 class="mb-0 text-success">{{ $stats['approved'] }}</h4>
                        </div>
                        <i class="fas fa-check-circle text-success opacity-50" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Rejected</p>
                            <h4 class="mb-0 text-danger">{{ $stats['rejected'] }}</h4>
                        </div>
                        <i class="fas fa-times-circle text-danger opacity-50" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Total</p>
                            <h4 class="mb-0">{{ $stats['total'] }}</h4>
                        </div>
                        <i class="fas fa-list text-primary opacity-50" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong> {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($pending->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                <h5 class="mt-3">All Caught Up!</h5>
                <p class="text-muted">No pending approvals at this time.</p>
            </div>
        </div>
    @else
        <!-- Pending Approvals by Entity Type -->
        @foreach ($groupedByEntity as $entityType => $approvals)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0">
                        <span class="badge bg-warning">{{ count($approvals) }}</span>
                        {{ $entityType }} Approvals
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Entity</th>
                                    <th>Action</th>
                                    <th>Requested By</th>
                                    <th>Requested At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($approvals as $approval)
                                    <tr>
                                        <td>
                                            <strong>#{{ $approval->entity_id }}</strong>
                                            <br>
                                            <small class="text-muted">{{ $entityType }}</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">{{ ucfirst($approval->action) }}</span>
                                        </td>
                                        <td>
                                            {{ $approval->requestedBy->name ?? 'Unknown' }}
                                            <br>
                                            <small class="text-muted">{{ $approval->requestedBy->email ?? '' }}</small>
                                        </td>
                                        <td>
                                            {{ $approval->requested_at->format('d M Y H:i') }}
                                            <br>
                                            <small class="text-muted">{{ $approval->requested_at->diffForHumans() }}</small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-success"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#approveModal{{ $approval->id }}">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#rejectModal{{ $approval->id }}">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Approve Modal -->
                                    <div class="modal fade" id="approveModal{{ $approval->id }}" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Approve Request</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('risk.approvals.approve', $approval) }}" method="POST">
                                                    @csrf
                                                    <div class="modal-body">
                                                        <p class="mb-3">Are you sure you want to approve this request?</p>
                                                        <div class="mb-3">
                                                            <label class="form-label">Comments (Optional)</label>
                                                            <textarea name="comments" class="form-control" rows="3" placeholder="Add any comments..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Reject Modal -->
                                    <div class="modal fade" id="rejectModal{{ $approval->id }}" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reject Request</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('risk.approvals.reject', $approval) }}" method="POST">
                                                    @csrf
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Rejection Reason</label>
                                                            <textarea name="rejection_reason" class="form-control @error('rejection_reason') is-invalid @enderror" rows="3" required placeholder="Explain why you're rejecting this request..."></textarea>
                                                            @error('rejection_reason')
                                                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
