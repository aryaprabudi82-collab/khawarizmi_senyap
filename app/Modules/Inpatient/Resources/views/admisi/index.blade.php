@extends('layouts.app')

@section('title', 'Rawat Inap — Admisi')
@section('breadcrumb', 'Konteks inpatient')
@section('heading', 'Admisi Rawat Inap')

@section('actions')
  <a href="{{ route('inpatient.kamar.index') }}" class="btn btn-link">Kelola Kamar &amp; Bed &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Menunggu Kamar</h3>
        <div class="card-actions text-secondary small">{{ $menunggu->count() }} registrasi</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Registrasi</th><th>Pasien</th><th>Dokter</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($menunggu as $r)
              <tr>
                <td class="font-monospace small">{{ $r->registration_number }}</td>
                <td>{{ $r->patient_name }}<div class="text-secondary small font-monospace">{{ $r->patient_mrn }}</div></td>
                <td class="text-secondary">{{ $r->practitioner_name ?? '—' }}</td>
                <td>
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#admisi-{{ $r->id }}">Admisi</button>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada registrasi rawat inap yang menunggu kamar.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Sedang Dirawat</h3>
        <div class="card-actions text-secondary small">{{ $dirawat->count() }} pasien</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Admisi</th><th>Pasien</th><th>Kamar/Bed</th><th>Lama Rawat</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($dirawat as $a)
              <tr>
                <td class="font-monospace small">{{ $a->admission_number }}</td>
                <td>{{ $a->patient_name }}<div class="text-secondary small">{{ $a->dpjp_name ?? '—' }}</div></td>
                <td>{{ $a->bed->room->room_number }} / {{ $a->bed->bed_number }}<div class="text-secondary small text-uppercase">{{ $a->bed->room->room_class }}</div></td>
                <td>{{ $a->lengthOfStayDays() }} hari</td>
                <td>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#pulang-{{ $a->id }}">Pulangkan</button>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada pasien yang sedang dirawat.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@foreach ($menunggu as $r)
  <div class="modal fade" id="admisi-{{ $r->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inpatient.admisi.simpan') }}">
        @csrf
        <input type="hidden" name="registration_id" value="{{ $r->id }}">
        <div class="modal-header"><h5 class="modal-title">Admisi {{ $r->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Pilih Bed Tersedia</label>
          <select name="bed_id" class="form-select" required>
            <option value="">— pilih bed —</option>
            @foreach ($bedTersedia as $bed)
              <option value="{{ $bed->id }}">{{ $bed->room->room_number }} / {{ $bed->bed_number }} — {{ strtoupper($bed->room->room_class) }}</option>
            @endforeach
          </select>
          @if ($bedTersedia->isEmpty())
            <div class="form-hint text-danger">Tidak ada bed tersedia saat ini.</div>
          @endif
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary" @disabled($bedTersedia->isEmpty())>Admisi</button></div>
      </form>
    </div>
  </div>
@endforeach

@foreach ($dirawat as $a)
  <div class="modal fade" id="pulang-{{ $a->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inpatient.admisi.pulang', $a) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Pulangkan {{ $a->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Status Pulang</label>
          <select name="discharge_status" class="form-select mb-2" required>
            <option value="sembuh">Sembuh</option>
            <option value="rujuk">Dirujuk</option>
            <option value="aps">Atas Permintaan Sendiri (APS)</option>
            <option value="meninggal">Meninggal</option>
            <option value="lain">Lain-lain</option>
          </select>
          <label class="form-label">Catatan (opsional)</label>
          <textarea name="note" class="form-control" rows="2"></textarea>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-danger">Pulangkan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
