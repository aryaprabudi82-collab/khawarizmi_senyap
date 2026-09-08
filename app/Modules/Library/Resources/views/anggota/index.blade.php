@extends('layouts.app')

@section('title', 'Perpustakaan — Anggota')
@section('breadcrumb', 'Konteks library')
@section('heading', 'Anggota Perpustakaan')

@section('actions')
  @can('peminjaman_perpustakaan')
    <a href="{{ route('library.sirkulasi.index') }}" class="btn btn-link">Sirkulasi &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Daftarkan Anggota</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Identitas anggota dicatat di sini dan <b>tidak ditautkan keras</b> ke data pasien maupun
      kepegawaian: keanggotaan hidup lebih lama daripada kepegawaian, dan mengikatnya akan membuat
      riwayat pinjam seorang pensiunan lenyap bersama status pegawainya &mdash; padahal buku yang
      belum ia kembalikan tetap harus bisa ditagih.
    </div>
    <form method="POST" action="{{ route('library.anggota.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Nomor Anggota</label><input type="text" name="member_number" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis</label>
        <select name="member_type" class="form-select" required>
          @foreach ($jenis as $j)
            <option value="{{ $j }}">{{ ucfirst($j) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">NIP/NIK/No. RM</label><input type="text" name="person_ref" class="form-control"></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis Kelamin</label>
        <select name="sex" class="form-select">
          <option value="">&mdash;</option>
          <option value="L">Laki-laki</option>
          <option value="P">Perempuan</option>
        </select>
      </div>

      <div class="col-6 col-md-2"><label class="form-label">Tanggal Lahir</label><input type="date" name="birth_date" class="form-control"></div>
      <div class="col-6 col-md-2"><label class="form-label">Bergabung</label><input type="date" name="joined_at" class="form-control" value="{{ now()->toDateString() }}" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Masa Berlaku</label>
        <input type="date" name="expires_at" class="form-control">
        <div class="form-hint">Kosong berarti tidak berbatas.</div>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Telepon</label><input type="text" name="phone" class="form-control"></div>
      <div class="col-12 col-md-4"><label class="form-label">Surel</label><input type="email" name="email" class="form-control"></div>
      <div class="col-12"><label class="form-label">Alamat</label><input type="text" name="address" class="form-control"></div>

      <div class="col-12"><button class="btn btn-primary">Daftarkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Anggota</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Nama</th><th>Jenis</th><th>Masa Berlaku</th><th>Dipegang</th><th>Tunggakan Denda</th></tr></thead>
      <tbody>
        @forelse ($anggota as $a)
          <tr>
            <td class="font-monospace small">{{ $a->member_number }}</td>
            <td>{{ $a->name }}@if ($a->person_ref)<div class="text-secondary small font-monospace">{{ $a->person_ref }}</div>@endif</td>
            <td><span class="badge bg-blue-lt">{{ $a->member_type }}</span></td>
            <td class="small {{ $a->kedaluwarsa() ? 'text-danger' : '' }}">
              {{ $a->expires_at?->format('d-m-Y') ?: 'tidak berbatas' }}
              @if ($a->kedaluwarsa())<div>habis</div>@endif
            </td>
            <td class="small">{{ $a->dipinjam_count }} eksemplar</td>
            <td class="small">{{ number_format($a->dendaTertunggak(), 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada anggota.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
