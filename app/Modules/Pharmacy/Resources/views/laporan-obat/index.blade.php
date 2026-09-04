@extends('layouts.app')

@section('title', 'Farmasi — Laporan Penggunaan Obat')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Laporan Penggunaan Obat Berbasis Resep')

@section('actions')
  <a href="{{ route('pharmacy.rekap.index') }}" class="btn btn-link">&larr; Rekap Penjualan</a>
@endsection

@section('content')

<p class="text-secondary small mb-3">Menaungi 5 kode Khanza (pengguna_obat_resep, obat_per_resep, obat10_terbanyak_poli, rekap_obat_poli, ringkasan_biaya_obat_pasien_pertanggal) &middot; hanya obat yang sudah diserahkan lewat resep, tidak termasuk penjualan bebas.</p>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label" for="dari">Dari</label>
        <input type="date" id="dari" name="dari" class="form-control" value="{{ $dari }}">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="sampai">Sampai</label>
        <input type="date" id="sampai" name="sampai" class="form-control" value="{{ $sampai }}">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="unit">Unit (untuk 10 Terbanyak)</label>
        <input type="text" id="unit" name="unit" class="form-control" placeholder="Kosongkan untuk semua unit" value="{{ $unitFilter }}">
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">10 Obat Terbanyak {{ $unitFilter ? '— ' . $unitFilter : '(Semua Unit)' }}</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($top10 as $t)
              <tr><td>{{ $t->drug_name }}</td><td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $t->jumlah_unit, 2, ',', '.'), '0'), ',') }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Rekap Obat Per Unit/Poli</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th class="text-center">Resep</th><th class="text-end">Biaya</th></tr></thead>
          <tbody>
            @forelse ($perUnit as $u)
              <tr><td>{{ $u->unit_name ?? '—' }}</td><td class="text-center">{{ $u->jumlah_resep }}</td><td class="text-end font-monospace">Rp {{ number_format((float) $u->total_biaya, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Pengguna Obat (Per Obat)</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th class="text-center">Jumlah Pasien</th><th class="text-end">Total Unit</th></tr></thead>
          <tbody>
            @forelse ($perObat as $o)
              <tr><td>{{ $o->drug_name }}</td><td class="text-center">{{ $o->jumlah_pasien }}</td><td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $o->jumlah_unit, 2, ',', '.'), '0'), ',') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Obat Per Dokter Peresep</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Dokter</th><th class="text-center">Resep</th><th class="text-end">Biaya</th></tr></thead>
          <tbody>
            @forelse ($perDokter as $d)
              <tr><td>{{ $d->prescriber_name }}</td><td class="text-center">{{ $d->jumlah_resep }}</td><td class="text-end font-monospace">Rp {{ number_format((float) $d->total_biaya, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Rekap Obat Per Pasien</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pasien</th><th>No. RM</th><th class="text-center">Resep</th><th class="text-end">Biaya</th></tr></thead>
          <tbody>
            @forelse ($perPasien as $p)
              <tr><td>{{ $p->patient_name }}</td><td class="font-monospace small">{{ $p->patient_mrn }}</td><td class="text-center">{{ $p->jumlah_resep }}</td><td class="text-end font-monospace">Rp {{ number_format((float) $p->total_biaya, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Biaya Obat Pasien Per Tanggal</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Pasien</th><th class="text-end">Biaya</th></tr></thead>
          <tbody>
            @forelse ($biayaPerTanggal as $b)
              <tr><td class="text-secondary small">{{ \Carbon\Carbon::parse($b->tanggal)->format('d-m-Y') }}</td><td>{{ $b->patient_name }}</td><td class="text-end font-monospace">Rp {{ number_format((float) $b->total_biaya, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
