@extends('layouts.app')

@section('title', 'Sensus & Kunjungan')
@section('breadcrumb', 'Konteks reporting')
@section('heading', 'Sensus &amp; Kunjungan')

@section('actions')
  <a href="{{ route('reporting.dashboard') }}" class="btn btn-link">&larr; Dasbor</a>
@endsection

@section('content')

<div class="alert alert-info">
  Kunjungan yang <b>dibatalkan tidak ikut dihitung</b> di mana pun &mdash; kunjungan yang batal bukan kunjungan.
  Jumlahnya sendiri ditampilkan terpisah di kartu Pembatalan.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis Rawat</label>
        <select name="jenis_rawat" class="form-select">
          <option value="">Semua</option>
          <option value="ralan" @selected($jenisRawat === 'ralan')>Rawat Jalan</option>
          <option value="ranap" @selected($jenisRawat === 'ranap')>Rawat Inap</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Unit/Poliklinik</label>
        <select name="unit_id" class="form-select">
          <option value="">Semua unit</option>
          @foreach ($unit as $u)
            <option value="{{ $u->id }}" @selected($unitId === $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-1"><label class="form-label">Tahun</label><input type="number" name="tahun" class="form-control" value="{{ $tahun }}" min="2000" max="2100"></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Sensus harian</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Jenis</th><th class="text-end">Kunjungan</th></tr></thead>
          <tbody>
            @forelse ($harian as $b)
              <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($b->service_date)->format('d-m-Y') }}</td>
                <td><span class="badge bg-{{ $b->care_type === 'ranap' ? 'purple' : 'blue' }}-lt">{{ $b->care_type }}</span></td>
                <td class="text-end">{{ $b->jumlah }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada kunjungan pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per unit/poliklinik</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th class="text-end">Kunjungan</th><th class="text-end">Pasien</th></tr></thead>
          <tbody>
            @forelse ($perUnit as $b)
              <tr><td>{{ $b->unit_name }}</td><td class="text-end">{{ $b->jumlah }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per dokter</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Dokter</th><th class="text-end">Kunjungan</th></tr></thead>
          <tbody>
            @forelse ($perDokter as $b)
              <tr><td>{{ $b->practitioner_name }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per penjamin</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Penjamin</th><th class="text-end">Kunjungan</th></tr></thead>
          <tbody>
            @forelse ($perPenjamin as $b)
              <tr><td>{{ $b->payer_name }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kelompok umur</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kelompok</th><th class="text-end">Kunjungan</th></tr></thead>
          <tbody>
            @forelse ($perUmur as $b)
              <tr><td>{{ $b->kelompok }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data (pasien tanpa tanggal lahir tidak dihitung).</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kedatangan per jam</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Jam</th><th class="text-end">Kunjungan</th></tr></thead>
          <tbody>
            @forelse ($perJam as $b)
              <tr><td>{{ str_pad((int) $b->jam, 2, '0', STR_PAD_LEFT) }}.00</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pembatalan periksa</h3><div class="card-subtitle">Tidak ikut dihitung sebagai kunjungan</div></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th>Dokter</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($batal as $b)
              <tr><td>{{ $b->unit_name }}</td><td>{{ $b->practitioner_name }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pembatalan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Rekap bulanan {{ $tahun }}</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Bulan</th><th class="text-end">Kunjungan</th><th class="text-end">Pasien</th></tr></thead>
      <tbody>
        @forelse ($bulanan as $b)
          <tr>
            <td>{{ \Illuminate\Support\Carbon::create()->month((int) $b->bulan)->translatedFormat('F') }}</td>
            <td class="text-end">{{ $b->jumlah }}</td>
            <td class="text-end">{{ $b->pasien }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data pada tahun ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rawat inap per ruang</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Ruang</th><th>Kelas</th><th class="text-end">Admisi</th></tr></thead>
          <tbody>
            @forelse ($perRuang as $b)
              <tr><td>{{ $b->room_number }}</td><td class="text-uppercase small">{{ $b->room_class }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada admisi pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Asal pasien rawat inap</h3>
        <div class="card-subtitle">
          <a href="{{ request()->fullUrlWithQuery(['asal' => 'unit']) }}" class="{{ $asal === 'unit' ? 'fw-bold' : '' }}">per poli</a> &middot;
          <a href="{{ request()->fullUrlWithQuery(['asal' => 'dokter']) }}" class="{{ $asal === 'dokter' ? 'fw-bold' : '' }}">per dokter</a>
        </div>
      </div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Asal</th><th class="text-end">Admisi</th></tr></thead>
          <tbody>
            @forelse ($asalRanap as $b)
              <tr><td>{{ $b->asal }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Permintaan penunjang</h3>
    <div class="card-subtitle">Termasuk yang belum selesai dan dibatalkan &mdash; yang dihitung permintaannya, bukan tagihannya</div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kategori</th><th>Status</th><th class="text-end">Permintaan</th></tr></thead>
      <tbody>
        @forelse ($penunjang as $b)
          <tr><td class="text-uppercase">{{ $b->category }}</td><td>{{ $b->status }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada permintaan penunjang.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar pasien rawat inap</h3><div class="card-subtitle">500 terbaru</div></div>
  <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Admisi</th><th>Pasien</th><th>Ruang</th><th>DPJP</th><th>Masuk</th><th>Pulang</th><th>Status</th></tr></thead>
      <tbody>
        @forelse ($admisi as $b)
          <tr>
            <td class="font-monospace small">{{ $b->admission_number }}</td>
            <td>{{ $b->patient_name }}<div class="text-secondary small font-monospace">{{ $b->patient_mrn }}</div></td>
            <td>{{ $b->room_number }} / {{ $b->bed_number }}<div class="text-secondary small text-uppercase">{{ $b->room_class }}</div></td>
            <td class="small">{{ $b->dpjp_name ?? '—' }}</td>
            <td class="text-secondary small">{{ \Illuminate\Support\Carbon::parse($b->admitted_at)->format('d-m-Y H:i') }}</td>
            <td class="text-secondary small">{{ $b->discharged_at ? \Illuminate\Support\Carbon::parse($b->discharged_at)->format('d-m-Y H:i') : '—' }}</td>
            <td><span class="badge bg-{{ $b->status === 'dirawat' ? 'green' : 'secondary' }}-lt">{{ $b->status }}</span></td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada admisi pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
