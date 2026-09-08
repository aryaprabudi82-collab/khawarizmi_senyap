@extends('layouts.print')

@section('title', 'Surat ' . $surat->certificate_number)

@section('content')

{{--
  Judul TIDAK lagi dipetakan dari jenis surat di sini. Ia diturunkan dari
  TEMUAN pemeriksaannya (MedicalCertificate::judul()): surat bertipe
  bebas_tato yang temuannya "bertato" dicetak sebagai "Surat Keterangan
  Hasil Pemeriksaan Tato", tidak pernah berbunyi "bebas". Kalau judulnya
  ikut jenis, satu-satunya cara menerbitkan hasil yang tidak diinginkan
  adalah tidak menerbitkannya sama sekali.
--}}
<h2 class="judul">{{ $surat->judul() }}</h2>
<p style="text-align:center">No. {{ $surat->certificate_number }}</p>

<div class="isi">
  <p>Yang bertanda tangan di bawah ini menerangkan bahwa:</p>
</div>

<table class="data">
  <tr><td class="label">Nama</td><td>: {{ $surat->patient_name }}</td></tr>
  <tr><td class="label">Keperluan</td><td>: {{ $surat->purpose }}</td></tr>
  <tr>
    <td class="label">{{ $surat->certificate_type === 'rawat_inap' ? 'Dirawat' : 'Berlaku' }}</td>
    <td>: {{ $surat->valid_from->format('d F Y') }}@if ($surat->valid_until) s.d. {{ $surat->valid_until->format('d F Y') }}@elseif ($surat->certificate_type === 'rawat_inap') s.d. saat ini (masih dalam perawatan)@endif</td>
  </tr>
  @if ($surat->diagnosis)
    <tr><td class="label">Diagnosis</td><td>: {{ $surat->diagnosis }}</td></tr>
  @endif
</table>

@if ($surat->certificate_type === 'sakit_pihak_kedua')
  {{--
    Suratnya diserahkan kepada atasan ORANG LAIN, jadi yang dicetak adalah
    identitas orang itu — dan diagnosis pasien tidak ikut ke sini, dijaga
    CHECK di basis data, bukan cuma oleh kehati-hatian pengisi.
  --}}
  <p><strong>Diperlukan oleh</strong></p>
  <table class="data">
    <tr><td class="label">Nama</td><td>: {{ $surat->third_party_name }}</td></tr>
    <tr><td class="label">Hubungan</td><td>: {{ ucwords(str_replace('-', ' ', (string) $surat->third_party_relationship)) }}</td></tr>
    @if ($surat->third_party_occupation)
      <tr><td class="label">Pekerjaan</td><td>: {{ $surat->third_party_occupation }}</td></tr>
    @endif
    @if ($surat->third_party_institution)
      <tr><td class="label">Instansi</td><td>: {{ $surat->third_party_institution }}</td></tr>
    @endif
    @if ($surat->third_party_address)
      <tr><td class="label">Alamat</td><td>: {{ $surat->third_party_address }}</td></tr>
    @endif
  </table>
@endif

@if ($surat->memeriksaSesuatu())
  <p><strong>Hasil pemeriksaan</strong></p>
  <table class="data">
    <tr><td class="label">Temuan</td><td>: {{ $surat->examination_result }}</td></tr>
    <tr><td class="label">Kesimpulan</td><td>: <strong>{{ $surat->is_clear ? 'Sesuai keterangan di atas' : 'TIDAK sesuai keterangan yang dimintakan' }}</strong></td></tr>
  </table>
@endif

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
