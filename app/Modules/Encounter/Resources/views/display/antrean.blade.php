@extends('layouts.display')

@section('title', 'Antrean Poliklinik')
@section('heading', 'Antrean Pendaftaran &amp; Poliklinik')

@php use App\Modules\Encounter\Http\Controllers\QueueDisplayController as Layar; @endphp

@section('content')

<div class="kolom">
  @forelse ($antrean as $namaUnit => $baris)
    <div class="kartu">
      <h2>{{ $namaUnit ?: 'Tanpa Unit' }}</h2>
      <ul>
        @foreach ($baris->take(12) as $r)
          <li>
            <span class="nomor">{{ str_pad((string) $r->queue_number, 3, '0', STR_PAD_LEFT) }}</span>
            <span class="nama">{{ Layar::samarkan($r->patient_name) }}</span>
          </li>
        @endforeach
      </ul>
      @if ($baris->count() > 12)
        <div class="kosong">&hellip; dan {{ $baris->count() - 12 }} lainnya</div>
      @endif
    </div>
  @empty
    <div class="kartu">
      <h2>Hari Ini</h2>
      <div class="kosong">Belum ada antrean hari ini.</div>
    </div>
  @endforelse
</div>

@endsection
