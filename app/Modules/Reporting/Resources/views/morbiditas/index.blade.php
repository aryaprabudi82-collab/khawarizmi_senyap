@extends('layouts.app')

@section('title', 'Morbiditas & Surveilans')
@section('breadcrumb', 'Konteks reporting')
@section('heading', 'Morbiditas &amp; Surveilans Penyakit')

@section('actions')
  <a href="{{ route('reporting.sensus') }}" class="btn btn-link">&larr; Sensus &amp; Kunjungan</a>
@endsection

@section('content')

<div class="alert alert-info">
  Diagnosis yang kodenya <b>belum ada di kamus ICD-10</b> tetap dihitung, ditandai penularan
  <span class="badge bg-secondary-lt">tidak-diketahui</span> &mdash; bukan disembunyikan. Laporan yang tampak rapi
  padahal ada kasus yang hilang lebih menyesatkan daripada laporan yang jujur menunjukkan apa yang belum lengkap.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis Rawat</label>
        <select name="jenis_rawat" class="form-select">
          <option value="">Semua</option>
          <option value="ralan" @selected($jenisRawat === 'ralan')>Rawat Jalan</option>
          <option value="ranap" @selected($jenisRawat === 'ranap')>Rawat Inap</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Rincian Penularan</label>
        <select name="penularan" class="form-select">
          <option value="menular" @selected($penularan === 'menular')>Menular</option>
          <option value="tidak-menular" @selected($penularan === 'tidak-menular')>Tidak menular</option>
          <option value="tidak-diketahui" @selected($penularan === 'tidak-diketahui')>Tidak diketahui</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Program Surveilans</label>
        <select name="kelompok" class="form-select">
          @forelse ($kelompok as $k)
            <option value="{{ $k->group }}" @selected($kelompokDipilih === $k->group)>{{ strtoupper($k->group) }} ({{ $k->jumlah_kode }} kode)</option>
          @empty
            <option value="">— belum ada program terdaftar —</option>
          @endforelse
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Ringkasan penularan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sifat</th><th class="text-end">Kasus</th><th class="text-end">Pasien</th></tr></thead>
          <tbody>
            @forelse ($perPenularan as $b)
              <tr>
                <td>
                  <span class="badge bg-{{ $b->transmission === 'menular' ? 'red' : ($b->transmission === 'tidak-menular' ? 'blue' : 'secondary') }}-lt">
                    {{ $b->transmission }}
                  </span>
                </td>
                <td class="text-end">{{ $b->jumlah }}</td>
                <td class="text-end">{{ $b->pasien }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada diagnosis pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rincian penyakit {{ $penularan }}</h3></div>
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Penyakit</th><th class="text-end">Kasus</th><th class="text-end">Pasien</th></tr></thead>
          <tbody>
            @forelse ($rincianPenularan as $b)
              <tr>
                <td class="font-monospace small">{{ $b->code }}</td>
                <td>{{ $b->display }}</td>
                <td class="text-end">{{ $b->jumlah }}</td>
                <td class="text-end">{{ $b->pasien }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada penyakit {{ $penularan }} pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Frekuensi penyakit terbanyak</h3><div class="card-subtitle">50 teratas</div></div>
  <div class="table-responsive" style="max-height:400px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Penyakit</th><th>Penularan</th><th class="text-end">Kasus</th><th class="text-end">Pasien</th></tr></thead>
      <tbody>
        @forelse ($frekuensi as $b)
          <tr>
            <td class="font-monospace small">{{ $b->code }}</td>
            <td>{{ $b->display }}</td>
            <td>
              <span class="badge bg-{{ $b->transmission === 'menular' ? 'red' : ($b->transmission === 'tidak-menular' ? 'blue' : 'secondary') }}-lt">
                {{ $b->transmission }}
              </span>
            </td>
            <td class="text-end">{{ $b->jumlah }}</td>
            <td class="text-end">{{ $b->pasien }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada diagnosis pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Surveilans {{ $kelompokDipilih ? strtoupper($kelompokDipilih) : '' }}</h3>
        <div class="card-subtitle">Keanggotaan program ditetapkan pada kamus penyakit</div>
      </div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Penyakit</th><th class="text-end">Kasus</th></tr></thead>
          <tbody>
            @forelse ($perSurveilans as $b)
              <tr><td class="font-monospace small">{{ $b->code }}</td><td>{{ $b->display }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
            @empty
              <tr>
                <td colspan="3" class="text-center text-secondary py-3">
                  @if ($kelompok->isEmpty())
                    Belum ada penyakit yang didaftarkan ke program surveilans mana pun.
                  @else
                    Tidak ada kasus program ini pada rentang tersebut.
                  @endif
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Morbiditas per cara bayar</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Penjamin</th><th>Penularan</th><th class="text-end">Kasus</th></tr></thead>
          <tbody>
            @forelse ($perPenjamin as $b)
              <tr><td>{{ $b->payer_name }}</td><td class="text-secondary small">{{ $b->transmission }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
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
  <div class="card-header"><h3 class="card-title">Obat pada satu penyakit</h3></div>
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="dari" value="{{ $dari }}">
      <input type="hidden" name="sampai" value="{{ $sampai }}">
      <input type="hidden" name="jenis_rawat" value="{{ $jenisRawat }}">
      <div class="col-12 col-md-4">
        <label class="form-label">Kode Penyakit</label>
        <input type="text" name="kode" class="form-control" value="{{ $kodeObat }}" placeholder="mis. J06.9">
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-outline-primary w-100">Lihat Obat</button></div>
    </form>
  </div>
  @if ($kodeObat)
    <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Obat</th><th class="text-end">Kali diserahkan</th><th class="text-end">Jumlah unit</th></tr></thead>
        <tbody>
          @forelse ($obat as $b)
            <tr><td>{{ $b->drug_name }}</td><td class="text-end">{{ $b->jumlah }}</td><td class="text-end">{{ rtrim(rtrim((string) $b->jumlah_unit, '0'), '.') }}</td></tr>
          @empty
            <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada obat diserahkan pada kunjungan dengan diagnosis {{ $kodeObat }}.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  @endif
</div>

@endsection
