@extends('layouts.app')

@section('title', 'Laporan RL Kemenkes')
@section('breadcrumb', 'Konteks reporting')
@section('heading', 'Laporan RL Kemenkes')

@section('actions')
  <a href="{{ route('reporting.morbiditas') }}" class="btn btn-link">&larr; Morbiditas</a>
@endsection

@section('content')

<div class="alert alert-warning">
  <b>Ini angka yang mendasari tiap RL, bukan formulir RL siap kirim.</b>
  Tata letak baris dan kolom resmi tiap formulir ditetapkan peraturan Kemenkes yang dapat berubah, dan sebagian
  rincian yang diminta formulir belum tercatat di sistem ini &mdash; lihat catatan pada kartu yang bersangkutan.
  Verifikasi terhadap peraturan yang berlaku wajib dilakukan sebelum dikirimkan.
</div>

@if (! $pakaiDtd)
  <div class="alert alert-danger">
    <b>RL 4A/4B belum memakai Daftar Tabulasi Dasar (DTD).</b>
    {{ $belumDtd }} kode diagnosis belum punya kelompok DTD, jadi pengelompokan sebab di bawah memakai
    <b>bab ICD-10</b> &mdash; bukan DTD yang diminta formulir. Daftar DTD resmi harus diimpor bersama kamus ICD-10.
    Angka ini ditampilkan apa adanya, bukan disamarkan seolah sudah sesuai DTD.
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Unit Gigi (RL 3.3)</label>
        <select name="unit_gigi" class="form-select">
          @foreach ($unit as $u)
            <option value="{{ $u->name }}" @selected($unitGigi === $u->name)>{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Unit Kebidanan (RL 3.4)</label>
        <select name="unit_obgyn" class="form-select">
          @foreach ($unit as $u)
            <option value="{{ $u->name }}" @selected($unitObgyn === $u->name)>{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">RL 1.3 &mdash; Ketersediaan Tempat Tidur</h3><div class="card-subtitle">Kamar nonaktif tidak dihitung sebagai kapasitas</div></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kelas</th><th class="text-end">Total</th><th class="text-end">Terisi</th><th class="text-end">Tersedia</th></tr></thead>
          <tbody>
            @forelse ($tempatTidur as $b)
              <tr>
                <td class="text-uppercase">{{ $b->room_class }}</td>
                <td class="text-end">{{ $b->total }}</td>
                <td class="text-end">{{ $b->terisi }}</td>
                <td class="text-end">{{ $b->tersedia }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada kamar terdaftar.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">RL 3.2 &mdash; Rawat Darurat</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tingkat Triase</th><th class="text-end">Kunjungan</th><th class="text-end">Pasien</th></tr></thead>
          <tbody>
            @forelse ($gawatDarurat as $b)
              <tr><td>{{ $b->triage_level }}</td><td class="text-end">{{ $b->jumlah }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada kunjungan gawat darurat pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  @foreach ([['RL 3.3 — Gigi dan Mulut', $gigi, $unitGigi, 'Formulir resmi merinci jenis tindakan gigi (tumpatan, pencabutan, dan seterusnya) — rincian itu belum dicatat.'], ['RL 3.4 — Kebidanan', $kebidanan, $unitObgyn, 'Formulir resmi merinci jenis persalinan berikut sebab kematian ibu/bayi — rincian itu belum dicatat.']] as [$judul, $baris, $namaUnit, $catatan])
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">{{ $judul }}</h3><div class="card-subtitle">{{ $namaUnit }} &mdash; {{ $catatan }}</div></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Jenis Rawat</th><th class="text-end">Kunjungan</th><th class="text-end">Pasien</th></tr></thead>
            <tbody>
              @forelse ($baris as $b)
                <tr><td>{{ $b->care_type }}</td><td class="text-end">{{ $b->kunjungan }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
              @empty
                <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada kegiatan pada rentang ini.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">RL 3.6 &mdash; Pembedahan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Jenis Anestesi</th><th>Kamar Operasi</th><th class="text-end">Tindakan</th><th class="text-end">Pasien</th></tr></thead>
      <tbody>
        @forelse ($pembedahan as $b)
          <tr><td>{{ $b->anesthesia_type }}</td><td>{{ $b->operating_room }}</td><td class="text-end">{{ $b->jumlah }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada pembedahan pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  @foreach ([['RL 3.7 — Radiologi', $radiologi], ['RL 3.8 — Laboratorium', $laboratorium]] as [$judul, $baris])
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">{{ $judul }}</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Jenis Rawat</th><th>Status</th><th class="text-end">Permintaan</th></tr></thead>
            <tbody>
              @forelse ($baris as $b)
                <tr><td>{{ $b->care_type }}</td><td class="text-secondary small">{{ $b->status }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
              @empty
                <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada permintaan pada rentang ini.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

<div class="row">
  @foreach ([['RL 4A Sebab — Morbiditas Rawat Inap', $sebabRanap], ['RL 4B Sebab — Morbiditas Rawat Jalan', $sebabRalan]] as [$judul, $baris])
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header">
          <h3 class="card-title">{{ $judul }}</h3>
          <div class="card-subtitle">Dikelompokkan menurut {{ $pakaiDtd ? 'DTD Kemenkes' : 'bab ICD-10 (DTD belum diimpor)' }}</div>
        </div>
        <div class="table-responsive" style="max-height:280px; overflow-y:auto;">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Kelompok</th><th class="text-end">Kasus</th><th class="text-end">Pasien</th></tr></thead>
            <tbody>
              @forelse ($baris as $b)
                <tr><td>{{ $b->kelompok }}</td><td class="text-end">{{ $b->jumlah }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
              @empty
                <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada diagnosis pada rentang ini.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

@foreach ([['RL 4A — Morbiditas Rawat Inap', $morbiditasRanap], ['RL 4B — Morbiditas Rawat Jalan', $morbiditasRalan]] as [$judul, $baris])
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">{{ $judul }}</h3><div class="card-subtitle">Menurut golongan umur dan jenis kelamin</div></div>
    <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Kode</th><th>Penyakit</th><th>Golongan Umur</th><th>JK</th><th class="text-end">Kasus</th></tr></thead>
        <tbody>
          @forelse ($baris as $b)
            <tr>
              <td class="font-monospace small">{{ $b->code }}</td>
              <td>{{ $b->display }}</td>
              <td class="text-secondary small">{{ $b->golongan_umur }}</td>
              <td>{{ $b->sex }}</td>
              <td class="text-end">{{ $b->jumlah }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada diagnosis pada rentang ini.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endforeach

@endsection
