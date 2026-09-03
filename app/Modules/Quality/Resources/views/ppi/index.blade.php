@extends('layouts.app')

@section('title', 'Mutu — Audit PPI')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'Audit PPI (Pencegahan & Pengendalian Infeksi)')

@section('actions')
  <a href="{{ route('quality.insiden.index') }}" class="btn btn-link">&larr; IKP</a>
  <a href="{{ route('quality.k3.index') }}" class="btn btn-link">K3 &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Catat Audit</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('quality.ppi.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Jenis Audit</label>
        <select name="audit_type" class="form-select" required>
          <optgroup label="Bundle Infeksi">
            <option value="bundle-iadp">Bundle IADP</option>
            <option value="bundle-ido">Bundle IDO</option>
            <option value="bundle-isk">Bundle ISK</option>
            <option value="bundle-plabsi">Bundle PLABSI</option>
            <option value="bundle-vap">Bundle VAP</option>
          </optgroup>
          <optgroup label="Kepatuhan &amp; Fasilitas">
            <option value="cuci-tangan-medis">Cuci Tangan Medis</option>
            <option value="kepatuhan-apd">Kepatuhan APD</option>
            <option value="fasilitas-apd">Fasilitas APD</option>
            <option value="fasilitas-kebersihan-tangan">Fasilitas Kebersihan Tangan</option>
          </optgroup>
          <optgroup label="Penanganan &amp; Pembuangan">
            <option value="pembuangan-benda-tajam">Pembuangan Benda Tajam &amp; Jarum</option>
            <option value="pembuangan-limbah">Pembuangan Limbah</option>
            <option value="pembuangan-limbah-cair-infeksius">Pembuangan Limbah Cair Infeksius</option>
            <option value="penanganan-darah">Penanganan Darah</option>
            <option value="pengelolaan-linen-kotor">Pengelolaan Linen Kotor</option>
            <option value="sterilisasi-alat">Sterilisasi Alat</option>
          </optgroup>
          <optgroup label="Lainnya">
            <option value="penempatan-pasien">Penempatan Pasien</option>
            <option value="kamar-jenazah">Kamar Jenazah</option>
          </optgroup>
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Tanggal</label><input type="date" name="audited_on" class="form-control" value="{{ now()->toDateString() }}" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Unit</label>
        <select name="unit_id" class="form-select">
          <option value="">— unit —</option>
          @foreach ($unit as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Kepatuhan (%)</label><input type="number" step="0.01" min="0" max="100" name="compliance_rate" class="form-control"></div>
      <div class="col-12"><label class="form-label">Temuan</label><textarea name="findings" class="form-control" rows="2" required></textarea></div>
      <div class="col-12"><label class="form-label">Tindakan Korektif</label><textarea name="corrective_action" class="form-control" rows="2"></textarea></div>
      <div class="col-12"><button class="btn btn-primary">Catat</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat Audit</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Tanggal</th><th>Jenis</th><th class="text-end">Kepatuhan</th><th>Temuan</th></tr></thead>
      <tbody>
        @forelse ($audit as $a)
          <tr>
            <td class="text-secondary small">{{ $a->audited_on->format('d-m-Y') }}</td>
            <td><span class="badge bg-blue-lt">{{ $a->audit_type }}</span></td>
            <td class="text-end font-monospace">{{ $a->compliance_rate !== null ? $a->compliance_rate . '%' : '—' }}</td>
            <td class="text-secondary small">{{ $a->findings }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada audit tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
