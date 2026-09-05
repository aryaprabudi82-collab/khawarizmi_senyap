@extends('layouts.app')

@section('title', 'Rekap Jasa Medis')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Rekap Jasa Medis')

@section('actions')
  <a href="{{ route('tagihan.index') }}" class="btn btn-link">&larr; Kasir</a>
@endsection

@section('content')

<div class="alert alert-info">
  Angka di sini <b>dibekukan saat tindakan dilakukan</b>, bukan dihitung ulang dari tarif hari ini &mdash;
  jadi rekap bulan lalu tidak berubah kalau tarif naik bulan ini.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" action="{{ route('jasa-medis.index') }}" class="row g-2">
      <div class="col-6 col-md-3">
        <label class="form-label">Komponen</label>
        <select name="komponen" class="form-select">
          @foreach ($daftarKomponen as $kode => $label)
            <option value="{{ $kode }}" @selected($komponen === $kode)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Tahun (bulanan)</label><input type="number" name="tahun" class="form-control" value="{{ $tahun }}" min="2000" max="2100"></div>
      <div class="col-12 col-md-3 d-flex align-items-end"><button class="btn btn-primary">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ringkasan seluruh komponen &mdash; {{ $dari }} s.d. {{ $sampai }}</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Komponen</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @foreach ($ringkasan as $baris)
          <tr class="{{ $baris['label'] === 'Total tindakan' ? 'fw-bold' : '' }}">
            <td>{{ $baris['label'] }}</td>
            <td class="text-end">Rp {{ number_format($baris['jumlah'], 0, ',', '.') }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ $daftarKomponen[$komponen] }} per pelaksana</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pelaksana</th><th class="text-end">Tindakan</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($perPelaksana as $baris)
              <tr>
                <td>{{ $baris->practitioner_name }}</td>
                <td class="text-end">{{ $baris->jumlah_tindakan }}</td>
                <td class="text-end">Rp {{ number_format((float) $baris->jumlah, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada tindakan dengan komponen ini pada rentang tersebut.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ $daftarKomponen[$komponen] }} per hari</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th class="text-end">Tindakan</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($harian as $baris)
              <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($baris->tanggal)->format('d-m-Y') }}</td>
                <td class="text-end">{{ $baris->jumlah_tindakan }}</td>
                <td class="text-end">Rp {{ number_format((float) $baris->jumlah, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">{{ $daftarKomponen[$komponen] }} per bulan &mdash; {{ $tahun }}</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Bulan</th><th class="text-end">Tindakan</th><th class="text-end">Jumlah</th></tr></thead>
      <tbody>
        @forelse ($bulanan as $baris)
          <tr>
            <td>{{ \Illuminate\Support\Carbon::create()->month((int) $baris->bulan)->translatedFormat('F') }}</td>
            <td class="text-end">{{ $baris->jumlah_tindakan }}</td>
            <td class="text-end">Rp {{ number_format((float) $baris->jumlah, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada data pada tahun ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
