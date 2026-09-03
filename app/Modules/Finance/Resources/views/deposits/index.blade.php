@extends('layouts.app')

@section('title', 'Deposit Pasien')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Deposit Pasien')

@section('content')

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-8">
        <label class="form-label" for="q">Cari kunjungan (nomor registrasi / no. RM / nama pasien)</label>
        <input type="text" id="q" name="q" class="form-control" value="{{ $q }}" placeholder="mis. REG-20260903-00007">
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-outline-primary w-100">Cari</button>
      </div>
    </form>

    @if ($q !== '')
      <div class="table-responsive mt-3">
        <table class="table table-sm table-vcenter">
          <thead><tr><th>No. Registrasi</th><th>Pasien</th><th>Penjamin</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($hasilPencarian as $k)
              <tr>
                <td class="font-monospace small">{{ $k->registration_number }}</td>
                <td>{{ $k->patient_name }} <span class="text-secondary small font-monospace">{{ $k->patient_mrn }}</span></td>
                <td>{{ $k->payer_name }}</td>
                <td>
                  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#deposit-{{ $k->id }}">
                    Catat Deposit
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada kunjungan yang cocok.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>No. Deposit</th><th>Pasien</th><th>Penjamin</th>
          <th class="text-end">Jumlah</th><th>Diterima</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $d)
          <tr>
            <td class="font-monospace small">{{ $d->deposit_number }}</td>
            <td>{{ $d->patient_name }} <span class="text-secondary small font-monospace">{{ $d->patient_mrn }}</span></td>
            <td>{{ $d->payer_name }}</td>
            <td class="text-end">Rp {{ number_format((float) $d->amount, 0, ',', '.') }}</td>
            <td class="text-secondary small">{{ $d->deposited_at->format('d-m-Y H:i') }} &middot; {{ $d->deposited_by_name ?? '—' }}</td>
            <td>
              @if ($d->status === 'aktif')
                <span class="badge bg-blue-lt">Aktif</span>
              @else
                <span class="badge bg-secondary-lt">Terpakai</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada deposit tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@foreach ($hasilPencarian as $k)
  <div class="modal fade" id="deposit-{{ $k->id }}" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('deposit.simpan') }}">
        @csrf
        <input type="hidden" name="registration_id" value="{{ $k->id }}">
        <div class="modal-header">
          <h5 class="modal-title">Catat deposit</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-secondary small">{{ $k->registration_number }} &middot; {{ $k->patient_name }}</p>
          <label class="form-label" for="jumlah-{{ $k->id }}">Jumlah deposit (Rp)</label>
          <input type="number" min="1" step="1" id="jumlah-{{ $k->id }}" name="amount" class="form-control" required>
          <label class="form-label mt-2" for="catatan-{{ $k->id }}">Catatan (opsional)</label>
          <input type="text" id="catatan-{{ $k->id }}" name="note" class="form-control" maxlength="255">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-primary">Simpan Deposit</button>
        </div>
      </form>
    </div>
  </div>
@endforeach

@endsection
