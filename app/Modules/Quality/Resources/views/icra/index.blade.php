@extends('layouts.app')

@section('title', 'Mutu — PCRA/ICRA')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'PCRA/ICRA — Kajian Risiko Pra-Konstruksi')

@section('actions')
  <a href="{{ route('quality.insiden.index') }}" class="btn btn-link">&larr; Insiden</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Kajian Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('quality.icra.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4"><label class="form-label">Nama Proyek</label><input type="text" name="project_name" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Jenis Aktivitas</label><input type="text" name="project_type" class="form-control" placeholder="mis. renovasi" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Lokasi</label><input type="text" name="location" class="form-control" required></div>
      <div class="col-12 col-md-2">
        <label class="form-label">Unit</label>
        <select name="unit_id" class="form-select">
          <option value="">— unit —</option>
          @foreach ($unit as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
      </div>

      @foreach (['infection_risk_level' => 'Risiko Infeksi', 'fire_risk_level' => 'Risiko Kebakaran', 'safety_risk_level' => 'Risiko Keselamatan', 'utility_risk_level' => 'Risiko Utilitas'] as $field => $label)
        <div class="col-6 col-md-3">
          <label class="form-label">{{ $label }}</label>
          <select name="{{ $field }}" class="form-select" required>
            <option value="rendah">Rendah</option>
            <option value="sedang">Sedang</option>
            <option value="tinggi">Tinggi</option>
            <option value="sangat-tinggi">Sangat Tinggi</option>
          </select>
        </div>
      @endforeach

      <div class="col-6 col-md-3">
        <label class="form-label">Kelas Risiko</label>
        <select name="risk_class" class="form-select" required>
          <option value="I">Kelas I</option>
          <option value="II">Kelas II</option>
          <option value="III">Kelas III</option>
          <option value="IV">Kelas IV</option>
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Berlaku Sampai</label><input type="date" name="valid_until" class="form-control"></div>

      <div class="col-12 col-md-6"><label class="form-label">Persyaratan yang Harus Dipenuhi</label><textarea name="required_precautions" class="form-control" rows="2"></textarea></div>
      <div class="col-12 col-md-6"><label class="form-label">Tindakan Pengendalian</label><textarea name="control_measures" class="form-control" rows="2"></textarea></div>

      <div class="col-12"><button class="btn btn-primary">Simpan Kajian</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Kajian Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Kajian</th><th>Proyek</th><th>Lokasi</th><th>Kelas</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($kajian as $k)
          <tr>
            <td class="font-monospace small">{{ $k->assessment_number }}</td>
            <td>{{ $k->project_name }}</td>
            <td class="text-secondary small">{{ $k->location }}</td>
            <td><span class="badge bg-secondary-lt">Kelas {{ $k->risk_class }}</span></td>
            <td>
              @php $warna = ['aktif' => 'yellow', 'selesai' => 'green', 'dibatalkan' => 'red'][$k->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $k->status }}</span>
            </td>
            <td>
              @if ($k->status === 'aktif')
                <div class="btn-group">
                  <form method="POST" action="{{ route('quality.icra.selesai', $k) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-success">Selesai</button>
                  </form>
                </div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada kajian ICRA.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
