@extends('layouts.app')

@section('title', 'Tata Usaha — Persetujuan Tindakan')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Persetujuan & Penolakan Tindakan')

@section('actions')
  <a href="{{ route('correspondence.keterangan.index') }}" class="btn btn-link">Surat Keterangan &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Catat Persetujuan/Penolakan</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">Catatan terstruktur, bukan tanda tangan elektronik — dicetak dan ditandatangani manual di atas kertas.</div>
    <form method="POST" action="{{ route('correspondence.persetujuan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis</label>
        <select name="consent_type" class="form-select" required>
          <option value="tindakan">Persetujuan Tindakan</option>
          <option value="penolakan-anjuran-medis">Penolakan Anjuran Medis</option>
          <option value="resusitasi">Penolakan Resusitasi (DNR)</option>
          <option value="umum">Persetujuan Umum</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Keputusan</label>
        <select name="decision" class="form-select" required>
          <option value="setuju">Setuju</option>
          <option value="menolak">Menolak</option>
        </select>
      </div>
      <div class="col-12 col-md-6"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
      <div class="col-6"><label class="form-label">ID Kunjungan (opsional)</label><input type="number" name="registration_id" class="form-control"></div>
      <div class="col-6"><label class="form-label">Nama Saksi</label><input type="text" name="witness_name" class="form-control"></div>
      <div class="col-12"><label class="form-label">Uraian Tindakan/Keputusan</label><textarea name="procedure_description" class="form-control" rows="3" required></textarea></div>
      <div class="col-12"><button class="btn btn-primary">Simpan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Keputusan</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($persetujuan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->consent_number }}</td>
            <td>{{ $p->patient_name }}</td>
            <td><span class="badge bg-blue-lt">{{ $p->consent_type }}</span></td>
            <td>
              @php $warnaKeputusan = ['setuju' => 'green', 'menolak' => 'red'][$p->decision]; @endphp
              <span class="badge bg-{{ $warnaKeputusan }}-lt">{{ $p->decision }}</span>
            </td>
            <td>
              @if ($p->status === 'aktif')
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-secondary-lt">Dibatalkan</span>
              @endif
            </td>
            <td>
              <div class="btn-group">
                <a href="{{ route('correspondence.persetujuan.cetak', $p) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Cetak</a>
                @if ($p->status === 'aktif')
                  <form method="POST" action="{{ route('correspondence.persetujuan.batal', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada persetujuan tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
