@extends('layouts.app')

@section('title', 'Perpustakaan — Katalog Koleksi')
@section('breadcrumb', 'Konteks library')
@section('heading', 'Katalog Koleksi')

@section('actions')
  @can('kategori_perpustakaan')
    <a href="{{ route('library.master.index') }}" class="btn btn-link">Master Katalog &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Cari Koleksi</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Satu pencarian untuk <b>seluruh</b> koleksi &mdash; cetak maupun ebook, judul maupun pengarang.
      Khanza memisahkan buku dan ebook jadi dua tabel; pencarian yang lupa salah satunya tetap
      menghasilkan daftar yang terlihat wajar, hanya saja tanpa separuh koleksi.
    </div>
    <form method="GET" action="{{ route('library.index') }}" class="row g-2">
      <div class="col-12 col-md-5">
        <label class="form-label">Kata Kunci</label>
        <input type="search" name="q" class="form-control" value="{{ $filter['q'] }}" placeholder="Judul, nomor panggil, ISBN, atau nama pengarang">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Medium</label>
        <select name="medium" class="form-select">
          <option value="">Semua</option>
          <option value="cetak" @selected($filter['medium'] === 'cetak')>Cetak</option>
          <option value="ebook" @selected($filter['medium'] === 'ebook')>Ebook</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Kategori</label>
        <select name="kategori" class="form-select">
          <option value="">Semua</option>
          @foreach ($kategori as $k)
            <option value="{{ $k->id }}" @selected((string) $filter['kategori'] === (string) $k->id)>{{ $k->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Cari</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor Panggil</th><th>Judul</th><th>Pengarang</th><th>Medium</th><th>Terbit</th><th>Eksemplar</th></tr></thead>
      <tbody>
        @forelse ($koleksi as $c)
          <tr>
            <td class="font-monospace small">{{ $c->code }}</td>
            <td>
              {{ $c->title }}
              @if ($c->edition)<div class="text-secondary small">{{ $c->edition }}</div>@endif
              @if ($c->isbn)<div class="text-secondary small font-monospace">ISBN {{ $c->isbn }}</div>@endif
            </td>
            <td class="small">{{ $c->penulisTerurut() ?: '—' }}</td>
            <td>
              <span class="badge bg-{{ $c->isEbook() ? 'purple' : 'blue' }}-lt">{{ $c->medium }}</span>
            </td>
            <td class="small">{{ $c->publication_year ?: '—' }}</td>
            <td class="small text-secondary">
              @if ($c->isEbook())
                berkas digital
              @else
                {{-- Jumlah eksemplar fisik menyusul pada item B (inventaris). --}}
                &mdash;
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada koleksi yang cocok.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftarkan Koleksi</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('library.koleksi.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Nomor Panggil</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-12 col-md-8"><label class="form-label">Judul</label><input type="text" name="title" class="form-control" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Medium</label>
        <select name="medium" class="form-select" required>
          <option value="cetak">Cetak</option>
          <option value="ebook">Ebook</option>
        </select>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Pengarang <span class="text-secondary fw-normal">(urut sitasi)</span></label>
        <select name="authors[]" class="form-select" multiple size="4">
          @foreach ($pengarang as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
        <div class="form-hint">
          Urutan pilihan menentukan urutan sitasi &mdash; pengarang pertama yang dipakai pada
          daftar pustaka. Buku teks kedokteran hampir selalu ditulis banyak orang; menyimpan
          hanya yang pertama membuat pencarian atas nama pengarang kedua tidak menemukan apa pun.
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Penerbit</label>
        <select name="publisher_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($penerbit as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Kategori</label>
        <select name="category_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($kategori as $k)
            <option value="{{ $k->id }}">{{ $k->name }}</option>
          @endforeach
        </select>
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label">Jenis Koleksi</label>
        <select name="collection_type_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($jenis as $j)
            <option value="{{ $j->id }}">{{ $j->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-3 col-md-2"><label class="form-label">Tahun Terbit</label><input type="number" name="publication_year" class="form-control" min="1400" max="2200"></div>
      <div class="col-3 col-md-2"><label class="form-label">Halaman</label><input type="number" name="page_count" class="form-control" min="1"></div>
      <div class="col-6 col-md-2"><label class="form-label">Edisi</label><input type="text" name="edition" class="form-control"></div>
      <div class="col-6 col-md-3">
        <label class="form-label">ISBN</label>
        <input type="text" name="isbn" class="form-control">
        <div class="form-hint">Boleh kosong &mdash; skripsi dan laporan penelitian memang tidak punya.</div>
      </div>

      <div class="col-12">
        <label class="form-label">Berkas Ebook</label>
        <input type="text" name="file_path" class="form-control" placeholder="Hanya untuk medium ebook">
        <div class="form-hint">
          Wajib untuk ebook: entri tanpa berkas ditemukan pemustaka di hasil pencarian,
          dikira tersedia, lalu tidak menghasilkan apa-apa.
        </div>
      </div>
      <div class="col-12"><label class="form-label">Abstrak</label><textarea name="abstract" class="form-control" rows="2"></textarea></div>

      <div class="col-12"><button class="btn btn-primary">Daftarkan</button></div>
    </form>
  </div>
</div>

@endsection
