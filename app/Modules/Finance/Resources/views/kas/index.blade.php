@extends('layouts.app')

@section('title', 'Kas Harian')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Kas Harian')

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Nilai selalu dicatat positif; arahnya dibaca dari kategori.</b>
  Pemasukan dan pengeluaran adalah satu mekanisme yang sama dilihat dari arah berbeda, jadi menjumlahkan
  kolom nilai tanpa memisahkan arah akan menghasilkan angka yang bukan apa-apa. Ringkasan di bawah selalu
  memisahkannya.
</div>

@if ($belumDipetakan > 0)
  <div class="alert alert-warning">
    <b>Rp {{ number_format($belumDipetakan, 2, ',', '.') }} kas belum terpetakan ke bagan akun.</b>
    Nilai itu tidak akan muncul di laporan akuntansi sampai kategorinya diberi akun. Ditampilkan di sini
    supaya hilangnya ketahuan, bukan dibiarkan lenyap diam-diam.
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Kategori</label>
        <select name="kategori" class="form-select">
          <option value="">Semua kategori</option>
          @foreach ($kategori as $k)
            <option value="{{ $k->id }}" @selected($kategoriId === $k->id)>{{ $k->name }} ({{ $k->direction }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Arah (untuk rekap kategori)</label>
        <select name="arah" class="form-select">
          <option value="">Kedua arah</option>
          <option value="masuk" @selected($arah === 'masuk')>Pemasukan</option>
          <option value="keluar" @selected($arah === 'keluar')>Pengeluaran</option>
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  @foreach ([
    ['Kas Masuk', $ringkasan->masuk, 'text-success'],
    ['Kas Keluar', $ringkasan->keluar, 'text-danger'],
    ['Selisih', $ringkasan->selisih, $ringkasan->selisih < 0 ? 'text-danger' : ''],
    ['Transaksi', $ringkasan->transaksi, ''],
  ] as [$judul, $nilai, $warna])
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-secondary small">{{ $judul }}</div>
        <div class="h2 mb-0 {{ $warna }}">
          {{ $judul === 'Transaksi' ? $nilai : 'Rp ' . number_format($nilai, 0, ',', '.') }}
        </div>
      </div></div>
    </div>
  @endforeach
</div>

<div class="row">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Arus Kas Harian</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Selisih</th></tr></thead>
          <tbody>
            @forelse ($arusKas as $b)
              <tr>
                <td>{{ $b->transaction_date }}</td>
                <td class="text-end text-success">{{ number_format($b->masuk, 0, ',', '.') }}</td>
                <td class="text-end text-danger">{{ number_format($b->keluar, 0, ',', '.') }}</td>
                <td class="text-end {{ $b->selisih < 0 ? 'text-danger' : '' }}">{{ number_format($b->selisih, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada transaksi kas pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rekap per Kategori</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kategori</th><th>Arah</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($perKategori as $b)
              <tr>
                <td>{{ $b->name }}</td>
                <td class="small {{ $b->direction === 'masuk' ? 'text-success' : 'text-danger' }}">{{ $b->direction }}</td>
                <td class="text-end">{{ number_format($b->nilai, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada transaksi.</td></tr>
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
      <div class="card-header"><h3 class="card-title">Catat Transaksi Kas</h3></div>
      <form method="POST" action="{{ route('kas.simpan') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-12 col-md-6">
            <label class="form-label">Kategori</label>
            <select name="category_id" class="form-select" required>
              @foreach ($kategori->where('is_active', true) as $k)
                <option value="{{ $k->id }}">{{ $k->name }} &mdash; {{ $k->direction }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label">Tanggal</label><input name="transaction_date" type="date" class="form-control" value="{{ $sampai }}" required></div>
          <div class="col-6 col-md-3"><label class="form-label">Nilai (Rp)</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Uraian</label><input name="description" class="form-control" required></div>
          <div class="col-12 col-md-5"><label class="form-label">Dari / kepada</label><input name="counterparty" class="form-control"></div>
          <div class="col-6 col-md-3"><label class="form-label">Cara bayar</label><input name="payment_method" class="form-control" value="tunai"></div>
          <div class="col-6 col-md-4"><label class="form-label">No. bukti</label><input name="reference_number" class="form-control"></div>
        </div>
        <button class="btn btn-primary mt-3">Simpan Transaksi</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Tambah Kategori</h3><div class="card-subtitle">Kategori inilah yang dipetakan ke bagan akun</div></div>
      <form method="POST" action="{{ route('kas.kategori') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-6"><label class="form-label">Kode</label><input name="code" class="form-control" required></div>
          <div class="col-6">
            <label class="form-label">Arah</label>
            <select name="direction" class="form-select" required>
              <option value="masuk">Pemasukan</option>
              <option value="keluar">Pengeluaran</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
          <div class="col-12"><label class="form-label">ID Akun Bagan (opsional)</label><input name="account_id" type="number" class="form-control" placeholder="Kosongkan bila belum dipetakan"></div>
        </div>
        <button class="btn btn-primary mt-3">Tambah Kategori</button>
      </form>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Daftar Transaksi</h3></div>
  <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Tanggal</th><th>Uraian</th><th>Pihak</th><th>Arah</th><th class="text-end">Nilai</th><th></th></tr></thead>
      <tbody>
        @forelse ($transaksi as $b)
          <tr>
            <td class="font-monospace small">{{ $b->transaction_number }}</td>
            <td>{{ $b->transaction_date }}</td>
            <td>{{ $b->description }}</td>
            <td class="text-secondary small">{{ $b->counterparty ?: '—' }}</td>
            <td class="small {{ $b->direction === 'masuk' ? 'text-success' : 'text-danger' }}">{{ $b->direction }}</td>
            <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
            <td class="text-end">
              <form method="POST" action="{{ route('kas.batal', $b->id) }}" class="d-flex gap-1">
                @csrf
                <input name="reason" class="form-control form-control-sm" placeholder="Alasan batal" required>
                <button class="btn btn-sm btn-outline-danger">Batal</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada transaksi pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@if ($dibatalkan->isNotEmpty())
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Transaksi Dibatalkan</h3>
      <div class="card-subtitle">Tidak ikut dihitung, tapi tetap terlihat berikut alasannya</div>
    </div>
    <div class="table-responsive" style="max-height:240px; overflow-y:auto;">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Nomor</th><th>Tanggal</th><th>Uraian</th><th class="text-end">Nilai</th><th>Alasan</th></tr></thead>
        <tbody>
          @foreach ($dibatalkan as $b)
            <tr>
              <td class="font-monospace small">{{ $b->transaction_number }}</td>
              <td>{{ $b->transaction_date }}</td>
              <td>{{ $b->description }}</td>
              <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
              <td class="text-secondary small">{{ $b->cancellation_reason }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

@endsection
