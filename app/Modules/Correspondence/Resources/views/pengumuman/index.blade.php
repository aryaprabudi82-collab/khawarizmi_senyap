@extends('layouts.app')

@section('title', 'Tata Usaha — Pengumuman E-Pasien')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Pengumuman E-Pasien')

@section('actions')
  <a href="{{ route('correspondence.index') }}" class="btn btn-link">&larr; Surat</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Pengumuman Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('correspondence.pengumuman.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12"><label class="form-label">Judul</label><input type="text" name="title" class="form-control" required></div>
      <div class="col-12"><label class="form-label">Isi</label><textarea name="body" class="form-control" rows="3" required></textarea></div>
      <div class="col-6"><label class="form-label">Mulai Tayang</label><input type="date" name="starts_at" class="form-control" value="{{ now()->toDateString() }}" required></div>
      <div class="col-6"><label class="form-label">Selesai Tayang</label><input type="date" name="ends_at" class="form-control"></div>
      <div class="col-12"><button class="btn btn-primary">Tambah</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Pengumuman</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Judul</th><th>Tayang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pengumuman as $p)
          <tr>
            <td>{{ $p->title }}</td>
            <td class="text-secondary small">{{ $p->starts_at->format('d-m-Y') }}{{ $p->ends_at ? ' – ' . $p->ends_at->format('d-m-Y') : ' – tanpa batas' }}</td>
            <td>
              @if ($p->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-red-lt">Nonaktif</span>
              @endif
            </td>
            <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-{{ $p->id }}">Ubah</button></td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada pengumuman.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($pengumuman as $p)
  <div class="modal fade" id="edit-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('correspondence.pengumuman.perbarui', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Pengumuman</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Judul</label><input type="text" name="title" class="form-control" value="{{ $p->title }}" required></div>
          <div class="mb-2"><label class="form-label">Isi</label><textarea name="body" class="form-control" rows="3" required>{{ $p->body }}</textarea></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label">Mulai</label><input type="date" name="starts_at" class="form-control" value="{{ $p->starts_at->toDateString() }}" required></div>
            <div class="col-6"><label class="form-label">Selesai</label><input type="date" name="ends_at" class="form-control" value="{{ $p->ends_at?->toDateString() }}"></div>
          </div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($p->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
