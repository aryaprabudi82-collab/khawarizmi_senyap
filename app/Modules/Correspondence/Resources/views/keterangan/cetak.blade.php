@extends('layouts.print')

@section('title', 'Surat ' . $surat->certificate_number)

@section('content')

@php
  $judul = [
    'sehat' => 'Surat Keterangan Sehat',
    'sakit' => 'Surat Keterangan Sakit',
    'berobat' => 'Surat Keterangan Berobat',
  ][$surat->certificate_type];
@endphp

<h2 class="judul">{{ $judul }}</h2>
<p style="text-align:center">No. {{ $surat->certificate_number }}</p>

<div class="isi">
  <p>Yang bertanda tangan di bawah ini menerangkan bahwa:</p>
</div>

<table class="data">
  <tr><td class="label">Nama</td><td>: {{ $surat->patient_name }}</td></tr>
  <tr><td class="label">Keperluan</td><td>: {{ $surat->purpose }}</td></tr>
  <tr><td class="label">Berlaku</td><td>: {{ $surat->valid_from->format('d F Y') }}{{ $surat->valid_until ? ' s.d. ' . $surat->valid_until->format('d F Y') : '' }}</td></tr>
</table>

<div class="isi">
  <p>{{ $surat->content }}</p>
  <p>Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
</div>

<div class="ttd">
  <div class="blok">&nbsp;</div>
  <div class="blok">
    <div>{{ now()->format('d F Y') }}</div>
    <div>Dokter Pemeriksa</div>
    <div class="garis">&nbsp;</div>
  </div>
</div>

@endsection
