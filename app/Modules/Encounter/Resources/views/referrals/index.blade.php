@extends('layouts.app')

@section('title', 'Registrasi — Rujukan Keluar')
@section('breadcrumb', 'Konteks encounter')
@section('heading', 'Rujukan Keluar')

@section('actions')
  <a href="{{ route('registrasi.index') }}" class="btn btn-link">&larr; Papan Antrean</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Terbitkan Rujukan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('rujukan-keluar.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Kunjungan Hari Ini</label>
        <select name="registration_id" class="form-select" required>
          <option value="">— pilih pasien —</option>
          @foreach ($kunjunganHariIni as $k)
            <option value="{{ $k->id }}">{{ $k->patient_name }} ({{ $k->patient_mrn }}) — {{ $k->registration_number }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Faskes Tujuan</label>
        <input type="text" name="destination_facility_name" class="form-control" placeholder="mis. RSUP Rujukan" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Kode Faskes (opsional)</label>
        <input type="text" name="destination_facility_code" class="form-control" placeholder="Kode PPK Kemenkes bila ada">
      </div>
      <div class="col-12">
        <label class="form-label">Alasan Rujukan</label>
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Diagnosis (opsional)</label>
        <input type="text" name="diagnosis" class="form-control">
      </div>
      <div class="col-12"><button class="btn btn-primary">Terbitkan Rujukan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Tujuan</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($rujukan as $r)
          <tr>
            <td class="font-monospace small">{{ $r->referral_number }}</td>
            <td>{{ $r->patient_name }}</td>
            <td>{{ $r->destination_facility_name }}</td>
            <td>
              @if ($r->status === 'aktif')
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-secondary-lt">Dibatalkan</span>
              @endif
            </td>
            <td>
              <div class="btn-group">
                <a href="{{ route('rujukan-keluar.cetak', $r) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Cetak</a>
                @if ($r->status === 'aktif')
                  <form method="POST" action="{{ route('rujukan-keluar.batal', $r) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada rujukan keluar tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
