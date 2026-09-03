@extends('layouts.app')

@section('title', 'Tata Usaha — Surat Keterangan')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Surat Keterangan Medis')

@section('actions')
  <a href="{{ route('correspondence.persetujuan.index') }}" class="btn btn-link">&larr; Persetujuan Tindakan</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Terbitkan Surat</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('correspondence.keterangan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis</label>
        <select name="certificate_type" class="form-select" required>
          <option value="sehat">Keterangan Sehat</option>
          <option value="sakit">Keterangan Sakit</option>
          <option value="berobat">Keterangan Berobat</option>
        </select>
      </div>
      <div class="col-12 col-md-6"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">ID Kunjungan (opsional)</label><input type="number" name="registration_id" class="form-control"></div>
      <div class="col-12"><label class="form-label">Keperluan</label><input type="text" name="purpose" class="form-control" placeholder="mis. untuk keperluan kerja" required></div>
      <div class="col-12"><label class="form-label">Isi Keterangan</label><textarea name="content" class="form-control" rows="3" required placeholder="mis. diagnosis dan anjuran istirahat untuk surat sakit"></textarea></div>
      <div class="col-6"><label class="form-label">Berlaku Sejak</label><input type="date" name="valid_from" class="form-control" value="{{ now()->toDateString() }}" required></div>
      <div class="col-6"><label class="form-label">Berlaku Sampai (opsional)</label><input type="date" name="valid_until" class="form-control"></div>
      <div class="col-12"><button class="btn btn-primary">Terbitkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Berlaku</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($surat as $s)
          <tr>
            <td class="font-monospace small">{{ $s->certificate_number }}</td>
            <td>{{ $s->patient_name }}</td>
            <td><span class="badge bg-blue-lt text-uppercase">{{ $s->certificate_type }}</span></td>
            <td class="text-secondary small">{{ $s->valid_from->format('d-m-Y') }}{{ $s->valid_until ? ' – ' . $s->valid_until->format('d-m-Y') : '' }}</td>
            <td>
              @if ($s->status === 'diterbitkan')
                <span class="badge bg-green-lt">Diterbitkan</span>
              @else
                <span class="badge bg-secondary-lt">Dibatalkan</span>
              @endif
            </td>
            <td>
              <div class="btn-group">
                <a href="{{ route('correspondence.keterangan.cetak', $s) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Cetak</a>
                @if ($s->status === 'diterbitkan')
                  <form method="POST" action="{{ route('correspondence.keterangan.batal', $s) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada surat diterbitkan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
