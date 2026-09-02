@extends('layouts.app')

@section('title', 'Rekam Medis Elektronik')
@section('breadcrumb', 'Konteks clinical &middot; Permenkes 24/2022')
@section('heading', 'Pasien Menunggu Pemeriksaan')

@section('content')

<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label" for="tanggal">Tanggal</label>
        <input type="date" id="tanggal" name="tanggal" class="form-control" value="{{ $tanggal->toDateString() }}">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="unit_id">Unit layanan</label>
        <select id="unit_id" name="unit_id" class="form-select">
          <option value="">Semua unit</option>
          @foreach ($units as $unit)
            <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="praktisi_id">Dokter</label>
        <select id="praktisi_id" name="praktisi_id" class="form-select">
          <option value="">Semua dokter</option>
          @foreach ($praktisi as $dokter)
            <option value="{{ $dokter->id }}" @selected($praktisiId === $dokter->id)>{{ $dokter->displayName() }}</option>
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
          <th style="width:70px" class="text-center">Antrean</th>
          <th>Pasien</th>
          <th>Unit</th>
          <th>Dokter</th>
          <th>Penjamin</th>
          <th>Catatan medis</th>
          <th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($menunggu as $kunjungan)
          <tr>
            <td class="text-center queue-number">{{ $kunjungan->queue_number }}</td>
            <td>
              <div class="fw-semibold">{{ $kunjungan->patient_name }}</div>
              <div class="text-secondary small font-monospace">{{ $kunjungan->patient_mrn }}</div>
            </td>
            <td>{{ $kunjungan->unit_name }}</td>
            <td>{{ $kunjungan->practitioner_name ?? '—' }}</td>
            <td>{{ $kunjungan->payer_name }}</td>
            <td>
              @php $status = $sudahDinilai[$kunjungan->id] ?? null; @endphp
              @if ($status === null)
                <span class="badge bg-secondary-lt">belum dimulai</span>
              @elseif ($status === 'draft')
                <span class="badge bg-yellow-lt">draf</span>
              @elseif ($status === 'amended')
                <span class="badge bg-orange-lt">diralat</span>
              @else
                <span class="badge bg-green-lt">final</span>
              @endif
            </td>
            <td>
              <a href="{{ route('rme.edit', $kunjungan->id) }}" class="btn btn-sm btn-primary">
                {{ $status === null ? 'Mulai Periksa' : 'Buka' }}
              </a>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="text-center text-secondary py-4">
              Tidak ada pasien menunggu pemeriksaan pada saringan ini.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
