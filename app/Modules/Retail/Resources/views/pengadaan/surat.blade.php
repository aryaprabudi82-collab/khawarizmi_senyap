@extends('layouts.print')

@section('title', 'Surat Pemesanan ' . $pesanan->order_number)

@section('content')

{{--
  Surat pemesanan adalah TAMPILAN CETAK pesanan yang sama, bukan entitas
  kedua. `toko_surat_pemesanan` Khanza punya tabelnya sendiri berikut
  detailnya, terpisah dari tabel pembelian — dua tempat yang harus sepakat
  tentang barang dan jumlah yang sama, dan begitu keduanya berbeda tidak
  ada cara menentukan mana yang dikirim ke suplier.
--}}
<h2 class="judul">Surat Pemesanan Barang</h2>
<p style="text-align:center">No. {{ $pesanan->order_number }}</p>

<table class="data">
  <tr><td class="label">Kepada</td><td>: {{ $pesanan->supplier->name }}</td></tr>
  @if ($pesanan->supplier->address)
    <tr><td class="label">Alamat</td><td>: {{ $pesanan->supplier->address }}</td></tr>
  @endif
  <tr><td class="label">Tanggal</td><td>: {{ $pesanan->ordered_on->format('d F Y') }}</td></tr>
  @if ($pesanan->expected_on)
    <tr><td class="label">Diharapkan Datang</td><td>: {{ $pesanan->expected_on->format('d F Y') }}</td></tr>
  @endif
</table>

<div class="isi">
  <p>Dengan ini kami memesan barang berikut:</p>
</div>

<table class="data" style="width:100%">
  <thead>
    <tr><th style="text-align:left">No.</th><th style="text-align:left">Barang</th><th style="text-align:left">Jumlah</th><th style="text-align:left">Harga Satuan</th><th style="text-align:left">Jumlah Harga</th></tr>
  </thead>
  <tbody>
    @php $total = 0; @endphp
    @foreach ($pesanan->items as $i => $baris)
      @php $sub = $baris->quantity * (float) $baris->unit_cost; $total += $sub; @endphp
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $baris->product->name ?? '—' }}</td>
        <td>{{ $baris->quantity }} {{ $baris->product->unit ?? '' }}</td>
        <td>{{ number_format((float) $baris->unit_cost, 0, ',', '.') }}</td>
        <td>{{ number_format($sub, 0, ',', '.') }}</td>
      </tr>
    @endforeach
    <tr><td colspan="4"><b>Total</b></td><td><b>{{ number_format($total, 0, ',', '.') }}</b></td></tr>
  </tbody>
</table>

@if ($pesanan->note)
  <div class="isi"><p>{{ $pesanan->note }}</p></div>
@endif

<div class="ttd">
  <div class="blok">&nbsp;</div>
  <div class="blok">
    <div>{{ now()->format('d F Y') }}</div>
    <div>Pemesan</div>
    <div class="garis">{{ $pesanan->created_by_name ?: ' ' }}</div>
  </div>
</div>

@endsection
