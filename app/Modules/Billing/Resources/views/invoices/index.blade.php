@extends('layouts.app')

@section('title', 'Kasir Rawat Jalan')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Tagihan ' . $tanggal->translatedFormat('l, d F Y'))

@section('content')

<div class="row row-deck row-cards mb-3">
  @php
    $kartu = [
      'terbuka' => ['Belum lunas', 'bg-orange-lt'],
      'lunas' => ['Lunas', 'bg-green-lt'],
      'ditanggung-penjamin' => ['Ditanggung penjamin', 'bg-blue-lt'],
      'void' => ['Dibatalkan', 'bg-red-lt'],
    ];
  @endphp
  @foreach ($kartu as $kode => [$label, $warna])
    <div class="col-6 col-sm-3">
      <a class="card {{ $warna }} text-decoration-none"
         href="{{ route('tagihan.index', ['tanggal' => $tanggal->toDateString(), 'status' => $kode]) }}">
        <div class="card-body py-3">
          <div class="text-secondary small">{{ $label }}</div>
          <div class="h1 mb-0">{{ $ringkasan[$kode]->jumlah ?? 0 }}</div>
          @if (($ringkasan[$kode]->sisa ?? 0) > 0)
            <div class="text-secondary small">
              sisa Rp {{ number_format((float) $ringkasan[$kode]->sisa, 0, ',', '.') }}
            </div>
          @endif
        </div>
      </a>
    </div>
  @endforeach
</div>

<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label" for="tanggal">Tanggal</label>
        <input type="date" id="tanggal" name="tanggal" class="form-control" value="{{ $tanggal->toDateString() }}">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach (['terbuka','lunas','ditanggung-penjamin','void'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>
              {{ \App\Modules\Billing\Models\Invoice::statusLabel($s) }}
            </option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>No. Tagihan</th><th>Pasien</th><th>Unit</th><th>Penjamin</th>
          <th class="text-end">Total</th><th class="text-end">Sisa</th>
          <th>Status</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $t)
          <tr class="{{ $t->status === 'void' ? 'opacity-75' : '' }}">
            <td class="font-monospace small">{{ $t->invoice_number }}</td>
            <td>
              <div class="fw-semibold">{{ $t->patient_name }}</div>
              <div class="text-secondary small font-monospace">{{ $t->patient_mrn }}</div>
            </td>
            <td>{{ $t->unit_name ?? '—' }}</td>
            <td>
              {{ $t->payer_name }}
              @if (! $t->isPatientPayable())
                <span class="badge bg-blue-lt ms-1">penjamin</span>
              @endif
            </td>
            <td class="text-end">Rp {{ number_format((float) $t->total_amount, 0, ',', '.') }}</td>
            <td class="text-end {{ $t->outstanding() > 0 && $t->isPatientPayable() ? 'text-danger fw-semibold' : '' }}">
              Rp {{ number_format($t->outstanding(), 0, ',', '.') }}
            </td>
            <td>
              @php
                $rona = match ($t->status) {
                  'terbuka' => 'orange', 'lunas' => 'green', 'ditanggung-penjamin' => 'blue', default => 'red',
                };
              @endphp
              <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Billing\Models\Invoice::statusLabel($t->status) }}</span>
            </td>
            <td><a href="{{ route('tagihan.show', $t) }}" class="btn btn-sm btn-primary">Buka</a></td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-secondary py-4">Belum ada tagihan pada saringan ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@endsection
