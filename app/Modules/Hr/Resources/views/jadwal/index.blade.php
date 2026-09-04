@extends('layouts.app')

@section('title', 'Kepegawaian — Jadwal Pegawai')
@section('breadcrumb', 'Konteks hr')
@section('heading', 'Jadwal Pegawai ' . $tanggal->translatedFormat('l, d F Y'))

@section('content')

<div class="row g-3">

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Jam Kerja (Shift)</h3></div>
      <div class="table-responsive">
        <table class="table table-sm table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jam</th><th>Toleransi</th></tr></thead>
          <tbody>
            @forelse ($shift as $s)
              <tr>
                <td class="font-monospace small">{{ $s->code }}</td>
                <td>{{ $s->name }}</td>
                <td>{{ substr($s->start_time, 0, 5) }}–{{ substr($s->end_time, 0, 5) }}</td>
                <td>{{ $s->tolerance_minutes }} mnt</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada shift.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.jadwal.shift.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-8"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama shift, mis. Pagi" required></div>
          <div class="col-4"><input type="time" name="start_time" class="form-control form-control-sm" required></div>
          <div class="col-4"><input type="time" name="end_time" class="form-control form-control-sm" required></div>
          <div class="col-4"><input type="number" name="tolerance_minutes" class="form-control form-control-sm" placeholder="Toleransi (mnt)" value="15" min="0"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Tambah Shift</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Penugasan Shift</h3>
        <div class="card-actions">
          <form method="GET" class="d-flex gap-2">
            <input type="date" name="tanggal" class="form-control form-control-sm" value="{{ $tanggal->toDateString() }}" onchange="this.form.submit()">
          </form>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pegawai</th><th>Shift</th><th>Unit</th><th class="w-1"></th></tr></thead>
          <tbody>
            @foreach ($pegawai as $p)
              @php $j = $jadwal->get($p->id); @endphp
              <tr>
                <td>{{ $p->name }}</td>
                <td>
                  @if ($j && $j->status === 'terjadwal')
                    {{ $j->workShift->name }} ({{ substr($j->workShift->start_time, 0, 5) }})
                  @else
                    <span class="text-secondary">belum dijadwalkan</span>
                  @endif
                </td>
                <td>{{ $j->unit_name ?? '—' }}</td>
                <td>
                  @if ($j && $j->status === 'terjadwal')
                    <form method="POST" action="{{ route('hr.jadwal.batal', $j) }}" class="d-inline">
                      @csrf
                      <button class="btn btn-sm btn-outline-danger">Batal</button>
                    </form>
                  @else
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#tugas-{{ $p->id }}">Tugaskan</button>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

@foreach ($pegawai as $p)
  <div class="modal fade" id="tugas-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('hr.jadwal.simpan') }}">
        @csrf
        <input type="hidden" name="employee_id" value="{{ $p->id }}">
        <input type="hidden" name="schedule_date" value="{{ $tanggal->toDateString() }}">
        <div class="modal-header">
          <h5 class="modal-title">Tugaskan {{ $p->name }}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Shift</label>
          <select name="work_shift_id" class="form-select mb-2" required>
            @foreach ($shift as $s)
              <option value="{{ $s->id }}">{{ $s->name }} ({{ substr($s->start_time, 0, 5) }}–{{ substr($s->end_time, 0, 5) }})</option>
            @endforeach
          </select>
          <label class="form-label">Unit (opsional)</label>
          <select name="unit_id" class="form-select">
            <option value="">— tidak ditentukan —</option>
            @foreach ($unit as $u)
              <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
@endforeach

@endsection
