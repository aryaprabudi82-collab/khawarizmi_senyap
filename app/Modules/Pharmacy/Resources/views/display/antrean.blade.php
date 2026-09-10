@extends('layouts.display')

@section('title', 'Antrean Apotek')
@section('heading', 'Antrean Apotek')

@php use App\Modules\Pharmacy\Http\Controllers\PharmacyQueueDisplayController as Layar; @endphp

@section('content')

<div class="kolom">
  {{-- Tiga kelompok, bukan satu daftar berurut nomor. Yang menunggu di
       apotek menanyakan satu hal — apakah obat saya sudah bisa diambil —
       dan daftar berurut nomor tidak menjawab itu. --}}

  <div class="kartu">
    <h2>Sedang Disiapkan ({{ $disiapkan->count() }})</h2>
    @if ($disiapkan->isEmpty())
      <div class="kosong">Tidak ada resep yang sedang disiapkan.</div>
    @else
      <ul>
        @foreach ($disiapkan->take(12) as $r)
          <li>
            <span class="nomor">{{ substr($r->prescription_number, -4) }}</span>
            <span class="nama">{{ Layar::samarkan($r->patient_name) }}</span>
          </li>
        @endforeach
      </ul>
      @if ($disiapkan->count() > 12)
        <div class="kosong">&hellip; dan {{ $disiapkan->count() - 12 }} lainnya</div>
      @endif
    @endif
  </div>

  <div class="kartu siap">
    <h2>Siap Diambil ({{ $siap->count() }})</h2>
    @if ($siap->isEmpty())
      <div class="kosong">Belum ada yang siap diambil.</div>
    @else
      <ul>
        @foreach ($siap->take(12) as $r)
          <li>
            <span class="nomor">{{ substr($r->prescription_number, -4) }}</span>
            <span class="nama">{{ Layar::samarkan($r->patient_name) }}</span>
          </li>
        @endforeach
      </ul>
      @if ($siap->count() > 12)
        <div class="kosong">&hellip; dan {{ $siap->count() - 12 }} lainnya</div>
      @endif
    @endif
  </div>

  <div class="kartu">
    <h2>Sudah Diserahkan</h2>
    @if ($diserahkan->isEmpty())
      <div class="kosong">Belum ada penyerahan hari ini.</div>
    @else
      <ul>
        @foreach ($diserahkan as $r)
          <li>
            <span class="nomor">{{ substr($r->prescription_number, -4) }}</span>
            <span class="nama">{{ Layar::samarkan($r->patient_name) }}</span>
          </li>
        @endforeach
      </ul>
      <div class="kosong">Sepuluh terakhir saja &mdash; daftar penuh sepanjang hari akan mendorong yang siap diambil keluar layar.</div>
    @endif
  </div>
</div>

@endsection
