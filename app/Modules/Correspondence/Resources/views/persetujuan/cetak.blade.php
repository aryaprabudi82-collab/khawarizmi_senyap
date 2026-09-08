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
    'pernyataan-pasien-umum' => 'Surat Pernyataan Pasien Umum',
    'memilih-dpjp' => 'Surat Pernyataan Memilih Dokter Penanggung Jawab Pelayanan',
  ][$persetujuan->consent_type];

  $labelHubungan = fn (?string $h) => $h ? ucwords(str_replace('-', ' ', $h)) : null;
@endphp

<h2 class="judul">{{ $judul }}</h2>
<p style="text-align:center">No. {{ $persetujuan->consent_number }}</p>

<table class="data">
  <tr><td class="label">Nama Pasien</td><td>: {{ $persetujuan->patient_name }}</td></tr>
  <tr><td class="label">Keputusan</td><td>: <strong>{{ strtoupper(str_replace('-', ' ', $persetujuan->decision)) }}</strong></td></tr>
  <tr><td class="label">Tanggal</td><td>: {{ $persetujuan->signed_at->format('d F Y, H:i') }} WIB</td></tr>
  @if ($persetujuan->template_name)
    <tr><td class="label">Formulir</td><td>: {{ $persetujuan->template_name }} (versi {{ $persetujuan->template_version }})</td></tr>
  @endif
  @if ($persetujuan->explained_by_name)
    <tr><td class="label">Pemberi Penjelasan</td><td>: {{ $persetujuan->explained_by_name }}</td></tr>
  @endif
</table>

@if ($persetujuan->signer_name)
  <p><strong>Yang bertanda tangan</strong></p>
  <table class="data">
    <tr><td class="label">Nama</td><td>: {{ $persetujuan->signer_name }}</td></tr>
    <tr><td class="label">Hubungan</td><td>: {{ $labelHubungan($persetujuan->signer_relationship) ?? '-' }}</td></tr>
    @if ($persetujuan->signer_id_number)
      <tr><td class="label">NIK/KTP</td><td>: {{ $persetujuan->signer_id_number }}</td></tr>
    @endif
    @if ($persetujuan->signer_address)
      <tr><td class="label">Alamat</td><td>: {{ $persetujuan->signer_address }}</td></tr>
    @endif
    @if ($persetujuan->delegation_reason)
      {{-- Dicetak, tidak disembunyikan: alasan perwakilan adalah bagian dari
           keabsahan persetujuan, bukan catatan internal. --}}
      <tr><td class="label">Alasan Diwakilkan</td><td>: {{ $persetujuan->delegation_reason }}</td></tr>
    @endif
  </table>
@endif

<div class="isi">
  @if ($persetujuan->decision === 'belum-dikonfirmasi')
    <p><em>Formulir ini belum memuat keputusan pasien. Dicetak untuk proses penjelasan,
      bukan sebagai bukti persetujuan.</em></p>
  @else
    <p>Saya yang bertanda tangan di bawah ini menyatakan
      <strong>{{ $persetujuan->decision === 'setuju' ? 'SETUJU' : 'MENOLAK' }}</strong>
      atas hal berikut:</p>
  @endif

  <p>{{ $persetujuan->procedure_description }}</p>

  @if ($persetujuan->items->isNotEmpty())
    {{--
      Butir penjelasan dicetak APA ADANYA, termasuk yang belum dijelaskan dan
      yang dinyatakan belum dipahami. Formulir yang cuma berbunyi "setelah
      mendapat penjelasan yang cukup" mengklaim sesuatu yang tidak diperiksa
      siapa pun — dan justru klaim itulah yang runtuh lebih dulu saat ada
      sengketa.
    --}}
    <p><strong>Penjelasan yang disampaikan</strong></p>
    <table class="data" style="width:100%">
      <thead>
        <tr><th style="text-align:left">Butir</th><th style="text-align:left">Isi</th><th style="text-align:left">Konfirmasi</th></tr>
      </thead>
      <tbody>
        @foreach ($persetujuan->items as $butir)
          <tr>
            <td>{{ $butir->label }}</td>
            <td>{{ $butir->body }}</td>
            <td>
              @if ($butir->confirmed === true)
                Dijelaskan &amp; dipahami
              @elseif ($butir->confirmed === false)
                Dijelaskan, belum dipahami{{ $butir->confirmation_note ? ' — '.$butir->confirmation_note : '' }}
              @else
                Belum dijelaskan
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if ($persetujuan->chosen_practitioner_name)
    {{--
      Dicetak sebagai PILIHAN, bukan penetapan. Penugasan DPJP adalah
      keputusan rumah sakit dan tercatat di modul rawat inap; keduanya bisa
      berbeda karena dokter yang diminta bisa saja tidak tersedia.
    --}}
    <p><strong>Dokter yang dipilih:</strong> {{ $persetujuan->chosen_practitioner_name }}
      <br><em>Pilihan ini akan diupayakan sepanjang dokter yang bersangkutan tersedia.</em></p>
  @endif

  @if ($persetujuan->refusal_risk_explained)
    <p><strong>Akibat penolakan yang dijelaskan:</strong> {{ $persetujuan->refusal_risk_explained }}</p>
  @endif

  @if ($persetujuan->consent_type === 'pemeriksaan-hiv')
    <p><em>Hasil pemeriksaan bersifat rahasia dan hanya dapat dibuka kepada pihak yang berwenang sesuai ketentuan kerahasiaan medis.</em></p>
  @endif
</div>

<div class="ttd">
  <div class="blok">
    <div>Pasien / Keluarga</div>
    <div class="garis">{{ $persetujuan->signer_name ?: $persetujuan->patient_name }}</div>
  </div>
  @if ($persetujuan->witness_name)
    <div class="blok">
      <div>Saksi</div>
      <div class="garis">{{ $persetujuan->witness_name }}</div>
    </div>
  @endif
  <div class="blok">
    <div>Petugas Kesehatan</div>
    <div class="garis">{{ $persetujuan->explained_by_name ?: ' ' }}</div>
  </div>
</div>

@endsection
