@extends('layouts.app')

@section('title', 'Riwayat Batch')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Riwayat Batch')

@section('actions')
  <a href="{{ route('pharmacy.laporan-stok.index') }}" class="btn btn-link">&larr; Laporan Stok</a>
@endsection

@section('content')

<div class="card">
  <div class="card-header"><h3 class="card-title">Buku Besar Batch</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Waktu</th><th>Jenis</th><th class="text-end">Jumlah</th><th class="text-end">Saldo Setelah</th><th>Rujukan</th><th>Catatan</th></tr></thead>
      <tbody>
        @forelse ($riwayat as $m)
          <tr>
            <td class="text-secondary small">{{ \Carbon\Carbon::parse($m->moved_at)->format('d-m-Y H:i') }}</td>
            <td><span class="badge bg-secondary-lt">{{ $m->kind }}</span></td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->quantity, 2, ',', '.'), '0'), ',') }}</td>
            <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $m->balance_after, 2, ',', '.'), '0'), ',') }}</td>
            <td class="text-secondary small">{{ $m->reference_type ? $m->reference_type . ' #' . $m->reference_id : '—' }}</td>
            <td class="text-secondary small">{{ $m->note ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pergerakan untuk batch ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
