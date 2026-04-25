@extends('layouts.admin')

@section('title', 'Filter Skip Log')

@section('content')
<div class="container-fluid">
    <h1 class="h3 mb-4">Filter Skip Log</h1>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.filter-skip-log.index') }}">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Source</label>
                        <select name="source" class="form-select">
                            <option value="">All</option>
                            @foreach($sources as $source)
                                <option value="{{ $source }}" {{ request('source') == $source ? 'selected' : '' }}>{{ $source }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Rule ID</label>
                        <input type="number" name="rule_id" class="form-control" value="{{ request('rule_id') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Cleanup Form -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.filter-skip-log.cleanup') }}">
                @csrf
                <div class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <label class="form-label">Delete entries older than (days)</label>
                        <input type="number" name="days" class="form-control" value="30" min="1">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-danger">Cleanup Old Logs</button>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted">This will permanently delete log entries. Use with caution.</small>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Log Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Skipped At</th>
                            <th>Source</th>
                            <th>Source ID</th>
                            <th>Rule</th>
                            <th>Action</th>
                            <th>Field</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td>{{ $log->skipped_at->format('Y-m-d H:i:s') }}</td>
                                <td>
                                    <span class="badge bg-{{ $log->source === 'encar' ? 'primary' : 'success' }}">
                                        {{ $log->source }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ $log->lot_url }}" target="_blank" class="text-decoration-none">
                                        {{ $log->source_id }}
                                    </a>
                                </td>
                                <td>{{ $log->rule_name }}</td>
                                <td>
                                    <span class="badge bg-{{ $log->action === 'skip' ? 'danger' : 'warning' }}">
                                        {{ $log->action }}
                                    </span>
                                </td>
                                <td>{{ $log->field_name }}</td>
                                <td class="text-truncate" style="max-width: 200px;">{{ $log->field_value }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center">No log entries found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            {{ $logs->appends(request()->query())->links() }}
        </div>
    </div>
</div>
@endsection
