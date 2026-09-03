@extends('layouts.app')

@section('title', 'Perkiraan Biaya Ranap')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Perkiraan Biaya Ranap')

@section('content')

<div class="card mb-3">
  <div class="card-body">
    <p class="text-secondary small">
      Kelas kamar tersedia berikut tarif rata-rata saat ini:
      @foreach ($kelasKamar as $kelas)
        <span class="badge bg-azure-lt me-1">
          {{ \App\Modules\Finance\Models\InpatientCostEstimate::classLabel($kelas) }}:
          Rp {{ number_format((float) ($tarifKelas[$kelas]->avg_daily_rate ?? 0), 0, ',', '.') }}/hari
        </span>
      @endforeach
    </p>

    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-8">
        <label class="form-label" for="q">Cari kunjungan rawat inap (nomor registrasi / no. RM / nama pasien)</label>
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
                  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#estimasi-{{ $k->id }}">
                    Buat Perkiraan
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada kunjungan rawat inap yang cocok.</td></tr>
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
          <th>No. Estimasi</th><th>Pasien</th><th>Kelas</th><th>Lama Rawat</th>
          <th class="text-end">Total Perkiraan</th><th>Dibuat</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $e)
          <tr>
            <td class="font-monospace small">{{ $e->estimate_number }}</td>
            <td>{{ $e->patient_name }} <span class="text-secondary small font-monospace">{{ $e->patient_mrn }}</span></td>
            <td>{{ \App\Modules\Finance\Models\InpatientCostEstimate::classLabel($e->room_class) }}</td>
            <td>{{ $e->estimated_days }} hari</td>
            <td class="text-end">Rp {{ number_format((float) $e->total_estimate, 0, ',', '.') }}</td>
            <td class="text-secondary small">{{ $e->prepared_at->format('d-m-Y H:i') }}</td>
            <td><a href="{{ route('estimasi-ranap.cetak', $e) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Cetak</a></td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-4">Belum ada perkiraan biaya tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@foreach ($hasilPencarian as $k)
  <div class="modal fade" id="estimasi-{{ $k->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('estimasi-ranap.simpan') }}">
        @csrf
        <input type="hidden" name="registration_id" value="{{ $k->id }}">
        <div class="modal-header">
          <h5 class="modal-title">Buat perkiraan biaya ranap</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-secondary small">{{ $k->registration_number }} &middot; {{ $k->patient_name }} &middot; {{ $k->payer_name }}</p>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" for="kelas-{{ $k->id }}">Kelas kamar</label>
              <select id="kelas-{{ $k->id }}" name="room_class" class="form-select" required>
                @foreach ($kelasKamar as $kelas)
                  <option value="{{ $kelas }}">{{ \App\Modules\Finance\Models\InpatientCostEstimate::classLabel($kelas) }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="hari-{{ $k->id }}">Perkiraan lama rawat (hari)</label>
              <input type="number" min="1" step="1" id="hari-{{ $k->id }}" name="estimated_days" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label" for="lain-{{ $k->id }}">Perkiraan biaya lain-lain (Rp)</label>
              <input type="number" min="0" step="1" id="lain-{{ $k->id }}" name="other_charges" class="form-control" value="0">
              <div class="form-hint">Obat, tindakan, dan penunjang selama rawat — angka tunggal, bukan rincian per item.</div>
            </div>
            <div class="col-12">
              <label class="form-label" for="catatan-{{ $k->id }}">Catatan (opsional)</label>
              <input type="text" id="catatan-{{ $k->id }}" name="note" class="form-control" maxlength="255">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-primary">Simpan &amp; Cetak</button>
        </div>
      </form>
    </div>
  </div>
@endforeach

@endsection
