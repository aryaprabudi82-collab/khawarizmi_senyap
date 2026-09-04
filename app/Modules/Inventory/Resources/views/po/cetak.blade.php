@extends('layouts.print')

@section('title', 'Surat Pemesanan ' . $po->po_number)

@section('content')

<h2 class="judul">Surat Pemesanan Barang Non-Medis</h2>
<p style="text-align:center">No. {{ $po->po_number }}</p>

<table class="data">
  <tr><td class="label">Suplier</td><td>: {{ $po->supplier->name }}</td></tr>
  @if ($po->supplier->address)
    <tr><td class="label">Alamat</td><td>: {{ $po->supplier->address }}</td></tr>
  @endif
  <tr><td class="label">Tanggal Pesan</td><td>: {{ $po->ordered_at?->format('d F Y') ?? $po->created_at->format('d F Y') }}</td></tr>
</table>

<table class="data" style="margin-top:16px; width:100%;">
  <thead>
    <tr>
      <th style="text-align:left; border-bottom:1px solid #000;">Barang</th>
      <th style="text-align:right; border-bottom:1px solid #000;">Jumlah</th>
      <th style="text-align:left; border-bottom:1px solid #000;">Satuan</th>
      <th style="text-align:right; border-bottom:1px solid #000;">Harga Satuan</th>
      <th style="text-align:right; border-bottom:1px solid #000;">Subtotal</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($po->items as $baris)
      <tr>
        <td>{{ $baris->item_name }}</td>
        <td style="text-align:right">{{ rtrim(rtrim(number_format((float) $baris->quantity_ordered, 2, ',', '.'), '0'), ',') }}</td>
        <td>{{ $baris->unit_of_measure }}</td>
        <td style="text-align:right">Rp {{ number_format((float) $baris->unit_price, 0, ',', '.') }}</td>
        <td style="text-align:right">Rp {{ number_format((float) $baris->quantity_ordered * (float) $baris->unit_price, 0, ',', '.') }}</td>
      </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr>
      <td colspan="4" style="text-align:right; border-top:1px solid #000;"><strong>Total</strong></td>
      <td style="text-align:right; border-top:1px solid #000;"><strong>Rp {{ number_format((float) $po->total_amount, 0, ',', '.') }}</strong></td>
    </tr>
  </tfoot>
</table>

<div class="ttd">
  <div class="blok">&nbsp;</div>
  <div class="blok">
    <div>{{ now()->format('d F Y') }}</div>
    <div>Bagian Logistik</div>
    <div class="garis">&nbsp;</div>
  </div>
</div>

@endsection
