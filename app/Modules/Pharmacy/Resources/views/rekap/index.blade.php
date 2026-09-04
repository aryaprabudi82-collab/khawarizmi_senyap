@extends('layouts.app')

@section('title', 'Farmasi — Rekap Penjualan & Untung')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Rekap Penjualan & Untung Farmasi')

@section('actions')
  <a href="{{ route('pharmacy.penjualan.index') }}" class="btn btn-link">&larr; Penjualan</a>
@endsection

@section('content')

<p class="text-secondary small mb-3">Menaungi 9 kode Khanza (keuntungan_penjualan, keuntungan_beri_obat, keuntungan_beri_obat_nonpiutang, ringkasan_penjualan_obat, ringkasan_retur_pembeli_obat, ringkasan_piutang_obat, ringkasan_stok_keluar_obat, ringkasan_beri_obat, ringkasan_hibah_obat).</p>

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
      <div class="col-12 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

<div class="row row-deck row-cards mb-3">
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Omzet Penjualan Bebas</div>
      <div class="h2 mb-0">Rp {{ number_format($penjualan['omzet'], 0, ',', '.') }}</div>
      <div class="text-secondary small">{{ $penjualan['jumlah_transaksi'] }} transaksi</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Untung Penjualan Bebas</div>
      <div class="h2 mb-0 text-success">Rp {{ number_format($penjualan['untung'], 0, ',', '.') }}</div>
      <div class="text-secondary small">HPP Rp {{ number_format($penjualan['hpp'], 0, ',', '.') }}</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Piutang Berjalan</div>
      <div class="h2 mb-0 text-orange">Rp {{ number_format($penjualan['piutang_sisa'], 0, ',', '.') }}</div>
      <div class="text-secondary small">{{ $penjualan['piutang_jumlah'] }} transaksi</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Retur Pembeli</div>
      <div class="h2 mb-0">{{ $penjualan['retur_jumlah'] }}</div>
      <div class="text-secondary small">{{ rtrim(rtrim(number_format($penjualan['retur_unit'], 2, ',', '.'), '0'), ',') }} unit</div>
    </div></div>
  </div>
</div>

<div class="row row-deck row-cards mb-3">
  <div class="col-6 col-md-4">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Omzet Resep Diserahkan</div>
      <div class="h2 mb-0">Rp {{ number_format($beriObat['omzet'], 0, ',', '.') }}</div>
      <div class="text-secondary small">{{ $beriObat['jumlah_resep'] }} resep</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Untung Beri Obat <span class="text-secondary">(estimasi, HPP rata-rata batch aktif)</span></div>
      <div class="h2 mb-0 text-success">Rp {{ number_format($beriObat['untung'], 0, ',', '.') }}</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Hibah Diterima</div>
      <div class="h2 mb-0">{{ $hibah['jumlah_penerimaan'] }}</div>
      <div class="text-secondary small">{{ rtrim(rtrim(number_format($hibah['jumlah_unit'], 2, ',', '.'), '0'), ',') }} unit</div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Stok Keluar Per Obat (Ringkasan Stok Keluar)</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Obat</th><th class="text-end">Jumlah Keluar</th></tr></thead>
      <tbody>
        @forelse ($stokKeluar as $s)
          <tr><td>{{ $s->drug_name }}</td><td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $s->jumlah, 2, ',', '.'), '0'), ',') }}</td></tr>
        @empty
          <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada stok keluar pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
