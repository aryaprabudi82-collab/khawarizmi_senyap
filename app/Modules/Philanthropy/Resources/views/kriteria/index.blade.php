@extends('layouts.app')

@section('title', 'ZIS — Kriteria Asesmen')
@section('breadcrumb', 'Konteks philanthropy')
@section('heading', 'Kriteria Asesmen Kelayakan Penerima Dana Kesehatan')

@section('actions')
  @can('zis_pengeluaran_penerima_dankes')
    <a href="{{ route('philanthropy.bantuan.index') }}" class="btn btn-link">Asesmen &amp; Penyaluran &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="alert alert-info">
  <p class="mb-1"><b>Enam belas daftar Khanza, satu layar.</b> Kelima belas tabel <code>zis_keterangan_*</code> berbentuk sama persis (kode + keterangan), dan yang keenam belas &mdash; kepemilikan rumah &mdash; tidak punya tabel sama sekali di Khanza meski menunya ada, karena kelas yang ditunjuk menunya adalah kelas <i>atap rumah</i>.</p>
  <p class="mb-0"><b>Daftar ini sengaja lahir nyaris kosong.</b> Batas penghasilan, ukuran rumah yang dianggap layak, jenis dinding yang dianggap tidak layak: semuanya penilaian amil RSP UI. Menebaknya berarti menerbitkan kriteria kemiskinan resmi yang tidak pernah disepakati siapa pun, lalu memakainya menolak orang. Yang sudah terisi hanya <b>golongan asnaf</b> &mdash; kedelapannya ditetapkan Al-Qur'an surah At-Taubah ayat 60, di luar kewenangan rumah sakit untuk mengubahnya.</p>
</div>

@if ($kategoriKosong !== [])
  <div class="alert alert-warning">
    <b>{{ count($kategoriKosong) }} kategori belum punya pilihan sama sekali</b> &mdash; kategori tanpa kosakata tidak bisa disurvei, jadi pertanyaannya tidak akan muncul di formulir asesmen:
    <ul class="mb-0 mt-1">
      @foreach ($kategoriKosong as $k)
        <li>{{ \App\Modules\Philanthropy\Models\AssessmentCriterion::LABEL_KATEGORI[$k] }}</li>
      @endforeach
    </ul>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Tambah Kriteria</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('philanthropy.kriteria.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Kategori</label>
        <select name="category" class="form-select" required>
          @foreach (\App\Modules\Philanthropy\Models\AssessmentCriterion::KATEGORI as $k)
            <option value="{{ $k }}" @selected(old('category') === $k)>{{ \App\Modules\Philanthropy\Models\AssessmentCriterion::LABEL_KATEGORI[$k] }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" value="{{ old('code') }}" maxlength="20" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Uraian</label><input type="text" name="name" class="form-control" value="{{ old('name') }}" maxlength="120" required></div>
      <div class="col-6 col-md-1">
        <label class="form-label">Bobot</label>
        <input type="number" name="weight" class="form-control" value="{{ old('weight') }}">
        <div class="form-hint">Opsional.</div>
      </div>
      <div class="col-6 col-md-1"><label class="form-label">Urutan</label><input type="number" name="position" class="form-control" value="{{ old('position', 0) }}" min="0"></div>
      <div class="col-12"><button class="btn btn-primary">Simpan</button></div>
    </form>
    <div class="form-hint mt-2">Bobot boleh dikosongkan, dan itu keadaan bawaannya. Kalau diisi, totalnya dihitung pada asesmen &mdash; tapi total <b>tidak pernah</b> berubah sendiri jadi putusan layak/tidak layak: ambang seperti itu belum pernah ditetapkan RSP UI, dan menebaknya berarti menolak keluarga sungguhan dengan angka karangan.</div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Daftar Kriteria</h3>
    <div class="card-actions">
      <form method="GET" class="d-flex gap-2">
        <select name="kategori" class="form-select form-select-sm">
          <option value="">— semua kategori —</option>
          @foreach (\App\Modules\Philanthropy\Models\AssessmentCriterion::KATEGORI as $k)
            <option value="{{ $k }}" @selected($kategoriDipilih === $k)>{{ \App\Modules\Philanthropy\Models\AssessmentCriterion::LABEL_KATEGORI[$k] }}</option>
          @endforeach
        </select>
        <button class="btn btn-sm">Saring</button>
      </form>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kategori</th><th>Kode</th><th>Uraian</th><th class="text-end">Bobot</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @forelse ($kriteria as $kategori => $baris)
          @foreach ($baris as $b)
            <tr>
              <td>@if ($loop->first)<b>{{ \App\Modules\Philanthropy\Models\AssessmentCriterion::LABEL_KATEGORI[$kategori] }}</b>@endif</td>
              <td><code>{{ $b->code }}</code></td>
              <td>{{ $b->name }}</td>
              <td class="text-end">{{ $b->weight ?? '—' }}</td>
              <td>@if ($b->is_active)<span class="badge bg-green-lt">aktif</span>@else<span class="badge bg-secondary-lt">nonaktif</span>@endif</td>
              <td class="text-end">
                <form method="POST" action="{{ route('philanthropy.kriteria.aktif', $b) }}">
                  @csrf
                  <button class="btn btn-sm">{{ $b->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                </form>
              </td>
            </tr>
          @endforeach
        @empty
          <tr><td colspan="6" class="text-secondary">Belum ada kriteria pada penyaring ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
