@extends('layouts.print')

@section('title', 'Perkiraan Biaya Ranap ' . $estimasi->estimate_number)

@section('content')

<h2 class="judul">Perkiraan Biaya Rawat Inap</h2>
<p style="text-align:center">No. {{ $estimasi->estimate_number }}</p>

<table class="data">
  <tr><td class="label">Nama Pasien</td><td>: {{ $estimasi->patient_name }}</td></tr>
  <tr><td class="label">No. RM</td><td>: {{ $estimasi->patient_mrn }}</td></tr>
  <tr><td class="label">Penjamin</td><td>: {{ $estimasi->payer_name }}</td></tr>
  <tr><td class="label">Kelas Kamar</td><td>: {{ \App\Modules\Finance\Models\InpatientCostEstimate::classLabel($estimasi->room_class) }}</td></tr>
  <tr><td class="label">Perkiraan Lama Rawat</td><td>: {{ $estimasi->estimated_days }} hari</td></tr>
  <tr><td class="label">Tanggal Dibuat</td><td>: {{ $estimasi->prepared_at->format('d F Y, H:i') }} WIB</td></tr>
</table>

<table class="data" style="border-top:1px solid #000;border-bottom:1px solid #000;">
  <tr>
    <td class="label">Biaya Kamar</td>
    <td>: {{ $estimasi->estimated_days }} hari &times; Rp {{ number_format((float) $estimasi->daily_rate, 0, ',', '.') }}</td>
    <td style="text-align:right">Rp {{ number_format((float) $estimasi->daily_rate * $estimasi->estimated_days, 0, ',', '.') }}</td>
  </tr>
  <tr>
    <td class="label">Perkiraan Biaya Lain-lain</td>
    <td>: obat, tindakan, dan penunjang selama rawat</td>
    <td style="text-align:right">Rp {{ number_format((float) $estimasi->other_charges, 0, ',', '.') }}</td>
  </tr>
  <tr>
    <td class="label"><strong>Total Perkiraan</strong></td>
    <td></td>
    <td style="text-align:right"><strong>Rp {{ number_format((float) $estimasi->total_estimate, 0, ',', '.') }}</strong></td>
  </tr>
</table>

@if ($estimasi->note)
  <div class="isi">
    <p><strong>Catatan:</strong> {{ $estimasi->note }}</p>
  </div>
@endif

<div class="isi">
  <p>Perkiraan biaya ini bersifat estimasi berdasarkan tarif kamar rata-rata dan lama rawat yang diperkirakan —
  bukan tagihan final. Biaya sesungguhnya dapat berbeda tergantung perkembangan kondisi pasien selama dirawat.</p>
</div>

<div class="ttd">
  <div class="blok">&nbsp;</div>
  <div class="blok">
    <div>{{ now()->format('d F Y') }}</div>
    <div>{{ $estimasi->prepared_by_name ?? 'Petugas Keuangan' }}</div>
    <div class="garis">&nbsp;</div>
  </div>
</div>

@endsection
