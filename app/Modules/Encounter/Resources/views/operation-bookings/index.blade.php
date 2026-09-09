@extends('layouts.app')

@section('title', 'Jadwal Operasi')
@section('breadcrumb', 'Konteks encounter')
@section('heading', 'Jadwal Operasi')

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
          <thead><tr><th>No. Registrasi</th><th>Pasien</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($hasilPencarian as $k)
              <tr>
                <td class="font-monospace small">{{ $k->registration_number }}</td>
                <td>{{ $k->patient_name }} <span class="text-secondary small font-monospace">{{ $k->patient_mrn }}</span></td>
                <td>
                  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#booking-{{ $k->id }}">
                    Jadwalkan Operasi
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada kunjungan yang cocok.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="q" value="{{ $q }}">
      <div class="col-12 col-md-4">
        <label class="form-label" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach (['dijadwalkan','selesai','dibatalkan'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ \App\Modules\Encounter\Models\OperationBooking::statusLabel($s) }}</option>
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
          <th>No. Jadwal</th><th>Pasien</th><th>Tindakan</th><th>Operator</th>
          <th>Jadwal</th><th>Status</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $b)
          <tr class="{{ $b->status === 'dibatalkan' ? 'opacity-75' : '' }}">
            <td class="font-monospace small">{{ $b->booking_number }}</td>
            <td>{{ $b->patient_name }} <span class="text-secondary small font-monospace">{{ $b->patient_mrn }}</span></td>
            <td>{{ $b->procedure_name }}</td>
            <td>{{ $b->surgeon_name ?? '—' }}</td>
            <td class="text-secondary small">{{ $b->scheduled_at->format('d-m-Y H:i') }} {{ $b->operating_room ? '· ' . $b->operating_room : '' }}</td>
            <td>
              @php
                $rona = match ($b->status) { 'dijadwalkan' => 'orange', 'selesai' => 'green', default => 'red' };
              @endphp
              <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Encounter\Models\OperationBooking::statusLabel($b->status) }}</span>
            </td>
            <td>
              @if ($b->status === 'dijadwalkan')
                <form method="POST" action="{{ route('booking-operasi.selesai', $b) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-success">Selesai</button>
                </form>
                <form method="POST" action="{{ route('booking-operasi.batal', $b) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-danger">Batal</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-4">Belum ada jadwal operasi.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@foreach ($hasilPencarian as $k)
  <div class="modal fade" id="booking-{{ $k->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('booking-operasi.simpan') }}">
        @csrf
        <input type="hidden" name="registration_id" value="{{ $k->id }}">
        <div class="modal-header">
          <h5 class="modal-title">Jadwalkan operasi</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-secondary small">{{ $k->registration_number }} &middot; {{ $k->patient_name }}</p>
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label" for="tindakan-{{ $k->id }}">Nama Tindakan Operasi</label>
              <input type="text" id="tindakan-{{ $k->id }}" name="procedure_name" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label" for="operator-{{ $k->id }}">Operator</label>
              <select id="operator-{{ $k->id }}" name="surgeon_id" class="form-select">
                <option value="">— Belum ditentukan —</option>
                @foreach ($praktisi as $p)
                  <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="ok-{{ $k->id }}">Ruang Operasi</label>
              {{-- Dipilih dari master, tidak diketik. Ruang yang sama dulu
                   diketik dua kali oleh dua orang berbeda — penjadwal di sini
                   dan operator di laporan operasi — lalu laporan RL
                   mengelompokkan berdasarkan teksnya. --}}
              <select id="ok-{{ $k->id }}" name="operating_room" class="form-select">
                <option value="">— belum ditentukan —</option>
                @foreach ($ruangOperasi as $r)
                  <option value="{{ $r->code }}">{{ $r->code }} · {{ $r->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12">
              <label class="form-label" for="jadwal-{{ $k->id }}">Jadwal</label>
              <input type="datetime-local" id="jadwal-{{ $k->id }}" name="scheduled_at" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label" for="catatan-{{ $k->id }}">Catatan (opsional)</label>
              <input type="text" id="catatan-{{ $k->id }}" name="note" class="form-control" maxlength="255">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
        </div>
      </form>
    </div>
  </div>
@endforeach

@endsection
