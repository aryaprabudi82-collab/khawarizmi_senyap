@extends('layouts.print')

@section('title', 'Barcode ' . $kunjungan->registration_number)

@section('content')

<h2 class="judul">Label Kunjungan</h2>

<table class="data">
  <tr><td class="label">Nama Pasien</td><td>: {{ $kunjungan->patient_name }}</td></tr>
  <tr><td class="label">No. RM</td><td>: {{ $kunjungan->patient_mrn }}</td></tr>
  <tr><td class="label">No. Registrasi</td><td>: {{ $kunjungan->registration_number }}</td></tr>
  <tr><td class="label">Unit</td><td>: {{ $kunjungan->unit_name }}</td></tr>
  <tr><td class="label">Jenis Rawat</td><td>: {{ strtoupper($kunjungan->care_type) }}</td></tr>
  <tr><td class="label">Tanggal</td><td>: {{ $kunjungan->service_date->format('d F Y') }}</td></tr>
</table>

<div style="text-align:center; margin-top:24px;">
  <svg id="barcode"></svg>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
  JsBarcode('#barcode', @json($kunjungan->registration_number), {
    format: 'CODE128',
    displayValue: true,
    fontSize: 16,
    height: 60,
  });
</script>

@endsection
