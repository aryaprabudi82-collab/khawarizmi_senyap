@extends('layouts.app')

@section('title', 'Tata Usaha — Master Arsip')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Master Arsip Surat')

@section('actions')
  <a href="{{ route('correspondence.index') }}" class="btn btn-link">&larr; Surat Masuk &amp; Keluar</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Lokasi Arsip</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Satu pohon berjenjang: <b>ruang &rarr; almari &rarr; rak &rarr; map</b>. Khanza menyimpannya
      sebagai empat daftar datar yang kodenya cuma berdampingan pada satu baris surat &mdash;
      susunan itu tidak bisa menyatakan bahwa rak 3 berada di dalam almari B, dan karena tidak
      bisa, ia juga tidak bisa menolak rak yang ada di ruang lain.
    </div>
    <form method="POST" action="{{ route('correspondence.master.lokasi.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2">
        <label class="form-label">Jenjang</label>
        <select name="level" class="form-select" required>
          @foreach ($jenjang as $j)
            <option value="{{ $j }}">{{ ucfirst($j) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-12 col-md-3">
        <label class="form-label">Berada di Dalam</label>
        <select name="parent_id" class="form-select">
          <option value="">&mdash; (kosongkan untuk ruang) &mdash;</option>
          @foreach ($lokasi as $l)
            <option value="{{ $l->id }}">{{ ucfirst($l->level) }} &middot; {{ $l->jalur() }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Jenjang</th><th>Kode</th><th>Jalur</th></tr></thead>
      <tbody>
        @forelse ($lokasi as $l)
          <tr>
            <td><span class="badge bg-blue-lt">{{ $l->level }}</span></td>
            <td class="font-monospace small">{{ $l->code }}</td>
            <td>{{ $l->jalur() }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada lokasi arsip.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Klasifikasi Arsip</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Kode perihal, bukan sifat surat. Inilah yang dipakai menemukan kembali surat bertahun
      kemudian, dan yang tercetak pada nomor surat keluar. <b>Daftarnya sengaja lahir kosong</b>:
      pola klasifikasi arsip adalah keputusan RSP UI, dan kode karangan akan tercetak pada surat
      resmi selama bertahun-tahun.
    </div>
    <form method="POST" action="{{ route('correspondence.master.klasifikasi.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" placeholder="KP.01" required></div>
      <div class="col-12 col-md-5"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-12 col-md-4">
        <label class="form-label">Induk (untuk sub-klasifikasi)</label>
        <select name="parent_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($klasifikasi->whereNull('parent_id') as $k)
            <option value="{{ $k->id }}">{{ $k->code }} &middot; {{ $k->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Induk</th></tr></thead>
      <tbody>
        @forelse ($klasifikasi as $k)
          <tr>
            <td class="font-monospace small">{{ $k->code }}</td>
            <td>{{ $k->name }}</td>
            <td class="text-secondary small">{{ $k->parent?->code ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Belum ditetapkan RSP UI.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Indeks Temu Balik</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">Kata kunci yang dilekatkan pada disposisi, untuk menemukan kembali berkas.</div>
    <form method="POST" action="{{ route('correspondence.master.indeks.simpan') }}" class="row g-2">
      @csrf
      <div class="col-4 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-8 col-md-8"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
      <tbody>
        @forelse ($indeks as $i)
          <tr><td class="font-monospace small">{{ $i->code }}</td><td>{{ $i->name }}</td></tr>
        @empty
          <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada indeks.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
