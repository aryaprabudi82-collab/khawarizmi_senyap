@extends('layouts.app')

@section('title', 'Pusat Biaya & Pusat Pendapatan')
@section('breadcrumb', 'Master Keuangan — Modul A')
@section('heading', 'Pusat Biaya &amp; Pusat Pendapatan')

@section('actions')
  <a href="{{ route('master-keuangan.index') }}" class="btn btn-link">&larr; Master Keuangan</a>
@endsection

@section('content')

@include('keuangan_master::_pesan')

<form method="GET" class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2">
    <label class="form-label">Berlaku pada</label>
    <input type="date" name="tanggal" class="form-control" value="{{ $tanggal }}">
  </div>
  <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
  <div class="col-12 col-md-8 text-md-end text-secondary small">
    Klasifikasi diubah dengan <b>meng-expire yang lama</b>, bukan menimpanya &mdash; laporan
    tahun lalu harus tetap memakai klasifikasi yang berlaku waktu itu.
  </div>
</form>

@if ($belumTerklasifikasi->isNotEmpty())
  <div class="alert alert-warning">
    <h4 class="alert-title">{{ $belumTerklasifikasi->count() }} unit belum punya pusat biaya</h4>
    <div class="small">
      Unit tanpa pusat biaya berarti biayanya <b>tidak masuk perhitungan unit cost mana pun</b> —
      dan laporan margin per layanan jadi terlalu bagus tanpa ada yang terlihat salah.
    </div>
    <div class="mt-2">
      @foreach ($belumTerklasifikasi->take(12) as $u)
        <span class="badge bg-secondary-lt me-1">{{ $u->name }}</span>
      @endforeach
      @if ($belumTerklasifikasi->count() > 12)
        <span class="text-secondary small">… dan {{ $belumTerklasifikasi->count() - 12 }} lainnya</span>
      @endif
    </div>
  </div>
@endif

<div class="row row-cards mb-3">
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Pusat terdaftar</div>
      <div class="h2 mb-0">{{ $pusat->count() }}</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Yang dialokasikan</div>
      <div class="h2 mb-0">{{ $dialokasikan->count() }}</div>
      <div class="text-secondary small">biayanya dibagi ke pusat pendapatan</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Pusat pendapatan</div>
      <div class="h2 mb-0">{{ $pusat->where('jenis', 'revenue-center')->count() }}</div>
      <div class="text-secondary small">penerima alokasi</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Pusat program (PTN-BH)</div>
      <div class="h2 mb-0">{{ $pusat->where('jenis', 'program-center')->count() }}</div>
      <div class="text-secondary small">pendidikan &amp; penelitian</div>
    </div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Pusat berlaku pada {{ $tanggal }}</h3>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Cost driver</th><th>Program</th><th>Berlaku</th></tr>
      </thead>
      <tbody>
        @forelse ($pusat as $p)
          <tr>
            <td class="font-monospace small">{{ $p->code }}</td>
            <td>{{ $p->name }}</td>
            <td>
              @php
                $warna = match ($p->jenis) {
                  'revenue-center' => 'bg-green-lt',
                  'cost-center'    => 'bg-blue-lt',
                  'support-center' => 'bg-yellow-lt',
                  'program-center' => 'bg-purple-lt',
                  default          => 'bg-secondary-lt',
                };
              @endphp
              <span class="badge {{ $warna }}">{{ $p->jenis }}</span>
            </td>
            <td class="small">{{ $p->cost_driver ?? '—' }}</td>
            <td class="small">{{ $p->default_program ?? '—' }}</td>
            <td class="small">{{ $p->valid_from }} &rarr; {{ $p->valid_until ?? 'berjalan' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada pusat biaya terdaftar.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftarkan pusat baru</h3></div>
  <div class="card-body border-bottom">
    <div class="row g-2 small text-secondary">
      @foreach ($jenis as $kode => $penjelasan)
        <div class="col-12 col-md-6">
          <span class="badge bg-secondary-lt font-monospace">{{ $kode }}</span>
          {{ $penjelasan }}
        </div>
      @endforeach
    </div>
  </div>
  <form method="POST" action="{{ route('master-keuangan.pusat-biaya.simpan') }}">
    @csrf
    <div class="card-body">
      <div class="row g-3">
        <div class="col-6 col-md-2">
          <label class="form-label required">Kode</label>
          <input type="text" name="code" class="form-control" value="{{ old('code') }}" required>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label required">Nama</label>
          <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label required">Jenis</label>
          <select name="jenis" class="form-select" required>
            @foreach ($jenis as $kode => $penjelasan)
              <option value="{{ $kode }}" @selected(old('jenis') === $kode)>{{ $kode }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Cost driver</label>
          <select name="cost_driver" class="form-select">
            <option value="">— tidak ada —</option>
            @foreach (['luas-lantai','jumlah-pegawai','jumlah-kunjungan','jumlah-hari-rawat','jam-mesin','jumlah-porsi','berat-cucian','jumlah-permintaan','manual'] as $d)
              <option value="{{ $d }}" @selected(old('cost_driver') === $d)>{{ $d }}</option>
            @endforeach
          </select>
          <div class="form-hint">
            Wajib untuk pusat yang dialokasikan; <b>dilarang</b> untuk pusat pendapatan.
          </div>
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label">Program (PTN-BH)</label>
          <select name="default_program" class="form-select">
            <option value="">— tidak ada —</option>
            @foreach (['pelayanan','pendidikan','penelitian'] as $pr)
              <option value="{{ $pr }}" @selected(old('default_program') === $pr)>{{ $pr }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-5">
          <label class="form-label">Unit organisasi</label>
          <select name="unit_id" class="form-select">
            <option value="">— tidak berpadanan satu unit —</option>
            @foreach ($unit as $u)
              <option value="{{ $u->id }}" @selected(old('unit_id') == $u->id)>{{ $u->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label required">Berlaku dari</label>
          <input type="date" name="valid_from" class="form-control"
                 value="{{ old('valid_from', now()->toDateString()) }}" required>
        </div>
      </div>
    </div>
    <div class="card-footer text-end">
      <button class="btn btn-primary">Daftarkan pusat</button>
    </div>
  </form>
</div>

<div class="alert alert-info mt-3">
  <b>Mengapa pusat program dipisah.</b> Kalau biaya pendidikan dan penelitian digabung ke pusat
  pendukung, ia akan tersebar ke tarif pelayanan lewat alokasi biasa &mdash; dan itu berarti
  <b>pasien ikut membiayai pendidikan</b> tanpa ada yang memutuskannya. RSP UI berstatus PTN-BH,
  sehingga dana pendidikan harus bisa dipertanggungjawabkan terpisah.
</div>

@endsection
