@extends('layouts.app')

@section('title', 'Booking MCU Perusahaan')
@section('breadcrumb', 'Konteks encounter')
@section('heading', 'Booking MCU Perusahaan')

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Booking Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('mcu-perusahaan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label" for="company_name">Nama Perusahaan</label>
        <input type="text" id="company_name" name="company_name" class="form-control" value="{{ old('company_name') }}" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="contact_person">Kontak</label>
        <input type="text" id="contact_person" name="contact_person" class="form-control" value="{{ old('contact_person') }}">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="contact_phone">No. Telepon</label>
        <input type="text" id="contact_phone" name="contact_phone" class="form-control" value="{{ old('contact_phone') }}">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="scheduled_date">Tanggal Pelaksanaan</label>
        <input type="date" id="scheduled_date" name="scheduled_date" class="form-control" value="{{ old('scheduled_date') }}" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="employee_count">Jumlah Karyawan</label>
        <input type="number" min="1" step="1" id="employee_count" name="employee_count" class="form-control" value="{{ old('employee_count') }}" required>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" for="unit_id">Unit Pelaksana (opsional)</label>
        <select id="unit_id" name="unit_id" class="form-select">
          <option value="">— Belum ditentukan —</option>
          @foreach ($units as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" for="package_description">Paket Pemeriksaan</label>
        <input type="text" id="package_description" name="package_description" class="form-control"
               placeholder="mis. Darah lengkap, urine, rontgen thorax, EKG" value="{{ old('package_description') }}">
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Simpan Booking</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach (['dijadwalkan','selesai','dibatalkan'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ \App\Modules\Encounter\Models\CorporateMcuBooking::statusLabel($s) }}</option>
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
          <th>No. Booking</th><th>Perusahaan</th><th>Tanggal</th>
          <th class="text-center">Karyawan</th><th>Status</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $b)
          <tr class="{{ $b->status === 'dibatalkan' ? 'opacity-75' : '' }}">
            <td class="font-monospace small">{{ $b->booking_number }}</td>
            <td>
              <div class="fw-semibold">{{ $b->company_name }}</div>
              <div class="text-secondary small">{{ $b->contact_person }} {{ $b->contact_phone ? '· ' . $b->contact_phone : '' }}</div>
            </td>
            <td>{{ $b->scheduled_date->format('d-m-Y') }}</td>
            <td class="text-center">{{ $b->employee_count }}</td>
            <td>
              @php
                $rona = match ($b->status) { 'dijadwalkan' => 'orange', 'selesai' => 'green', default => 'red' };
              @endphp
              <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Encounter\Models\CorporateMcuBooking::statusLabel($b->status) }}</span>
            </td>
            <td>
              @if ($b->status === 'dijadwalkan')
                <form method="POST" action="{{ route('mcu-perusahaan.selesai', $b) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-success">Selesai</button>
                </form>
                <form method="POST" action="{{ route('mcu-perusahaan.batal', $b) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-danger">Batal</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada booking MCU perusahaan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@endsection
