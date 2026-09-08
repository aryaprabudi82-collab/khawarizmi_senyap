@extends('layouts.app')

@section('title', 'Perpustakaan — Master Katalog')
@section('breadcrumb', 'Konteks library')
@section('heading', 'Master Katalog Perpustakaan')

@section('actions')
  <a href="{{ route('library.index') }}" class="btn btn-link">&larr; Katalog Koleksi</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Tambah Data Master</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Lima kode Khanza (ruang, kategori, jenis, pengarang, penerbit) di satu layar &mdash;
      kelimanya dikelola pustakawan yang sama, dan memecah gerbangnya melahirkan kewenangan
      yang tidak dipegang siapa pun.
    </div>
    <form method="POST" action="{{ route('library.master.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2">
        <label class="form-label">Master</label>
        <select name="master" class="form-select" required>
          <option value="ruang">Ruang</option>
          <option value="kategori">Kategori</option>
          <option value="jenis">Jenis Koleksi</option>
          <option value="pengarang">Pengarang</option>
          <option value="penerbit">Penerbit</option>
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-12 col-md-3">
        <label class="form-label">Nama Sitasi <span class="text-secondary fw-normal">(pengarang saja)</span></label>
        <input type="text" name="citation_name" class="form-control" placeholder="mis. Harrison, T.R.">
        <div class="form-hint">Pembalikan nama tidak bisa ditebak dari nama lengkap.</div>
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
    </form>
  </div>
</div>

<div class="row g-3">
  @foreach ([
    ['Ruang', $ruang], ['Kategori', $kategori], ['Jenis Koleksi', $jenis],
    ['Penerbit', $penerbit], ['Pengarang', $pengarang],
  ] as [$judul, $baris])
    <div class="col-12 col-lg-6">
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ $judul }}</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
            <tbody>
              @forelse ($baris as $b)
                <tr>
                  <td class="font-monospace small">{{ $b->code }}</td>
                  <td>
                    {{ $b->name }}
                    @if ($judul === 'Pengarang' && $b->citation_name)
                      <div class="text-secondary small">sitasi: {{ $b->citation_name }}</div>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada data.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

@endsection
