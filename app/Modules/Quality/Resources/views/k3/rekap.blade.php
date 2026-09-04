@extends('layouts.app')

@section('title', 'Mutu — Rekap K3 Tahunan')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'Rekap Insiden K3 Per Tahun ' . $tahun)

@section('actions')
  <a href="{{ route('quality.k3.index') }}" class="btn btn-link">&larr; Insiden K3</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label" for="tahun">Tahun</label>
        <input type="number" id="tahun" name="tahun" class="form-control" value="{{ $tahun }}" min="2000" max="{{ now()->year }}">
      </div>
      <div class="col-6 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>
</div>

<div class="row row-deck row-cards mb-3">
  <div class="col-6 col-md-2">
    <div class="card"><div class="card-body py-3">
      <div class="text-secondary small">Total Insiden</div>
      <div class="h1 mb-0">{{ $rekap['total'] }}</div>
    </div></div>
  </div>
  @foreach (['dilaporkan' => 'Dilaporkan', 'ditinjau' => 'Ditinjau', 'ditutup' => 'Ditutup'] as $kode => $label)
    <div class="col-6 col-md-2">
      <div class="card"><div class="card-body py-3">
        <div class="text-secondary small">{{ $label }}</div>
        <div class="h1 mb-0">{{ $rekap['per_status'][$kode] ?? 0 }}</div>
      </div></div>
    </div>
  @endforeach
</div>

<p class="text-secondary small mb-3">Dikelompokkan langsung dari isian bebas petugas saat lapor (bukan daftar baku Khanza) · kategori berikut hanya menampilkan yang benar-benar pernah dicatat.</p>

<div class="row g-3">
  @foreach ([
    'jenis_cidera' => 'Jenis Cidera',
    'dampak_cidera' => 'Dampak Cidera',
    'bagian_tubuh' => 'Bagian Tubuh',
    'jenis_pekerjaan' => 'Jenis Pekerjaan',
    'lokasi_kejadian' => 'Lokasi Kejadian',
    'penyebab' => 'Penyebab',
  ] as $kunci => $judul)
    <div class="col-12 col-lg-6">
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ $judul }}</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>{{ $judul }}</th><th class="text-center w-1">Jumlah</th></tr></thead>
            <tbody>
              @forelse ($rekap[$kunci] as $label => $jumlah)
                <tr><td>{{ $label ?: '—' }}</td><td class="text-center">{{ $jumlah }}</td></tr>
              @empty
                <tr><td colspan="2" class="text-center text-secondary py-3">Tidak ada data pada tahun ini.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

@endsection
