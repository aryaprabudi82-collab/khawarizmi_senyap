@extends('layouts.app')

@section('title', 'Farmasi — Laporan Stok')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Laporan Stok Farmasi')

@section('actions')
  @can('stok_opname_obat')
    <a href="{{ route('pharmacy.opname.index') }}" class="btn btn-outline-primary">Stok Opname</a>
  @endcan
  @can('mutasi_barang')
    <a href="{{ route('pharmacy.mutasi.index') }}" class="btn btn-outline-primary">Mutasi</a>
  @endcan
@endsection

@section('content')

<p class="text-secondary small mb-3">Menaungi 12 kode Khanza (sisa_stok, darurat_stok, data_batch, kadaluarsa_batch, riwayat_data_batch, obat_bhp_tidakbergerak, stok_akhir_farmasi_pertanggal, sirkulasi_obat s/d sirkulasi_obat6) &middot; semua dibaca langsung dari stock_batches &amp; stock_movements, bukan tabel referensi terpisah.</p>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label" for="location_id">Lokasi</label>
        <select id="location_id" name="location_id" class="form-select">
          <option value="">— semua lokasi —</option>
          @foreach ($lokasi as $l)
            <option value="{{ $l->id }}" @selected($lokasiId == $l->id)>{{ $l->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="tanggal">Stok Akhir Per Tanggal</label>
        <input type="date" id="tanggal" name="tanggal" class="form-control" value="{{ $tanggal }}">
      </div>
      <div class="col-6 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Sisa Stok</h3></div>
      <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th>Lokasi</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($sisaStok as $s)
              <tr>
                <td>{{ $s->drug_name }}</td>
                <td class="text-secondary small">{{ $s->location_name }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $s->total, 2, ',', '.'), '0'), ',') }} {{ $s->unit }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada stok.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Darurat Stok <span class="text-secondary small">(di bawah stok minimum)</span></h3></div>
      <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th class="text-end">Stok</th><th class="text-end">Minimum</th></tr></thead>
          <tbody>
            @forelse ($daruratStok as $s)
              <tr class="table-danger">
                <td>{{ $s->drug_name }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $s->total, 2, ',', '.'), '0'), ',') }} {{ $s->unit }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $s->minimum_stock, 2, ',', '.'), '0'), ',') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada obat di bawah stok minimum.</td></tr>
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
      <div class="card-header"><h3 class="card-title">Data Batch</h3></div>
      <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th>Batch</th><th>Lokasi</th><th class="text-end">Jumlah</th><th>Kedaluwarsa</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($batch as $b)
              <tr>
                <td>{{ $b->drug->name }}</td>
                <td class="font-monospace small">{{ $b->batch_number }}</td>
                <td class="text-secondary small">{{ $b->location->name }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $b->quantity_on_hand, 2, ',', '.'), '0'), ',') }}</td>
                <td class="text-secondary small">{{ $b->expiry_date?->format('d-m-Y') ?? '—' }}</td>
                <td><a href="{{ route('pharmacy.laporan-stok.riwayat-batch', $b->id) }}" class="btn btn-sm btn-outline-secondary">Riwayat</a></td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada batch.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kadaluarsa Batch <span class="text-secondary small">(90 hari, perlu pilih lokasi)</span></h3></div>
      <div class="table-responsive" style="max-height:150px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th>Batch</th><th>Kedaluwarsa</th></tr></thead>
          <tbody>
            @forelse ($kedaluwarsa as $b)
              <tr class="table-warning">
                <td>{{ $b->drug->name }}</td>
                <td class="font-monospace small">{{ $b->batch_number }}</td>
                <td>{{ $b->expiry_date->format('d-m-Y') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">{{ $lokasiId ? 'Tidak ada batch mendekati kedaluwarsa.' : 'Pilih lokasi untuk melihat.' }}</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Obat/BHP Tidak Bergerak <span class="text-secondary small">(90 hari)</span></h3></div>
      <div class="table-responsive" style="max-height:150px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th>Batch</th><th class="text-end">Stok</th></tr></thead>
          <tbody>
            @forelse ($tidakBergerak as $b)
              <tr>
                <td>{{ $b->drug->name }}</td>
                <td class="font-monospace small">{{ $b->batch_number }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $b->quantity_on_hand, 2, ',', '.'), '0'), ',') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada obat yang tidak bergerak.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Stok Akhir Per {{ \Carbon\Carbon::parse($tanggal)->format('d-m-Y') }}</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th>Batch</th><th class="text-end">Saldo</th></tr></thead>
          <tbody>
            @forelse ($saldoPerTanggal as $s)
              <tr>
                <td>{{ $s->drug_name }}</td>
                <td class="font-monospace small">{{ $s->batch_number }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $s->balance_after, 2, ',', '.'), '0'), ',') }} {{ $s->unit }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pergerakan sampai tanggal ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Sirkulasi Obat, Alkes &amp; BHP <span class="text-secondary small">(200 terbaru)</span></h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Waktu</th><th>Obat</th><th>Lokasi</th><th>Jenis</th><th class="text-end">Jumlah</th><th class="text-end">Saldo</th></tr></thead>
          <tbody>
            @forelse ($sirkulasi as $m)
              <tr>
                <td class="text-secondary small">{{ \Carbon\Carbon::parse($m->moved_at)->format('d-m-Y H:i') }}</td>
                <td>{{ $m->drug_name }}</td>
                <td class="text-secondary small">{{ $m->location_name }}</td>
                <td><span class="badge bg-secondary-lt">{{ $m->kind }}</span></td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->balance_after, 2, ',', '.'), '0'), ',') }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada pergerakan pada filter ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
