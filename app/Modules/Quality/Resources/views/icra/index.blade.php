@extends('layouts.app')

@section('title', 'Mutu — PCRA/ICRA')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'PCRA/ICRA — Kajian Risiko Pra-Konstruksi')

@section('actions')
  <a href="{{ route('quality.insiden.index') }}" class="btn btn-link">&larr; Insiden</a>
  <a href="{{ route('quality.icra.master.index') }}" class="btn btn-link">Master ICRA &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Kajian Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('quality.icra.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4"><label class="form-label">Nama Proyek</label><input type="text" name="project_name" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Uraian Aktivitas</label><input type="text" name="project_type" class="form-control" placeholder="mis. renovasi plafon" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Lokasi (uraian)</label><input type="text" name="location" class="form-control" required></div>
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

      {{-- KELAS PENCEGAHAN TIDAK LAGI DIKETIK: ia dihitung dari matriks
           (tipe aktivitas x kelompok risiko area). Dengan kelas yang diketik,
           proyek Tipe D di ruang isolasi bisa tercatat Kelas I dan tidak ada
           yang menolaknya &mdash; lalu dokumen ICRA-nya justru jadi bukti bahwa
           rumah sakit sudah menilai dan menyimpulkan boleh. --}}
      <div class="col-12"><hr class="my-1"><div class="form-label mb-0">Penentu Kelas Pencegahan</div>
        <div class="form-hint">Kelas dihitung dari matriks ICRA, bukan dipilih. Sel tertentu memberi RENTANG &mdash; pedomannya menyerahkan pilihan kepada komite pengendalian infeksi, dan pada sel itu kelas serta pemutusnya wajib diisi.</div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Tipe Aktivitas Proyek</label>
        <select name="activity_type_id" class="form-select" required>
          @foreach ($aktivitas as $a)
            <option value="{{ $a->id }}">{{ $a->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Area Terdampak</label>
        <select name="area_id" class="form-select" required>
          @foreach ($area as $ar)
            <option value="{{ $ar->id }}">{{ $ar->name }} &middot; {{ $ar->riskGroup->name }}</option>
          @endforeach
        </select>
        @if ($area->isEmpty())
          <div class="form-hint text-danger">Belum ada area terdaftar &mdash; isi dulu di Master ICRA. Kelompok risikonya keputusan RSP UI, bukan tebakan sistem.</div>
        @endif
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Kelas Pilihan (bila matriks memberi rentang)</label>
        <select name="chosen_class_id" class="form-select">
          <option value="">&mdash; ikut matriks &mdash;</option>
          @foreach ($kelas as $k)
            <option value="{{ $k->id }}">{{ $k->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4"><label class="form-label">Diputuskan Oleh</label><input type="text" name="class_decided_by" class="form-control" placeholder="Nama IPCN/komite"></div>
      <div class="col-12 col-md-8"><label class="form-label">Alasan Pemilihan Kelas</label><input type="text" name="class_decision_reason" class="form-control"></div>
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
