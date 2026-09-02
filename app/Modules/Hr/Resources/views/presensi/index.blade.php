@extends('layouts.app')

@section('title', 'Kepegawaian — Presensi')
@section('breadcrumb', 'Konteks hr')
@section('heading', 'Presensi Harian')

@section('actions')
  <a href="{{ route('hr.index') }}" class="btn btn-link">&larr; Pegawai</a>
  <a href="{{ route('hr.cuti.index') }}" class="btn btn-link">Cuti &rarr;</a>
  <form method="GET" action="{{ route('hr.presensi.index') }}" class="d-inline-block ms-2">
    <input type="date" name="tanggal" class="form-control form-control-sm d-inline-block w-auto" value="{{ $tanggal->toDateString() }}" onchange="this.form.submit()">
  </form>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">{{ $tanggal->format('d-m-Y') }}</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Pegawai</th><th>Masuk</th><th>Pulang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pegawai as $p)
          @php $rec = $tercatat->get($p->id); @endphp
          <tr>
            <td>{{ $p->name }}</td>
            <td class="font-monospace small">{{ $rec?->check_in_at?->format('H:i') ?? '—' }}</td>
            <td class="font-monospace small">{{ $rec?->check_out_at?->format('H:i') ?? '—' }}</td>
            <td>
              @php
                $status = $rec->status ?? null;
                $warna = ['hadir' => 'green', 'izin' => 'yellow', 'sakit' => 'yellow', 'alpha' => 'red', 'cuti' => 'blue'][$status] ?? 'secondary';
              @endphp
              @if ($status)
                <span class="badge bg-{{ $warna }}-lt">{{ $status }}</span>
              @else
                <span class="text-secondary small">Belum tercatat</span>
              @endif
            </td>
            <td>
              @if ($tanggal->isToday())
                <div class="btn-group">
                  <form method="POST" action="{{ route('hr.presensi.masuk', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary" @disabled($rec?->check_in_at)>Masuk</button>
                  </form>
                  <form method="POST" action="{{ route('hr.presensi.pulang', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" @disabled(!$rec?->check_in_at || $rec?->check_out_at)>Pulang</button>
                  </form>
                </div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada pegawai aktif.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Catat Izin/Sakit/Alpha/Cuti</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('hr.presensi.manual') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <select name="employee_id" class="form-select" required>
          @foreach ($pegawai as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><input type="date" name="attendance_date" class="form-control" value="{{ $tanggal->toDateString() }}" required></div>
      <div class="col-6 col-md-2">
        <select name="status" class="form-select" required>
          <option value="izin">Izin</option>
          <option value="sakit">Sakit</option>
          <option value="alpha">Alpha</option>
          <option value="cuti">Cuti</option>
        </select>
      </div>
      <div class="col-12 col-md-2"><input type="text" name="note" class="form-control" placeholder="Catatan"></div>
      <div class="col-12 col-md-1"><button class="btn btn-outline-primary w-100">Catat</button></div>
    </form>
  </div>
</div>

@endsection
