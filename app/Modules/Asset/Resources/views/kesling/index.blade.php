@extends('layouts.app')

@section('title', 'Aset — Kesehatan Lingkungan')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Kesehatan Lingkungan (Kesling)')

@section('actions')
  <a href="{{ route('asset.index') }}" class="btn btn-link">&larr; Aset</a>
  <a href="{{ route('asset.cssd.index') }}" class="btn btn-link">CSSD &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Pengukuran</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('asset.kesling.pengukuran.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12">
            <label class="form-label">Kategori</label>
            <select name="category" class="form-select" required>
              <option value="limbah-b3-cair">Limbah Cair B3 Medis</option>
              <option value="limbah-b3-padat">Limbah Padat B3 Medis</option>
              <option value="limbah-domestik">Limbah Padat Domestik</option>
              <option value="mutu-air-limbah">Mutu Air Limbah</option>
              <option value="air-pdam">Pemakaian Air PDAM</option>
              <option value="air-tanah">Pemakaian Air Tanah</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label">Parameter (opsional)</label><input type="text" name="parameter" class="form-control" placeholder="mis. BOD, COD, pH"></div>
          <div class="col-6"><label class="form-label">Tanggal Ukur</label><input type="date" name="measured_on" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6"><label class="form-label">Jumlah</label><input type="number" step="0.001" name="quantity" class="form-control" required></div>
          <div class="col-6"><label class="form-label">Satuan</label><input type="text" name="unit" class="form-control" placeholder="kg, liter, m3, mg/L" required></div>
          <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Catat</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Catat Kunjungan Pest Control</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('asset.kesling.pest-control.simpan') }}" class="row g-2">
          @csrf
          <div class="col-6"><label class="form-label">Tanggal</label><input type="date" name="visited_on" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6">
            <label class="form-label">Unit</label>
            <select name="unit_id" class="form-select">
              <option value="">— unit —</option>
              @foreach ($unit as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-12"><label class="form-label">Lokasi Rinci</label><input type="text" name="location" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Temuan</label><textarea name="findings" class="form-control" rows="2" required></textarea></div>
          <div class="col-12"><label class="form-label">Tindakan</label><textarea name="action_taken" class="form-control" rows="2" required></textarea></div>
          <div class="col-12"><label class="form-label">Vendor</label><input type="text" name="vendor" class="form-control"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Catat</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Riwayat Pengukuran</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Kategori</th><th>Parameter</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($pengukuran as $p)
              <tr>
                <td class="text-secondary small">{{ $p->measured_on->format('d-m-Y') }}</td>
                <td><span class="badge bg-blue-lt">{{ $p->category }}</span></td>
                <td class="text-secondary">{{ $p->parameter ?? '—' }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $p->quantity, 3, ',', '.'), '0'), ',') }} {{ $p->unit }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada pengukuran tercatat.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Riwayat Pest Control</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Lokasi</th><th>Temuan</th></tr></thead>
          <tbody>
            @forelse ($pestControl as $v)
              <tr>
                <td class="text-secondary small">{{ $v->visited_on->format('d-m-Y') }}</td>
                <td>{{ $v->location }}</td>
                <td class="text-secondary small">{{ $v->findings }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada kunjungan pest control.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
