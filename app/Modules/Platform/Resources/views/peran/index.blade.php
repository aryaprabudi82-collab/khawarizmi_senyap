@extends('layouts.app')

@section('title', 'Pengaturan — Peran')
@section('breadcrumb', 'Konteks platform')
@section('heading', 'Peran & Hak Akses')

@section('actions')
  <a href="{{ route('platform.pengguna.index') }}" class="btn btn-link">Pengguna &rarr;</a>
@endsection

@section('content')

<div class="card">
  <div class="card-header"><h3 class="card-title">Peran</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Deskripsi</th><th>Jenis</th><th>Pengguna</th><th>Modul</th><th class="w-1"></th></tr></thead>
      <tbody>
        @foreach ($peran as $r)
          <tr>
            <td class="font-monospace small">{{ $r->code }}</td>
            <td>{{ $r->name }}</td>
            <td class="text-secondary">{{ $r->description }}</td>
            <td>
              @if ($r->is_system)
                <span class="badge bg-secondary-lt">Bawaan sistem</span>
              @else
                <span class="badge bg-azure-lt">Custom</span>
              @endif
            </td>
            <td>{{ $r->users_count }}</td>
            <td>{{ $r->is_system ? '—' : $r->permissions_count . ' modul' }}</td>
            <td>
              @if (! $r->is_system)
                <a href="{{ route('platform.peran.show', $r) }}" class="btn btn-sm btn-outline-primary">Atur Modul</a>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="card-body border-top">
    <h4>Buat Peran Custom</h4>
    <p class="text-secondary small">Peran bawaan sistem (super-admin, dokter, kasir, dsb.) dikelola lewat berkas konfigurasi dan tidak bisa diubah di sini. Buat peran baru untuk memberi seseorang akses ke modul tertentu saja — mis. "Admin CSSD Malam" yang hanya boleh mengakses sirkulasi CSSD.</p>
    <form method="POST" action="{{ route('platform.peran.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode (mis. admin-cssd-malam)" required></div>
      <div class="col-6 col-md-3"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama peran" required></div>
      <div class="col-12 col-md-4"><input type="text" name="description" class="form-control form-control-sm" placeholder="Deskripsi singkat (opsional)"></div>
      <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Buat &amp; Atur Modul</button></div>
    </form>
  </div>
</div>

@endsection
