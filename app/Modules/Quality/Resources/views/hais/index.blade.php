@extends('layouts.app')

@section('title', 'Surveilans HAIs')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'Surveilans HAIs')

@section('content')

<div class="alert alert-info">
  <b>Angka HAIs dilaporkan sebagai insiden per 1000 hari-alat, bukan sebagai jumlah kejadian.</b>
  Jumlah kejadian saja membuat bangsal yang merawat lebih banyak pasien selalu terlihat lebih buruk daripada
  bangsal kecil &mdash; padahal bisa jadi justru lebih aman per pasiennya. Karena itu tiap angka di bawah datang
  berpasangan dengan penyebutnya, dan kalau penyebutnya belum dicatat, rate-nya ditampilkan kosong &mdash;
  bukan disamakan dengan jumlah kejadian.
</div>

@if ($hariTanpaPenyebut > 0 || $bangsalTanpaPenyebut->isNotEmpty())
  <div class="alert alert-danger">
    <b>Pencatatan penyebut belum lengkap.</b>
    @if ($hariTanpaPenyebut > 0)
      <div>{{ $hariTanpaPenyebut }} hari pada rentang ini belum punya catatan hari-alat sama sekali.</div>
    @endif
    @if ($bangsalTanpaPenyebut->isNotEmpty())
      <div>
        Bangsal yang punya kejadian tapi belum mencatat penyebut:
        <b>{{ $bangsalTanpaPenyebut->implode(', ') }}</b> &mdash; rate untuk bangsal itu tidak bisa dihitung.
      </div>
    @endif
  </div>
@endif

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Bangsal</label><input type="text" name="unit" class="form-control" value="{{ $unit }}" placeholder="Semua bangsal"></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis (untuk tabel per bangsal)</label>
        <select name="jenis" class="form-select">
          <option value="">Semua jenis &mdash; penyebut hari-rawat</option>
          @foreach ($daftarJenis as $k => $label)
            <option value="{{ $k }}" @selected($jenis === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Angka HAIs per Jenis Infeksi</h3><div class="card-subtitle">Insiden per 1000 hari-alat atau hari-rawat</div></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Jenis</th><th class="text-end">Kejadian</th><th class="text-end">Penyebut</th><th>Satuan</th><th class="text-end">Rate /1000</th></tr></thead>
      <tbody>
        @foreach ($perJenis as $b)
          <tr>
            <td>{{ $b->label }}</td>
            <td class="text-end">{{ $b->jumlah }}</td>
            <td class="text-end">{{ $b->penyebut }}</td>
            <td class="text-secondary small">{{ $b->satuan_penyebut }}</td>
            <td class="text-end">
              @if ($b->rate === null)
                <span class="text-danger" title="Penyebut belum dicatat">&mdash;</span>
              @else
                <b>{{ $b->rate }}</b>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Angka HAIs per Bangsal</h3>
    <div class="card-subtitle">{{ $jenis ? $daftarJenis[$jenis] : 'Semua jenis, penyebut hari-rawat' }} &mdash; urutkan menurut rate, bukan jumlah</div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Bangsal</th><th class="text-end">Kejadian</th><th class="text-end">Penyebut</th><th class="text-end">Rate /1000</th></tr></thead>
      <tbody>
        @forelse ($perBangsal as $b)
          <tr>
            <td>{{ $b->unit_name }}</td>
            <td class="text-end">{{ $b->jumlah }}</td>
            <td class="text-end">{{ $b->penyebut }}</td>
            <td class="text-end">{{ $b->rate === null ? '—' : $b->rate }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada kejadian maupun penyebut tercatat pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kejadian Harian</h3></div>
      <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Jenis</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($harian as $b)
              <tr><td>{{ $b->onset_on }}</td><td class="text-uppercase small">{{ $b->infection_type }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada kejadian.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kejadian Bulanan</h3></div>
      <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Bulan</th><th>Jenis</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($bulanan as $b)
              <tr><td>{{ $b->bulan }}</td><td class="text-uppercase small">{{ $b->infection_type }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada kejadian.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Kejadian Infeksi</h3></div>
      <form method="POST" action="{{ route('quality.hais.simpan') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-6"><label class="form-label">No. RM</label><input name="patient_mrn" class="form-control" required></div>
          <div class="col-6"><label class="form-label">ID Pasien</label><input name="patient_id" type="number" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Nama Pasien</label><input name="patient_name" class="form-control" required></div>
          <div class="col-6"><label class="form-label">Bangsal</label><input name="unit_name" class="form-control" required></div>
          <div class="col-6">
            <label class="form-label">Jenis Infeksi</label>
            <select name="infection_type" class="form-select" required>
              @foreach ($daftarJenis as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Alat Terpasang</label>
            <select name="device" class="form-select">
              <option value="">&mdash;</option>
              @foreach ($daftarAlat as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
            </select>
          </div>
          <div class="col-6"><label class="form-label">Tanggal Onset</label><input name="onset_on" type="date" class="form-control" value="{{ $sampai }}" required></div>
          <div class="col-6"><label class="form-label">Hari ke- sejak masuk</label><input name="days_after_admission" type="number" min="0" class="form-control"></div>
          <div class="col-6"><label class="form-label">Hasil Kultur</label><input name="culture_result" class="form-control" placeholder="Kosongkan bila belum ada"></div>
          <div class="col-12"><label class="form-label">Dasar Penetapan (kriteria surveilans)</label><textarea name="clinical_criteria" class="form-control" rows="2" required></textarea></div>
        </div>
        <button class="btn btn-primary mt-3">Simpan Kejadian</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Catat Penyebut Harian</h3>
        <div class="card-subtitle">Menghitung ulang tanggal yang sama akan <b>mengganti</b>, bukan menambah</div>
      </div>
      <form method="POST" action="{{ route('quality.hais.penyebut') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-12"><label class="form-label">Bangsal</label><input name="unit_name" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Tanggal</label><input name="counted_on" type="date" class="form-control" value="{{ $sampai }}" required></div>
          <div class="col-6"><label class="form-label">Hari-rawat</label><input name="patient_days" type="number" min="0" class="form-control" value="0"></div>
          <div class="col-6"><label class="form-label">Hari ventilator</label><input name="ventilator_days" type="number" min="0" class="form-control" value="0"></div>
          <div class="col-6"><label class="form-label">Hari central line</label><input name="central_line_days" type="number" min="0" class="form-control" value="0"></div>
          <div class="col-6"><label class="form-label">Hari kateter urin</label><input name="urinary_catheter_days" type="number" min="0" class="form-control" value="0"></div>
          <div class="col-12"><label class="form-label">Hari infus perifer</label><input name="peripheral_line_days" type="number" min="0" class="form-control" value="0"></div>
        </div>
        <button class="btn btn-primary mt-3">Simpan Penyebut</button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Kejadian</h3></div>
  <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Tanggal</th><th>Pasien</th><th>Bangsal</th><th>Jenis</th><th>Alat</th><th>Kultur</th></tr></thead>
      <tbody>
        @forelse ($kejadian as $b)
          <tr>
            <td class="font-monospace small">{{ $b->event_number }}</td>
            <td>{{ $b->onset_on }}</td>
            <td>{{ $b->patient_name }} <span class="text-secondary small">{{ $b->patient_mrn }}</span></td>
            <td>{{ $b->unit_name }}</td>
            <td class="text-uppercase small">{{ $b->infection_type }}</td>
            <td class="text-secondary small">{{ $b->device ?: '—' }}</td>
            <td class="text-secondary small">{{ $b->culture_result ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada kejadian pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
