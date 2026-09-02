@extends('layouts.app')

@section('title', 'Farmasi')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Antrean Resep ' . $tanggal->translatedFormat('l, d F Y'))

@section('content')

<div class="row row-deck row-cards mb-3">
  @php
    $kartu = [
      'menunggu-telaah' => ['Menunggu telaah', 'bg-orange-lt'],
      'disetujui'       => ['Siap diserahkan', 'bg-blue-lt'],
      'diserahkan'      => ['Sudah diserahkan', 'bg-green-lt'],
      'ditolak'         => ['Ditolak apoteker', 'bg-red-lt'],
    ];
  @endphp
  @foreach ($kartu as $kode => [$label, $warna])
    <div class="col-6 col-sm-3">
      <a class="card {{ $warna }} text-decoration-none"
         href="{{ route('resep.index', ['tanggal' => $tanggal->toDateString(), 'status' => $kode]) }}">
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
        <label class="form-label" for="tanggal">Tanggal resep</label>
        <input type="date" id="tanggal" name="tanggal" class="form-control" value="{{ $tanggal->toDateString() }}">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach (['ditulis','menunggu-telaah','disetujui','ditolak','diserahkan','batal'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>
              {{ \App\Modules\Pharmacy\Models\Prescription::statusLabel($s) }}
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
          <th>No. Resep</th><th>Pasien</th><th>Unit</th><th>Dokter</th>
          <th class="text-center">Item</th><th class="text-end">Nilai</th>
          <th>Status</th><th>Jam</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $r)
          <tr class="{{ in_array($r->status, ['batal','ditolak'], true) ? 'opacity-75' : '' }}">
            <td class="font-monospace small">{{ $r->prescription_number }}</td>
            <td>
              <div class="fw-semibold">{{ $r->patient_name }}</div>
              <div class="text-secondary small font-monospace">{{ $r->patient_mrn }}</div>
            </td>
            <td>{{ $r->unit_name ?? '—' }}</td>
            <td>{{ $r->prescriber_name ?? '—' }}</td>
            <td class="text-center">{{ $r->items()->count() }}</td>
            <td class="text-end">Rp {{ number_format((float) $r->total_amount, 0, ',', '.') }}</td>
            <td>
              @php
                $rona = match ($r->status) {
                  'ditulis' => 'secondary', 'menunggu-telaah' => 'orange', 'disetujui' => 'blue',
                  'diserahkan' => 'green', 'ditolak' => 'red', default => 'secondary',
                };
              @endphp
              <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Pharmacy\Models\Prescription::statusLabel($r->status) }}</span>
            </td>
            <td class="text-secondary">{{ $r->prescribed_at->format('H:i') }}</td>
            <td><a href="{{ route('resep.show', $r) }}" class="btn btn-sm btn-primary">Buka</a></td>
          </tr>
        @empty
          <tr><td colspan="9" class="text-center text-secondary py-4">Belum ada resep pada saringan ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@endsection
