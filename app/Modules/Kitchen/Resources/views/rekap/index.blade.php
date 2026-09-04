@extends('layouts.app')

@section('title', 'Dapur — Rekap')
@section('breadcrumb', 'Konteks kitchen')
@section('heading', 'Rekap Dapur & Gizi')

@section('actions')
  <a href="{{ route('kitchen.hibah.index') }}" class="btn btn-link">Hibah &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Rentang Tanggal</h3></div>
  <div class="card-body">
    <form method="GET" action="{{ route('kitchen.rekap.index') }}" class="row g-2">
      <div class="col-6 col-md-3">
        <label class="form-label">Dari</label>
        <input type="date" name="dari" class="form-control" value="{{ $dari }}">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Sampai</label>
        <input type="date" name="sampai" class="form-control" value="{{ $sampai }}">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Biaya Pengadaan — Tanggal</label>
        <input type="date" name="tanggal" class="form-control" value="{{ $tanggalHarian }}">
      </div>
      <div class="col-6 col-md-3 d-flex align-items-end">
        <button class="btn btn-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Permintaan Unit</h3></div>
      <div class="card-body">
        <div class="datagrid">
          @forelse ($permintaan['per_status'] as $status => $jumlah)
            <div class="datagrid-item">
              <div class="datagrid-title">{{ $status }}</div>
              <div class="datagrid-content">{{ $jumlah }}</div>
            </div>
          @empty
            <div class="text-secondary py-2">Tidak ada permintaan di rentang ini.</div>
          @endforelse
        </div>
        <div class="mt-2 text-secondary small">Total: {{ $permintaan['total'] }} permintaan</div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Pengadaan (PO)</h3></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <thead><tr><th>Status</th><th class="text-end">Jumlah</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($pengadaan['per_status'] as $status => $r)
              <tr>
                <td>{{ $status }}</td>
                <td class="text-end font-monospace">{{ $r->jumlah }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $r->nilai, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-2">Tidak ada PO di rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
        <div class="mt-2 text-secondary small">Total: {{ $pengadaan['total_po'] }} PO, Rp {{ number_format($pengadaan['total_nilai'], 0, ',', '.') }}</div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Biaya Pengadaan Harian</h3></div>
      <div class="card-body">
        <div class="h1 mb-0">Rp {{ number_format($pengeluaranHarian, 0, ',', '.') }}</div>
        <div class="text-secondary small">Nilai barang diterima pada {{ \Illuminate\Support\Carbon::parse($tanggalHarian)->format('d-m-Y') }}</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Penerimaan per Suplier</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Suplier</th><th class="text-end">Jumlah Penerimaan</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($penerimaan as $r)
              <tr>
                <td>{{ $r->supplier_name }}</td>
                <td class="text-end font-monospace">{{ $r->jumlah_penerimaan }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $r->nilai, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada penerimaan di rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-3">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Stok Keluar per Sumber</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sumber</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            @forelse ($stokKeluar as $r)
              <tr>
                <td class="text-secondary small">{{ $r->source }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $r->total_keluar, 2, ',', '.'), '0'), ',') }}</td>
              </tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada stok keluar di rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-3">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Retur ke Suplier</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Status</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($retur as $r)
              <tr>
                <td>{{ $r->status }}</td>
                <td class="text-end font-monospace">{{ $r->jumlah }}</td>
              </tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada retur di rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
