@extends('layouts.app')

@section('title', 'Piutang Penjamin')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Piutang Penjamin')

@section('content')

@if ($baruDiposting > 0)
  <div class="alert alert-info">
    {{ $baruDiposting }} tagihan baru diposting ke jurnal dan dibuka sebagai piutang.
  </div>
@endif

<div class="row row-deck row-cards mb-3">
  <div class="col-6 col-md-3">
    <a class="card bg-orange-lt text-decoration-none" href="{{ route('piutang.index', ['status' => 'terbuka']) }}">
      <div class="card-body py-3">
        <div class="text-secondary small">Belum tertagih</div>
        <div class="h1 mb-0">{{ $ringkasan['terbuka']->jumlah ?? 0 }}</div>
        <div class="text-secondary small">Rp {{ number_format((float) ($ringkasan['terbuka']->total ?? 0), 0, ',', '.') }}</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a class="card bg-green-lt text-decoration-none" href="{{ route('piutang.index', ['status' => 'tertagih']) }}">
      <div class="card-body py-3">
        <div class="text-secondary small">Sudah tertagih</div>
        <div class="h1 mb-0">{{ $ringkasan['tertagih']->jumlah ?? 0 }}</div>
        <div class="text-secondary small">Rp {{ number_format((float) ($ringkasan['tertagih']->total ?? 0), 0, ',', '.') }}</div>
      </div>
    </a>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>No. Tagihan</th><th>Pasien</th><th>Penjamin</th>
          <th class="text-end">Nilai</th><th>Dibuka</th><th>Status</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $p)
          <tr>
            <td class="font-monospace small">{{ $p->invoice_number }}</td>
            <td>{{ $p->patient_name }}</td>
            <td>{{ $p->payer_name }}</td>
            <td class="text-end">Rp {{ number_format((float) $p->amount, 0, ',', '.') }}</td>
            <td class="text-secondary small">{{ $p->opened_at->format('d-m-Y') }}</td>
            <td>
              @if ($p->isCollected())
                <span class="badge bg-green-lt">Tertagih</span>
              @else
                <span class="badge bg-orange-lt">Terbuka</span>
              @endif
            </td>
            <td>
              @unless ($p->isCollected())
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#tagih-{{ $p->id }}">
                  Catat Tertagih
                </button>
              @else
                <span class="text-secondary small">{{ $p->collection_reference }}</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-4">Belum ada piutang pada saringan ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@foreach ($daftar as $p)
  @unless ($p->isCollected())
    <div class="modal fade" id="tagih-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('piutang.tagih', $p) }}">
          @csrf
          <div class="modal-header">
            <h5 class="modal-title">Catat penerimaan piutang</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-secondary small">
              {{ $p->invoice_number }} &middot; {{ $p->payer_name }} &middot;
              Rp {{ number_format((float) $p->amount, 0, ',', '.') }}
            </p>
            <label class="form-label" for="ref-{{ $p->id }}">Nomor bukti penerimaan</label>
            <input type="text" id="ref-{{ $p->id }}" name="reference" class="form-control"
                   placeholder="mis. No. SP2D atau referensi transfer" required>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
            <button type="submit" class="btn btn-success">Catat Tertagih</button>
          </div>
        </form>
      </div>
    </div>
  @endunless
@endforeach

@endsection
