@extends('layouts.app')

@section('title', 'Pengaturan — Pengguna')
@section('breadcrumb', 'Konteks platform')
@section('heading', 'Pengguna')

@section('actions')
  <a href="{{ route('platform.peran.index') }}" class="btn btn-link">Peran &rarr;</a>
@endsection

@section('content')

<div class="card">
  <div class="card-header"><h3 class="card-title">Pengguna Aplikasi</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nama Pengguna</th><th>Nama</th><th>Peran</th><th>Status</th><th>Login Terakhir</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pengguna as $p)
          <tr>
            <td class="font-monospace small">{{ $p->username }}</td>
            <td>
              {{ $p->name }}
              @if ($p->email)
                <div class="text-secondary small">{{ $p->email }}</div>
              @endif
            </td>
            <td>
              @forelse ($p->roles as $r)
                <span class="badge bg-blue-lt me-1">{{ $r->name }}</span>
              @empty
                <span class="text-secondary">— belum ada peran —</span>
              @endforelse
            </td>
            <td>
              @if ($p->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-red-lt">Nonaktif</span>
              @endif
            </td>
            <td class="text-secondary small">{{ $p->last_login_at?->format('d M Y H:i') ?? 'Belum pernah' }}</td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-pengguna-{{ $p->id }}">Ubah</button>
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reset-sandi-{{ $p->id }}">Reset Sandi</button>
              @if ($p->id !== auth()->id())
                <form method="POST" action="{{ route('platform.pengguna.status', [$p, $p->is_active ? 'nonaktifkan' : 'aktifkan']) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm {{ $p->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">{{ $p->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pengguna.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-body border-top">
    <h4>Tambah Pengguna</h4>
    <form method="POST" action="{{ route('platform.pengguna.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><input type="text" name="username" class="form-control form-control-sm" placeholder="Nama pengguna" required></div>
      <div class="col-6 col-md-2"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama lengkap" required></div>
      <div class="col-6 col-md-2"><input type="email" name="email" class="form-control form-control-sm" placeholder="Surel (opsional)"></div>
      <div class="col-6 col-md-2"><input type="text" name="nip" class="form-control form-control-sm" placeholder="NIP (opsional)"></div>
      <div class="col-6 col-md-2"><input type="password" name="password" class="form-control form-control-sm" placeholder="Kata sandi awal" required minlength="8"></div>
      <div class="col-12 col-md-2">
        <select name="roles[]" class="form-select form-select-sm" multiple required size="1">
          @foreach ($peran as $r)
            <option value="{{ $r->id }}">{{ $r->name }}</option>
          @endforeach
        </select>
        <div class="form-hint">Ctrl/Cmd+klik untuk pilih lebih dari satu peran.</div>
      </div>
      <div class="col-12"><button class="btn btn-sm btn-outline-primary">Tambah Pengguna</button></div>
    </form>
  </div>
</div>

@foreach ($pengguna as $p)
  <div class="modal fade" id="edit-pengguna-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('platform.pengguna.perbarui', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah {{ $p->username }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $p->name }}" required></div>
          <div class="mb-2"><label class="form-label">Surel</label><input type="email" name="email" class="form-control" value="{{ $p->email }}"></div>
          <div class="mb-2"><label class="form-label">NIP</label><input type="text" name="nip" class="form-control" value="{{ $p->nip }}"></div>
          <div class="mb-2">
            <label class="form-label">Peran</label>
            <select name="roles[]" class="form-select" multiple required>
              @foreach ($peran as $r)
                <option value="{{ $r->id }}" @selected($p->roles->contains('id', $r->id))>{{ $r->name }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="reset-sandi-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('platform.pengguna.reset-sandi', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Reset Kata Sandi — {{ $p->username }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Kata Sandi Baru</label>
          <input type="password" name="password" class="form-control" placeholder="Minimal 8 karakter" minlength="8" required>
          <div class="form-hint">Pengguna wajib menggantinya saat masuk berikutnya.</div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Reset</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
