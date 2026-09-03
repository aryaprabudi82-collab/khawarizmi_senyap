@extends('layouts.print')

@section('title', 'Persetujuan ' . $persetujuan->consent_number)

@section('content')

@php
  $judul = [
    'tindakan' => 'Formulir Persetujuan Tindakan Kedokteran',
    'penolakan-anjuran-medis' => 'Surat Penolakan Anjuran Medis',
    'resusitasi' => 'Surat Penolakan Resusitasi (Do Not Resuscitate)',
    'umum' => 'Formulir Persetujuan Umum',
    'pemeriksaan-hiv' => 'Formulir Persetujuan Pemeriksaan HIV',
    'penundaan-pelayanan' => 'Formulir Persetujuan Penundaan Pelayanan',
    'rawat-inap' => 'Formulir Persetujuan Rawat Inap',
    'pulang-permintaan-sendiri' => 'Surat Pernyataan Pulang Atas Permintaan Sendiri',
  ][$persetujuan->consent_type];
@endphp

<h2 class="judul">{{ $judul }}</h2>
<p style="text-align:center">No. {{ $persetujuan->consent_number }}</p>

<table class="data">
  <tr><td class="label">Nama Pasien</td><td>: {{ $persetujuan->patient_name }}</td></tr>
  <tr><td class="label">Keputusan</td><td>: <strong>{{ strtoupper($persetujuan->decision) }}</strong></td></tr>
  <tr><td class="label">Tanggal</td><td>: {{ $persetujuan->signed_at->format('d F Y, H:i') }} WIB</td></tr>
</table>

<div class="isi">
  <p>Saya yang bertanda tangan di bawah ini, {{ $persetujuan->decision === 'setuju' ? 'menyatakan SETUJU' : 'menyatakan MENOLAK' }} atas hal berikut, setelah mendapat penjelasan yang cukup dari petugas kesehatan:</p>
  <p>{{ $persetujuan->procedure_description }}</p>
  @if ($persetujuan->consent_type === 'pemeriksaan-hiv')
    <p><em>Hasil pemeriksaan bersifat rahasia dan hanya dapat dibuka kepada pihak yang berwenang sesuai ketentuan kerahasiaan medis.</em></p>
  @endif
</div>

<div class="ttd">
  <div class="blok">
    <div>Pasien / Keluarga</div>
    <div class="garis">{{ $persetujuan->patient_name }}</div>
  </div>
  @if ($persetujuan->witness_name)
    <div class="blok">
      <div>Saksi</div>
      <div class="garis">{{ $persetujuan->witness_name }}</div>
    </div>
  @endif
  <div class="blok">
    <div>Petugas Kesehatan</div>
    <div class="garis">&nbsp;</div>
  </div>
</div>

@endsection
