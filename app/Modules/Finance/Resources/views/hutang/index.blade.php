@extends('layouts.app')

@section('title', 'Hutang Vendor')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Hutang Vendor')

@section('actions')
  <a href="{{ route('kas.index') }}" class="btn btn-link">Kas Harian &rarr;</a>
@endsection

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Satu buku hutang untuk empat rantai pengadaan</b> &mdash; obat &amp; BHP, non-medis, dapur, dan aset.
  Hutang kepada vendor adalah hutang yang sama; yang membedakan cuma dari rantai mana barangnya datang.
  <b>Hanya faktur yang sudah divalidasi</b> yang dihitung sebagai hutang: faktur yang baru dititipkan belum
  tentu benar, dan memasukkannya ke neraca berarti mengakui hutang yang belum diperiksa.
</div>

@if ($belumDipetakan > 0)
  <div class="alert alert-warning">
    <b>Rp {{ number_format($belumDipetakan, 2, ',', '.') }} hutang belum terpetakan ke akun bagan.</b>
    Nilai itu tidak akan muncul di laporan akuntansi sampai fakturnya diberi akun hutang.
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label">Rantai pengadaan</label>
        <select name="sumber" class="form-select">
          <option value="">Semua rantai</option>
          @foreach ($daftarSumber as $k => $label)
            <option value="{{ $k }}" @selected($sumber === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Vendor</label><input name="vendor" class="form-control" value="{{ $vendor }}" placeholder="Semua vendor"></div>
      <div class="col-6 col-md-2"><label class="form-label">Bayar dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Umur Hutang</h3>
        <div class="card-subtitle">Yang belum jatuh tempo dipisahkan &mdash; itu bukan tunggakan</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kelompok</th><th class="text-end">Faktur</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($umur as $b)
              <tr>
                <td class="{{ $b->kelompok === 'lebih dari 90 hari' ? 'text-danger' : '' }}">{{ $b->kelompok }}</td>
                <td class="text-end">{{ $b->faktur }}</td>
                <td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada hutang terutang.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-3">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per Rantai</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Rantai</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($perSumber as $b)
              <tr><td>{{ $daftarSumber[$b->source_context] ?? $b->source_context }}</td><td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Ringkasan per Vendor</h3></div>
      <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Vendor</th><th class="text-end">Faktur</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($perVendor as $b)
              <tr><td>{{ $b->supplier_name }}</td><td class="text-end">{{ $b->faktur }}</td><td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@if ($menunggu->isNotEmpty())
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">Menunggu Validasi</h3>
      <div class="card-subtitle">Belum dihitung sebagai hutang. Nilainya dibekukan saat divalidasi &mdash; koreksi terakhir dilakukan di sini</div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Nomor</th><th>Vendor</th><th>Faktur</th><th>Jatuh tempo</th><th class="text-end">Nilai</th><th style="width:36%"></th></tr></thead>
        <tbody>
          @foreach ($menunggu as $b)
            <tr>
              <td class="font-monospace small">{{ $b->payable_number }}</td>
              <td>{{ $b->supplier_name }}</td>
              <td class="small">{{ $b->invoice_number }}</td>
              <td>{{ $b->due_date }}</td>
              <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
              <td>
                <div class="d-flex gap-1">
                  <form method="POST" action="{{ route('hutang.validasi', $b->id) }}" class="d-flex gap-1">
                    @csrf
                    <input name="amount" type="number" step="0.01" class="form-control form-control-sm" placeholder="Koreksi nilai">
                    <button class="btn btn-sm btn-success">Validasi</button>
                  </form>
                  <form method="POST" action="{{ route('hutang.tolak', $b->id) }}" class="d-flex gap-1">
                    @csrf
                    <input name="reason" class="form-control form-control-sm" placeholder="Alasan tolak" required>
                    <button class="btn btn-sm btn-outline-danger">Tolak</button>
                  </form>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Hutang Terutang</h3><div class="card-subtitle">Sisa selalu dihitung dari nilai dikurangi pembayaran, tidak pernah disimpan</div></div>
  <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Vendor</th><th>Rantai</th><th>Jatuh tempo</th><th class="text-end">Nilai</th><th class="text-end">Dibayar</th><th class="text-end">Sisa</th><th style="width:26%"></th></tr></thead>
      <tbody>
        @forelse ($terutang as $b)
          <tr>
            <td class="font-monospace small">{{ $b->payable_number }}</td>
            <td>{{ $b->supplier_name }}</td>
            <td class="small text-secondary">{{ $daftarSumber[$b->source_context] ?? $b->source_context }}</td>
            <td class="{{ $b->due_date < now()->toDateString() ? 'text-danger' : '' }}">{{ $b->due_date }}</td>
            <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
            <td class="text-end">{{ number_format($b->dibayar, 0, ',', '.') }}</td>
            <td class="text-end"><b>{{ number_format($b->sisa, 0, ',', '.') }}</b></td>
            <td>
              <form method="POST" action="{{ route('hutang.bayar', $b->id) }}" class="d-flex gap-1">
                @csrf
                <input name="paid_on" type="date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                <input name="amount" type="number" step="0.01" class="form-control form-control-sm" placeholder="Nilai" required>
                <button class="btn btn-sm btn-primary">Bayar</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-secondary py-3">Tidak ada hutang terutang.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Titip Faktur Vendor</h3></div>
      <form method="POST" action="{{ route('hutang.simpan') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-12 col-md-5">
            <label class="form-label">Rantai pengadaan</label>
            <select name="source_context" class="form-select" required>
              @foreach ($daftarSumber as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
          </div>
          <div class="col-12 col-md-7"><label class="form-label">Vendor</label><input name="supplier_name" class="form-control" required></div>
          <div class="col-6 col-md-4"><label class="form-label">No. faktur</label><input name="invoice_number" class="form-control" required></div>
          <div class="col-6 col-md-4"><label class="form-label">Tanggal faktur</label><input name="invoice_date" type="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6 col-md-4"><label class="form-label">Jatuh tempo</label><input name="due_date" type="date" class="form-control" value="{{ now()->addDays(30)->toDateString() }}" required></div>
          <div class="col-6 col-md-4"><label class="form-label">Nilai (Rp)</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
          <div class="col-6 col-md-4"><label class="form-label">No. penerimaan</label><input name="receipt_number" class="form-control"></div>
          <div class="col-6 col-md-4"><label class="form-label">ID akun hutang</label><input name="account_id" type="number" class="form-control" placeholder="Opsional"></div>
        </div>
        <button class="btn btn-primary mt-3">Titipkan Faktur</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pembayaran {{ $dari }} &mdash; {{ $sampai }}</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Vendor</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($pembayaran as $b)
              <tr><td>{{ $b->paid_on }}</td><td>{{ $b->supplier_name }}</td><td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pembayaran pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
