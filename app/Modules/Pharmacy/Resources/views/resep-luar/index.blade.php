@extends('layouts.app')

@section('title', 'Farmasi — Resep Luar')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Resep Luar (Walk-in)')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Terima Resep Luar</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('pharmacy.resep-luar.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-4"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
        <div class="col-12 col-md-3"><label class="form-label">No. Identitas (opsional)</label><input type="text" name="patient_identity_number" class="form-control"></div>
        <div class="col-12 col-md-3"><label class="form-label">Dokter Penulis</label><input type="text" name="prescriber_name" class="form-control" required></div>
        <div class="col-12 col-md-2"><label class="form-label">No. SIP (opsional)</label><input type="text" name="prescriber_license" class="form-control"></div>
        <div class="col-12 col-md-3"><label class="form-label">Tanggal Resep</label><input type="date" name="issued_date" class="form-control" value="{{ now()->toDateString() }}" required></div>
        <div class="col-12 col-md-9"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control"></div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Obat/Alkes/BHP</th><th style="width:120px">Jumlah</th><th style="width:160px">Harga Satuan</th></tr></thead>
          <tbody>
            @foreach ($obat as $o)
              <tr>
                <td>
                  {{ $o->name }}
                  <input type="hidden" name="drug_id[]" value="{{ $o->id }}">
                </td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                <td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm" value="{{ $o->sell_price }}"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Terima Resep</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Resep Luar Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Resep</th><th>Pasien</th><th>Dokter Penulis</th><th class="text-end">Total</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($resep as $r)
          <tr>
            <td class="font-monospace small">{{ $r->prescription_number }}</td>
            <td>{{ $r->patient_name }}</td>
            <td class="text-secondary small">{{ $r->prescriber_name }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $r->total_amount, 0, ',', '.') }}</td>
            <td>
              @php $warna = ['diterima' => 'yellow', 'diserahkan' => 'green', 'dibatalkan' => 'red'][$r->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $r->status }}</span>
            </td>
            <td>
              @if ($r->status === 'diterima')
                <div class="btn-group">
                  <form method="POST" action="{{ route('pharmacy.resep-luar.serahkan', $r) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-success">Serahkan</button>
                  </form>
                  <form method="POST" action="{{ route('pharmacy.resep-luar.batal', $r) }}" onsubmit="return confirm('Batalkan resep luar ini?')">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Batal</button>
                  </form>
                </div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada resep luar.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
