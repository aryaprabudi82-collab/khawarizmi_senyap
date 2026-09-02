@extends('layouts.app')

@section('title', 'Kepegawaian — Cuti')
@section('breadcrumb', 'Konteks hr')
@section('heading', 'Pengajuan Cuti')

@section('actions')
  <a href="{{ route('hr.index') }}" class="btn btn-link">&larr; Pegawai</a>
  <a href="{{ route('hr.presensi.index') }}" class="btn btn-link">Presensi &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Cuti</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('hr.cuti.simpan') }}" class="row g-2 align-items-end">
      @csrf
      <div class="col-12 col-md-3">
        <label class="form-label">Pegawai</label>
        <select name="employee_id" class="form-select" required>
          @foreach ($pegawai as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis Cuti</label>
        <select name="leave_type_id" class="form-select" required>
          @foreach ($jenisCuti as $j)
            <option value="{{ $j->id }}">{{ $j->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Mulai</label>
        <input type="date" name="start_date" class="form-control" value="{{ now()->toDateString() }}" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Selesai</label>
        <input type="date" name="end_date" class="form-control" value="{{ now()->toDateString() }}" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Alasan</label>
        <input type="text" name="reason" class="form-control">
      </div>
      <div class="col-12 col-md-1">
        <button class="btn btn-primary w-100">Ajukan</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Pengajuan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Pegawai</th><th>Jenis</th><th>Periode</th><th class="text-end">Hari</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pengajuan as $p)
          <tr>
            <td>{{ $p->employee->name }}</td>
            <td>{{ $p->leaveType->name }}</td>
            <td class="text-secondary small">{{ $p->start_date->format('d-m-Y') }} &ndash; {{ $p->end_date->format('d-m-Y') }}</td>
            <td class="text-end font-monospace">{{ $p->days_count }}</td>
            <td>
              @php
                $warna = ['diajukan' => 'yellow', 'disetujui' => 'green', 'ditolak' => 'red', 'dibatalkan' => 'secondary'][$p->status] ?? 'secondary';
              @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
              @if ($p->status === 'ditolak' && $p->rejection_reason)
                <div class="text-secondary small">{{ $p->rejection_reason }}</div>
              @endif
            </td>
            <td>
              @if ($p->isPending())
                <div class="btn-group">
                  <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#setuju-{{ $p->id }}">Setuju</button>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#tolak-{{ $p->id }}">Tolak</button>
                </div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pengajuan cuti.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($pengajuan as $p)
  @if ($p->isPending())
    <div class="modal fade" id="setuju-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('hr.cuti.setuju', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Setujui Cuti {{ $p->employee->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">{{ $p->leaveType->name }}, {{ $p->days_count }} hari ({{ $p->start_date->format('d-m-Y') }} &ndash; {{ $p->end_date->format('d-m-Y') }}).</div>
          <div class="modal-footer"><button type="submit" class="btn btn-success">Setujui</button></div>
        </form>
      </div>
    </div>
    <div class="modal fade" id="tolak-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('hr.cuti.tolak', $p) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Tolak Cuti {{ $p->employee->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Alasan Penolakan</label>
            <textarea name="rejection_reason" class="form-control" required></textarea>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-danger">Tolak</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
