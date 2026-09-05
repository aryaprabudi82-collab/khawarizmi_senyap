@extends('layouts.app')

@section('title', 'Indikator Mutu & Lama Pelayanan')
@section('breadcrumb', 'Konteks reporting')
@section('heading', 'Indikator Mutu & Lama Pelayanan')

@section('actions')
  <a href="{{ route('reporting.rl') }}" class="btn btn-link">&larr; Laporan RL</a>
@endsection

@section('content')

<div class="alert alert-warning">
  <b>Baris yang tahapannya belum tercatat lengkap tidak ikut dihitung rata-rata</b> &mdash; bukan dihitung sebagai nol.
  Nol berarti &quot;dilayani seketika&quot; dan akan membuat indikator mutu terlihat jauh lebih baik daripada kenyataannya.
  Kolom <b>Belum lengkap</b> pada tiap tabel menunjukkan berapa banyak yang dikecualikan; kalau angkanya besar,
  yang bermasalah pencatatannya, bukan pelayanannya.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Unit rawat jalan</label>
        <select name="unit" class="form-select">
          <option value="">Semua unit</option>
          @foreach ($daftarUnit as $u)
            <option value="{{ $u }}" @selected($unit === $u)>{{ $u }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Penunjang</label>
        <select name="kategori" class="form-select">
          @foreach (['lab' => 'Laboratorium PK', 'radiologi' => 'Radiologi', 'pa' => 'Patologi Anatomi'] as $k => $label)
            <option value="{{ $k }}" @selected($kategori === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

{{-- Efisiensi ranap: BOR, ALOS, BTO, TOI --}}
<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Efisiensi Rawat Inap (Barber-Johnson)</h3>
    <div class="card-subtitle">
      {{ $efisiensi->tempat_tidur }} tempat tidur &times; {{ $efisiensi->hari }} hari &mdash;
      hari-rawat dihitung dari penempatan bed yang sungguh terjadi, jadi pindah kamar tidak terhitung dua kali
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3">
      @foreach ([
        ['BOR', $efisiensi->bor, '%', 'Tempat tidur terisi', '60&ndash;85%'],
        ['ALOS', $efisiensi->alos, ' hari', 'Rata-rata lama dirawat', '6&ndash;9 hari'],
        ['BTO', $efisiensi->bto, ' kali', 'Pemakaian tiap tempat tidur', '40&ndash;50 kali/tahun'],
        ['TOI', $efisiensi->toi, ' hari', 'Kosong antar pasien', '1&ndash;3 hari'],
      ] as [$nama, $nilai, $satuan, $arti, $standar])
        <div class="col-6 col-lg-3">
          <div class="p-3 border rounded h-100">
            <div class="text-secondary text-uppercase small">{{ $nama }}</div>
            <div class="h1 mb-1">
              @if ($nilai === null)
                <span class="text-secondary">&mdash;</span>
              @else
                {{ $nilai }}<span class="h4 text-secondary">{{ $satuan }}</span>
              @endif
            </div>
            <div class="small">{{ $arti }}</div>
            <div class="small text-secondary">Standar Kemenkes {!! $standar !!}</div>
          </div>
        </div>
      @endforeach
    </div>
    @if ($efisiensi->bor === null || $efisiensi->alos === null)
      <div class="text-secondary small mt-3">
        Nilai bertanda &mdash; belum bisa dihitung: belum ada pasien yang pulang pada rentang ini,
        atau belum ada tempat tidur terdaftar. Ditampilkan kosong, bukan nol, karena nol berarti hal yang berbeda.
      </div>
    @endif
  </div>
</div>

{{-- SPM waktu tunggu --}}
<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">SPM Waktu Tunggu Rawat Jalan</h3>
    <div class="card-subtitle">Standar Kemenkes: &le; 60 menit sejak mendaftar sampai dipanggil</div>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="text-secondary small">Kepatuhan</div>
        <div class="h1 mb-0">
          @if ($spm->persen === null)
            <span class="text-secondary">&mdash;</span>
          @else
            {{ $spm->persen }}<span class="h4 text-secondary">%</span>
          @endif
        </div>
      </div>
      <div class="col-6 col-md-3"><div class="text-secondary small">Kunjungan</div><div class="h2 mb-0">{{ $spm->kunjungan }}</div></div>
      <div class="col-6 col-md-3"><div class="text-secondary small">Terhitung</div><div class="h2 mb-0">{{ $spm->terhitung }}</div></div>
      <div class="col-6 col-md-3">
        <div class="text-secondary small">Belum tercatat</div>
        <div class="h2 mb-0 {{ $spm->belum_tercatat > 0 ? 'text-danger' : '' }}">{{ $spm->belum_tercatat }}</div>
      </div>
    </div>
    @if ($spm->belum_tercatat > 0)
      <div class="alert alert-danger mt-3 mb-0">
        <b>{{ $spm->belum_tercatat }} kunjungan belum punya waktu panggil.</b>
        Angka kepatuhan di atas hanya berlaku bagi {{ $spm->terhitung }} kunjungan yang tercatat lengkap.
        Kepatuhan yang tinggi karena sebagian besar kunjungan tidak tercatat bukan kepatuhan.
      </div>
    @endif
  </div>
</div>

{{-- Tabel-tabel lama pelayanan --}}
@foreach ([
  ['Lama Pelayanan Rawat Jalan', $ralan, $unit ?: 'Semua unit'],
  ['Lama Pelayanan Apotek', $apotek, 'Satu baris per resep, bukan per obat'],
  ['Lama Pelayanan Penunjang', $penunjang, ['lab' => 'Laboratorium PK', 'radiologi' => 'Radiologi', 'pa' => 'Patologi Anatomi'][$kategori]],
  ['Lama Operasi', $operasi, 'Selisih insisi sampai selesai'],
  ['Lama Pelayanan CSSD', $cssd, 'Siklus set instrumen'],
] as [$judul, $baris, $subjudul])
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">{{ $judul }}</h3><div class="card-subtitle">{{ $subjudul }}</div></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th>Tahap</th>
            <th class="text-end">Rata-rata</th>
            <th class="text-end">Median</th>
            <th class="text-end">Terlama</th>
            <th class="text-end">Terhitung</th>
            <th class="text-end">Belum lengkap</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($baris as $b)
            <tr>
              <td>{{ $b->tahap }}</td>
              <td class="text-end">{{ $b->rata_menit === null ? '—' : $b->rata_menit . ' mnt' }}</td>
              <td class="text-end">{{ $b->median_menit === null ? '—' : $b->median_menit . ' mnt' }}</td>
              <td class="text-end">{{ $b->terlama_menit === null ? '—' : $b->terlama_menit . ' mnt' }}</td>
              <td class="text-end">{{ $b->terhitung }}</td>
              <td class="text-end {{ $b->belum_lengkap > 0 ? 'text-danger' : 'text-secondary' }}">{{ $b->belum_lengkap }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endforeach

{{-- Waktu tunggu per unit --}}
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Waktu Tunggu per Unit</h3><div class="card-subtitle">Untuk menemukan di mana antreannya menumpuk</div></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Unit</th><th class="text-end">Kunjungan</th><th class="text-end">Terhitung</th><th class="text-end">Rata-rata tunggu</th><th class="text-end">Lewat 60 menit</th></tr></thead>
      <tbody>
        @forelse ($perUnit as $b)
          <tr>
            <td>{{ $b->unit_name }}</td>
            <td class="text-end">{{ $b->kunjungan }}</td>
            <td class="text-end">{{ $b->terhitung }}</td>
            <td class="text-end">{{ $b->rata_tunggu_menit === null ? '—' : $b->rata_tunggu_menit . ' mnt' }}</td>
            <td class="text-end {{ $b->lewat_spm > 0 ? 'text-danger' : '' }}">{{ $b->lewat_spm }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada kunjungan pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Yang belum bisa dilaporkan di sini</h3></div>
  <div class="card-body">
    <p class="mb-2">Dua kode Khanza pada kelompok ini sengaja tidak dibuatkan angka, karena datanya memang belum ada:</p>
    <ul class="mb-0">
      <li><b>Lama Penyiapan RM</b> &mdash; mengukur lama menyiapkan berkas rekam medis kertas. Rekam medis di sini
          elektronik sejak awal mengikuti Permenkes 24/2022, jadi tidak ada berkas yang disiapkan dan tidak ada
          lamanya yang bisa diukur. Dibiarkan kosong alih-alih diisi angka yang tidak mengukur apa pun.</li>
      <li><b>Lama Pelayanan Lab MB</b> (mikrobiologi) &mdash; kategori permintaan penunjang yang ada baru
          laboratorium PK, radiologi, dan patologi anatomi. Mikrobiologi perlu ditambahkan lebih dulu sebagai
          kategori tersendiri sebelum lamanya bisa dihitung.</li>
    </ul>
  </div>
</div>

@endsection
