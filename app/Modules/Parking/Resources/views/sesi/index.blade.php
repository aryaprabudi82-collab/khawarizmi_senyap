@extends('layouts.app')

@section('title', 'Parkir — Gerbang')
@section('breadcrumb', 'Konteks parking')
@section('heading', 'Gerbang Parkir')

@section('actions')
  <a href="{{ route('parking.master.index') }}" class="btn btn-link">&larr; Jenis &amp; Kartu</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Kendaraan Masuk</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('parking.sesi.masuk') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3"><label class="form-label">Nomor Kendaraan</label><input type="text" name="vehicle_number" class="form-control" maxlength="15" placeholder="B 1234 XYZ" required></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis Parkir</label>
        <select name="rate_id" class="form-select" required>
          @foreach ($tarifAktif as $t)
            <option value="{{ $t->id }}">{{ $t->name }} — Rp {{ number_format($t->fee, 0, ',', '.') }}/{{ $t->basis === 'jam' ? 'jam' : 'hari' }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Kartu</label>
        <select name="barcode_card_id" class="form-select">
          <option value="">— tanpa kartu —</option>
          @foreach ($kartuTersedia as $k)
            <option value="{{ $k->id }}">{{ $k->card_number }} ({{ $k->barcode }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control" maxlength="255"></div>
      <div class="col-12"><button class="btn btn-primary">Catat Masuk</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Cari Sesi untuk Keluar</h3></div>
  <div class="card-body">
    <form method="GET" action="{{ route('parking.sesi.index') }}" class="row g-2">
      <div class="col-12 col-md-6">
        <label class="form-label">Nomor Kartu, Barcode, atau Nomor Kendaraan</label>
        <input type="text" name="cari" class="form-control" value="{{ $cari }}" placeholder="scan kartu atau ketik plat">
      </div>
      <div class="col-12 col-md-3 d-flex align-items-end"><button class="btn btn-primary">Cari</button></div>
    </form>

    @if ($cari !== '')
      @if ($ditemukan === null)
        <div class="alert alert-warning mt-3 mb-0">Tidak ada sesi terbuka untuk "{{ $cari }}".</div>
      @else
        <div class="alert alert-info mt-3 mb-0 d-flex justify-content-between align-items-center">
          <div>
            <b>{{ $ditemukan->vehicle_number }}</b> &mdash; {{ $ditemukan->rate->name }},
            masuk {{ $ditemukan->entered_at->format('d-m-Y H:i') }}
            ({{ $ditemukan->entered_at->diffForHumans(null, true) }} lalu)
          </div>
          <form method="POST" action="{{ route('parking.sesi.keluar', $ditemukan) }}">
            @csrf
            <button class="btn btn-success">Catat Keluar</button>
          </form>
        </div>
      @endif
    @endif
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Masih di Dalam ({{ $terbuka->count() }})</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kendaraan</th><th>Jenis</th><th>Kartu</th><th>Masuk</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($terbuka as $s)
          <tr>
            <td><b>{{ $s->vehicle_number }}</b></td>
            <td>{{ $s->rate->name }}</td>
            <td class="text-secondary small">{{ $s->barcodeCard?->card_number ?? '—' }}</td>
            <td class="text-secondary small">{{ $s->entered_at->format('d-m-Y H:i') }}</td>
            <td>
              <form method="POST" action="{{ route('parking.sesi.keluar', $s) }}">
                @csrf
                <button class="btn btn-sm btn-outline-success">Keluar</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada kendaraan di dalam.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">20 Sesi Terakhir Selesai</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kendaraan</th><th>Jenis</th><th>Masuk</th><th>Keluar</th><th>Durasi</th><th>Biaya</th></tr></thead>
      <tbody>
        @forelse ($terakhir as $s)
          <tr>
            <td>{{ $s->vehicle_number }}</td>
            <td class="text-secondary small">{{ $s->rate->name }}</td>
            <td class="text-secondary small">{{ $s->entered_at->format('d-m H:i') }}</td>
            <td class="text-secondary small">{{ $s->exited_at->format('d-m H:i') }}</td>
            <td>{{ $s->duration_minutes }} menit</td>
            <td>Rp {{ number_format($s->total_fee, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada sesi selesai.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
