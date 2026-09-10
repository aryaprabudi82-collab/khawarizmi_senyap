@extends('layouts.app')

@section('title', \App\Modules\Order\Models\LabRadiologyOrder::categoryLabel($kategori))
@section('breadcrumb', 'Konteks order')
@section('heading', 'Antrean ' . \App\Modules\Order\Models\LabRadiologyOrder::categoryLabel($kategori) . ' ' . $tanggal->translatedFormat('l, d F Y'))

@section('content')

<div class="row row-deck row-cards mb-3">
  @php
    $kartu = [
      'diminta' => ['Diminta', 'bg-secondary-lt'],
      'diproses' => ['Diproses', 'bg-orange-lt'],
      'hasil-tersedia' => ['Hasil tersedia', 'bg-blue-lt'],
      'selesai' => ['Selesai', 'bg-green-lt'],
    ];
  @endphp
  @foreach ($kartu as $kode => [$label, $warna])
    <div class="col-6 col-sm-3">
      <a class="card {{ $warna }} text-decoration-none"
         href="{{ route('order.index', [$kategori, 'tanggal' => $tanggal->toDateString(), 'status' => $kode]) }}">
        <div class="card-body py-3">
          <div class="text-secondary small">{{ $label }}</div>
          <div class="h1 mb-0">{{ $ringkasan[$kode] ?? 0 }}</div>
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
          @foreach (['diminta','diproses','hasil-tersedia','selesai','batal'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>
              {{ \App\Modules\Order\Models\LabRadiologyOrder::statusLabel($s) }}
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
          <th>No. Order</th><th>Pasien</th><th>Unit</th><th>Dokter Peminta</th>
          <th class="text-center">Item</th><th>Status</th><th>Jam</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $o)
          <tr class="{{ $o->status === 'batal' ? 'opacity-75' : '' }}">
            <td class="font-monospace small">{{ $o->order_number }}</td>
            <td>
              <div class="fw-semibold">{{ $o->patient_name }}</div>
              <div class="text-secondary small font-monospace">{{ $o->patient_mrn }}</div>
            </td>
            <td>{{ $o->unit_name ?? '—' }}</td>
            <td>{{ $o->requesting_practitioner_name ?? '—' }}</td>
            <td class="text-center">{{ $o->items_count }}</td>
            <td>
              @php
                $rona = match ($o->status) {
                  'diminta' => 'secondary', 'diproses' => 'orange', 'hasil-tersedia' => 'blue',
                  'selesai' => 'green', default => 'red',
                };
              @endphp
              <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Order\Models\LabRadiologyOrder::statusLabel($o->status) }}</span>
            </td>
            <td class="text-secondary">{{ $o->requested_at->format('H:i') }}</td>
            <td><a href="{{ route('order.show', [$kategori, $o]) }}" class="btn btn-sm btn-primary">Buka</a></td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-secondary py-4">Belum ada order pada saringan ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@endsection
