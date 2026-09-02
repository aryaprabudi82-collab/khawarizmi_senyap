@extends('layouts.app')

@section('title', 'Kepegawaian — Pegawai')
@section('breadcrumb', 'Konteks hr')
@section('heading', 'Pegawai & Jenis Cuti')

@section('actions')
  <a href="{{ route('hr.cuti.index') }}" class="btn btn-link">Cuti &rarr;</a>
  <a href="{{ route('hr.presensi.index') }}" class="btn btn-link">Presensi &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pegawai</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>NIP/NIK</th><th>Nama</th><th>Jabatan</th><th>Status</th><th>Unit</th><th>Aktif</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($pegawai as $p)
              <tr>
                <td class="font-monospace small">{{ $p->employee_number }}</td>
                <td>{{ $p->name }}</td>
                <td>{{ $p->position }}</td>
                <td><span class="badge bg-secondary-lt text-uppercase">{{ $p->employment_type }}</span></td>
                <td class="text-secondary">{{ $unit->firstWhere('id', $p->unit_id)->name ?? '—' }}</td>
                <td>
                  @if ($p->is_active)
                    <span class="badge bg-green-lt">Aktif</span>
                  @else
                    <span class="badge bg-red-lt">Nonaktif</span>
                  @endif
                </td>
                <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-pegawai-{{ $p->id }}">Ubah</button></td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada pegawai.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.pegawai.simpan') }}" class="row g-2">
          @csrf
          <div class="col-6 col-md-2"><input type="text" name="employee_number" class="form-control form-control-sm" placeholder="NIP/NIK" required></div>
          <div class="col-6 col-md-3"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-6 col-md-2"><input type="text" name="position" class="form-control form-control-sm" placeholder="Jabatan" required></div>
          <div class="col-6 col-md-2">
            <select name="employment_type" class="form-select form-select-sm">
              <option value="tetap">Tetap</option>
              <option value="kontrak">Kontrak</option>
              <option value="honorer">Honorer</option>
              <option value="magang">Magang</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="unit_id" class="form-select form-select-sm">
              <option value="">— unit —</option>
              @foreach ($unit as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="practitioner_id" class="form-select form-select-sm">
              <option value="">— praktisi (opsional) —</option>
              @foreach ($praktisi as $pr)
                <option value="{{ $pr->id }}">{{ $pr->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2"><input type="date" name="hire_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6 col-md-2"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Telepon"></div>
          <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Jenis Cuti</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jatah/th</th></tr></thead>
          <tbody>
            @forelse ($jenisCuti as $j)
              <tr>
                <td class="font-monospace small">{{ $j->code }}</td>
                <td>{{ $j->name }}</td>
                <td>{{ $j->annual_quota ?? 'Tak dibatasi' }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada jenis cuti.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.jenis-cuti.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-3"><input type="number" name="annual_quota" class="form-control form-control-sm" placeholder="Jatah"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

@foreach ($pegawai as $p)
  <div class="modal fade" id="edit-pegawai-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('hr.pegawai.perbarui', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Pegawai</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $p->name }}" required></div>
          <div class="mb-2"><label class="form-label">Jabatan</label><input type="text" name="position" class="form-control" value="{{ $p->position }}" required></div>
          <div class="mb-2">
            <label class="form-label">Status Kepegawaian</label>
            <select name="employment_type" class="form-select">
              @foreach (['tetap','kontrak','honorer','magang'] as $t)
                <option value="{{ $t }}" @selected($p->employment_type === $t)>{{ $t }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Tanggal Berhenti</label><input type="date" name="termination_date" class="form-control" value="{{ $p->termination_date?->toDateString() }}"></div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($p->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
