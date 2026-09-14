@extends('layouts.app')

@section('title', 'Kontrak Penjamin')
@section('breadcrumb', 'Master Keuangan — Modul A')
@section('heading', 'Kontrak Penjamin')

@section('actions')
  <a href="{{ route('master-keuangan.index') }}" class="btn btn-link">&larr; Master Keuangan</a>
@endsection

@section('content')

@include('keuangan_master::_pesan')

@php
  $rp = fn ($n) => $n === null ? '—' : 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp

<form method="GET" class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2">
    <label class="form-label">Berlaku pada</label>
    <input type="date" name="tanggal" class="form-control" value="{{ $tanggal }}">
  </div>
  <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
  <div class="col-12 col-md-8 text-md-end text-secondary small">
    <b>Berperiode, dan itu intinya.</b> Tagihan yang terbit Maret dinilai dengan kontrak yang
    berlaku Maret &mdash; bukan kontrak yang berlaku saat laporannya dibuka.
  </div>
</form>

@if ($akanBerakhir->isNotEmpty())
  <div class="alert alert-warning">
    <h4 class="alert-title">{{ $akanBerakhir->count() }} kontrak berakhir dalam 30 hari</h4>
    <ul class="mb-0 mt-2">
      @foreach ($akanBerakhir as $k)
        <li>{{ $k->contract_number }} &mdash; berakhir {{ $k->valid_until }}</li>
      @endforeach
    </ul>
    <div class="small mt-2">
      Kontrak yang lewat tanpa diperpanjang membuat tagihan berikutnya tidak punya dasar
      cost-sharing, dan bagian pasien terhitung <b>null</b> &mdash; bukan nol.
    </div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Kontrak berlaku pada {{ $tanggal }}</h3>
    <div class="card-actions text-secondary small">{{ $kontrak->count() }} kontrak</div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>Penjamin</th><th>Nomor</th><th>Berlaku</th>
          <th>Cost-sharing</th><th class="text-end">Plafon/episode</th>
          <th class="text-end">Plafon/tahun</th><th class="text-end">Tenggat ajuan</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($kontrak as $k)
          <tr>
            <td>
              {{ $k->payer_name }}
              <div class="text-secondary small font-monospace">{{ $k->payer_code }}</div>
            </td>
            <td class="font-monospace small">{{ $k->contract_number }}</td>
            <td class="small">
              {{ $k->valid_from }} &rarr; {{ $k->valid_until ?? 'berjalan' }}
            </td>
            <td>
              <span class="badge bg-blue-lt">{{ $k->cost_sharing_basis }}</span>
              @if ($k->cost_sharing_percent !== null)
                <div class="small">{{ (float) $k->cost_sharing_percent }}%</div>
              @endif
              @if ($k->cost_sharing_amount !== null)
                <div class="small">{{ $rp($k->cost_sharing_amount) }}</div>
              @endif
              @if ($k->cost_sharing_cap !== null)
                <div class="small text-secondary">maks {{ $rp($k->cost_sharing_cap) }}</div>
              @endif
            </td>
            <td class="text-end">{{ $rp($k->plafon_per_episode) }}</td>
            <td class="text-end">{{ $rp($k->plafon_per_tahun) }}</td>
            <td class="text-end">{{ $k->batas_hari_pengajuan ? $k->batas_hari_pengajuan . ' hari' : '—' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="text-center text-secondary py-4">
              Belum ada kontrak yang berlaku pada {{ $tanggal }}.
              Tanpa kontrak, bagian pasien terhitung <b>null</b> &mdash; bukan nol.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftarkan kontrak baru</h3></div>
  <div class="card-body border-bottom text-secondary small">
    Kontrak baru <b>otomatis menutup</b> kontrak berjalan sehari sebelum tanggal berlakunya.
    Dua kontrak berjalan bersamaan untuk satu penjamin akan membuat tagihan dinilai dengan
    kontrak yang kebetulan terbaca lebih dulu.
  </div>
  <form method="POST" action="{{ route('master-keuangan.kontrak.simpan') }}">
    @csrf
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label required">Penjamin</label>
          <select name="payer_id" class="form-select" required>
            <option value="">— pilih —</option>
            @foreach ($penjamin as $p)
              <option value="{{ $p->id }}" @selected(old('payer_id') == $p->id)>
                {{ $p->code }} — {{ $p->name }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label required">Nomor kontrak</label>
          <input type="text" name="contract_number" class="form-control" value="{{ old('contract_number') }}" required>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label required">Nama kontrak</label>
          <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label required">Berlaku dari</label>
          <input type="date" name="valid_from" class="form-control" value="{{ old('valid_from', now()->toDateString()) }}" required>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label required">Basis cost-sharing</label>
          <select name="cost_sharing_basis" class="form-select" required>
            <option value="tidak-ada" @selected(old('cost_sharing_basis') === 'tidak-ada')>tidak-ada</option>
            <option value="persentase" @selected(old('cost_sharing_basis') === 'persentase')>persentase</option>
            <option value="nominal" @selected(old('cost_sharing_basis') === 'nominal')>nominal</option>
            <option value="persentase-berbatas" @selected(old('cost_sharing_basis') === 'persentase-berbatas')>persentase-berbatas</option>
          </select>
          <div class="form-hint">
            Basis menentukan kolom mana yang <b>wajib</b> terisi.
          </div>
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label">Persentase</label>
          <input type="number" step="0.01" min="0" max="100" name="cost_sharing_percent"
                 class="form-control" value="{{ old('cost_sharing_percent') }}">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label">Nominal</label>
          <input type="number" step="1" min="0" name="cost_sharing_amount"
                 class="form-control" value="{{ old('cost_sharing_amount') }}">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label">Batas atas</label>
          <input type="number" step="1" min="0" name="cost_sharing_cap"
                 class="form-control" value="{{ old('cost_sharing_cap') }}">
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label">Plafon per episode</label>
          <input type="number" step="1" min="0" name="plafon_per_episode"
                 class="form-control" value="{{ old('plafon_per_episode') }}">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Plafon per tahun</label>
          <input type="number" step="1" min="0" name="plafon_per_tahun"
                 class="form-control" value="{{ old('plafon_per_tahun') }}">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Tenggat pengajuan (hari)</label>
          <input type="number" min="1" max="365" name="batas_hari_pengajuan"
                 class="form-control" value="{{ old('batas_hari_pengajuan') }}">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Tenggat pembayaran (hari)</label>
          <input type="number" min="1" max="365" name="batas_hari_pembayaran"
                 class="form-control" value="{{ old('batas_hari_pembayaran') }}">
        </div>
      </div>
    </div>
    <div class="card-footer text-end">
      <button class="btn btn-primary">Daftarkan kontrak</button>
    </div>
  </form>
</div>

<div class="alert alert-info mt-3">
  <b>Mengapa basis menentukan kolom wajib.</b> Kontrak berbasis persentase tanpa angka persennya
  akan menghitung bagian pasien sebagai <b>nol</b>: pasien tidak ditagih apa-apa, tidak ada galat,
  dan selisihnya baru ketahuan saat rekonsiliasi penjamin. Basis data menolaknya lewat CHECK.
</div>

@endsection
