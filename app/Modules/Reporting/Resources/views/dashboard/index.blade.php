@extends('layouts.app')

@section('title', 'Dashboard Pelaporan')
@section('breadcrumb', 'Konteks reporting')
@section('heading', 'Rekap Kunjungan, Diagnosis & Pendapatan')

@section('actions')
  <form method="GET" action="{{ route('reporting.dashboard') }}" class="d-flex gap-2">
    <input type="date" name="tanggal" class="form-control form-control-sm" value="{{ $tanggal->toDateString() }}" onchange="this.form.submit()">
  </form>
@endsection

@section('content')

<form method="POST" action="{{ route('reporting.sinkron') }}" class="mb-3">
  @csrf
  <input type="hidden" name="tanggal" value="{{ $tanggal->toDateString() }}">
  <button class="btn btn-outline-primary btn-sm">Sinkronkan {{ $tanggal->format('d-m-Y') }}</button>
  <span class="text-secondary small ms-2">Menghitung ulang rekap dari data encounter/clinical/billing hari ini. Produksi semestinya menjadwalkan ini tiap jam.</span>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Total Kunjungan</div>
      <div class="fs-2 fw-bold font-monospace">{{ $totalKunjungan }}</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Total Pendapatan</div>
      <div class="fs-2 fw-bold font-monospace">Rp {{ number_format((float) $pendapatan->sum('total_amount'), 0, ',', '.') }}</div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kunjungan per Unit &amp; Penjamin</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th>Penjamin</th><th class="text-end">Kunjungan</th></tr></thead>
          <tbody>
            @forelse ($kunjungan as $k)
              <tr>
                <td>{{ $k->unit_name }}</td>
                <td><span class="badge bg-secondary-lt text-uppercase">{{ $k->payer_kind }}</span></td>
                <td class="text-end font-monospace">{{ $k->visit_count }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum disinkronkan untuk tanggal ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Pendapatan per Penjamin</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Penjamin</th><th class="text-end">Tagihan</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($pendapatan as $p)
              <tr>
                <td><span class="badge bg-secondary-lt text-uppercase">{{ $p->payer_kind }}</span></td>
                <td class="text-end font-monospace">{{ $p->invoice_count }}</td>
                <td class="text-end font-monospace">Rp {{ number_format((float) $p->total_amount, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum disinkronkan untuk tanggal ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">10 Diagnosis Terbanyak</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Diagnosis</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($diagnosis as $d)
              <tr>
                <td class="font-monospace small">{{ $d->code }}</td>
                <td>{{ $d->display }}</td>
                <td class="text-end font-monospace">{{ $d->occurrence_count }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada diagnosis tercatat untuk tanggal ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
