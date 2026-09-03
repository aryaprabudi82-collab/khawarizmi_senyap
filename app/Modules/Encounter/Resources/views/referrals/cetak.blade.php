@extends('layouts.print')

@section('title', 'Rujukan ' . $rujukan->referral_number)

@section('content')

<h2 class="judul">Surat Rujukan</h2>
<p style="text-align:center">No. {{ $rujukan->referral_number }}</p>

<table class="data">
  <tr><td class="label">Nama Pasien</td><td>: {{ $rujukan->patient_name }}</td></tr>
  <tr><td class="label">No. RM</td><td>: {{ $rujukan->patient_mrn }}</td></tr>
  <tr><td class="label">Dirujuk Ke</td><td>: {{ $rujukan->destination_facility_name }}{{ $rujukan->destination_facility_code ? ' (' . $rujukan->destination_facility_code . ')' : '' }}</td></tr>
  <tr><td class="label">Tanggal</td><td>: {{ $rujukan->referred_at->format('d F Y, H:i') }} WIB</td></tr>
  @if ($rujukan->diagnosis)
    <tr><td class="label">Diagnosis</td><td>: {{ $rujukan->diagnosis }}</td></tr>
  @endif
</table>

<div class="isi">
  <p>Dengan hormat,</p>
  <p>Mohon penanganan lebih lanjut untuk pasien tersebut di atas, dengan uraian sebagai berikut:</p>
  <p>{{ $rujukan->reason }}</p>
  <p>Demikian surat rujukan ini dibuat untuk dipergunakan sebagaimana mestinya. Atas kerja sama yang baik, kami ucapkan terima kasih.</p>
</div>

<div class="ttd">
  <div class="blok">&nbsp;</div>
  <div class="blok">
    <div>{{ now()->format('d F Y') }}</div>
    <div>{{ $rujukan->practitioner_name ?? 'Dokter Pemeriksa' }}</div>
    <div class="garis">&nbsp;</div>
  </div>
</div>

@endsection
