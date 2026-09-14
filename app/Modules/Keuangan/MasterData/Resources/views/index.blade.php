@extends('layouts.app')

@section('title', 'Master Keuangan')
@section('breadcrumb', 'Domain keuangan — Modul A')
@section('heading', 'Master Keuangan')

@section('actions')
  <a href="{{ route('master-keuangan.item') }}" class="btn btn-primary">Charge Master &rarr;</a>
@endsection

@section('content')

@include('keuangan_master::_pesan')

{{--
  KESIAPAN DITARUH PALING ATAS, sebelum satu pun angka dibaca. Laporan
  yang tersaji rapi akan dipercaya apa adanya, dan cakupan yang tampak
  wajar padahal separuh item belum punya akun tidak memperlihatkan apa pun
  yang terlihat salah.
--}}
@php
  $menghalangi = collect($kesiapan)->where('status', 'menghalangi');
  $peringatan  = collect($kesiapan)->where('status', 'peringatan');
@endphp

@if ($menghalangi->isNotEmpty())
  <div class="alert alert-danger">
    <h4 class="alert-title">Belum bisa dipakai menagih</h4>
    <ul class="mb-0 mt-2">
      @foreach ($menghalangi as $k)
        <li><b>{{ $k['judul'] }}</b> &mdash; {{ $k['akibat'] }}</li>
      @endforeach
    </ul>
  </div>
@endif

@if ($peringatan->isNotEmpty())
  <div class="alert alert-warning">
    <ul class="mb-0">
      @foreach ($peringatan as $k)
        <li><b>{{ $k['judul'] }}</b> &mdash; {{ $k['akibat'] }}</li>
      @endforeach
    </ul>
  </div>
@endif

<div class="row row-cards mb-3">
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Item CDM berjalan</div>
      <div class="h2 mb-0">{{ $ringkasan['total'] }}</div>
      <div class="text-secondary small">yang punya kode global</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Belum dipetakan akun</div>
      <div class="h2 mb-0 {{ $ringkasan['belum_dipetakan'] > 0 ? 'text-warning' : '' }}">
        {{ $ringkasan['belum_dipetakan'] }}
      </div>
      <div class="text-secondary small">tidak bisa diaktifkan</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Aktif &mdash; boleh ditagihkan</div>
      <div class="h2 mb-0 {{ $ringkasan['aktif'] === 0 ? 'text-danger' : 'text-success' }}">
        {{ $ringkasan['aktif'] }}
      </div>
      <div class="text-secondary small">dan akan masuk buku besar</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Cakupan pemetaan</div>
      <div class="h2 mb-0">
        {{-- Katalog kosong = TIDAK ADA cakupan, bukan 100%. --}}
        {{ $cakupanPemetaan['persen'] === null ? '—' : $cakupanPemetaan['persen'] . '%' }}
      </div>
      <div class="text-secondary small">
        {{ $cakupanPemetaan['persen'] === null ? 'belum ada item sama sekali' : $cakupanPemetaan['terpetakan'] . ' dari ' . $cakupanPemetaan['total'] }}
      </div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  {{-- Cakupan penautan per konteks sumber --}}
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Penautan tarif per konteks sumber</h3>
      </div>
      <div class="card-body border-bottom text-secondary small">
        CDM <b>menunjuk</b> ke tarifnya, tidak menyimpannya. Tarif tindakan tetap di catalog,
        harga obat tetap di pharmacy, tarif kamar tetap di rawat inap. Yang dibawa CDM adalah
        kode globalnya dan <b>pemetaannya ke akun</b> &mdash; bagian yang membuat pendapatan
        bisa dijurnalkan otomatis.
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Konteks</th><th class="text-end">Tertaut</th><th class="text-end">Belum</th><th></th></tr></thead>
          <tbody>
            @forelse ($cakupan as $konteks => $angka)
              <tr>
                <td class="font-monospace">{{ $konteks }}</td>
                <td class="text-end">{{ $angka['tertaut'] }}</td>
                <td class="text-end {{ $angka['belum'] > 0 ? 'text-warning fw-bold' : '' }}">
                  {{ $angka['belum'] }}
                </td>
                <td class="w-1">
                  @if ($angka['belum'] > 0)
                    <form method="POST" action="{{ route('master-keuangan.item.tautkan') }}">
                      @csrf
                      <input type="hidden" name="konteks" value="{{ $konteks }}">
                      <button class="btn btn-sm btn-outline-primary">Tautkan</button>
                    </form>
                  @else
                    <span class="badge bg-green-lt">Lengkap</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada konteks sumber terdaftar.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('master-keuangan.item.tautkan') }}">
          @csrf
          <button class="btn btn-primary">Tautkan seluruh konteks</button>
          <span class="text-secondary small ms-2">
            Item hasil penautan lahir <b>nonaktif</b> &mdash; menautkan memberi kode,
            mengaktifkan menuntut pemetaan akun.
          </span>
        </form>
      </div>
    </div>
  </div>

  {{-- Yang belum tertaut, rinciannya --}}
  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Yang bisa ditagih tapi belum berkode</h3></div>
      <div class="card-body border-bottom text-secondary small">
        Selama belum tertaut, ia tidak punya akun pendapatan &mdash; dan tagihan yang memuatnya
        <b>tidak akan masuk buku besar</b>.
      </div>
      <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
        <table class="table table-vcenter card-table">
          <tbody>
            @forelse ($belumTertaut as $konteks => $baris)
              <tr class="bg-light">
                <td colspan="2" class="fw-bold font-monospace small">{{ $konteks }} &mdash; {{ $baris->count() }}</td>
              </tr>
              @foreach ($baris->take(10) as $b)
                <tr>
                  <td class="font-monospace small">{{ $b['code'] }}</td>
                  <td>
                    {{ $b['name'] }}
                    <span class="badge bg-secondary-lt ms-1">{{ $b['golongan'] }}</span>
                  </td>
                </tr>
              @endforeach
              @if ($baris->count() > 10)
                <tr><td colspan="2" class="text-secondary small">… dan {{ $baris->count() - 10 }} lainnya</td></tr>
              @endif
            @empty
              <tr><td class="text-center text-secondary py-3">Seluruh sumber tarif sudah tertaut.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12 col-md-4">
    <div class="card"><div class="card-body">
      <h3 class="card-title">Charge Description Master</h3>
      <p class="text-secondary">Kode global tiap hal yang bisa ditagihkan, berikut pemetaannya ke akun COA.</p>
      <a href="{{ route('master-keuangan.item') }}" class="btn btn-outline-primary w-100">Buka</a>
    </div></div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card"><div class="card-body">
      <h3 class="card-title">Kontrak penjamin</h3>
      <p class="text-secondary">Plafon, cost-sharing, dan tenggat &mdash; <b>berperiode</b>, sehingga tagihan Maret dinilai dengan kontrak yang berlaku Maret.</p>
      <a href="{{ route('master-keuangan.kontrak') }}" class="btn btn-outline-primary w-100">Buka</a>
    </div></div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card"><div class="card-body">
      <h3 class="card-title">Pusat biaya &amp; pendapatan</h3>
      <p class="text-secondary">Empat jenis, menentukan arah alokasi biaya. Pusat program dipisah untuk PTN-BH.</p>
      <a href="{{ route('master-keuangan.pusat-biaya') }}" class="btn btn-outline-primary w-100">Buka</a>
    </div></div>
  </div>
</div>

@endsection
