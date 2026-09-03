@extends('layouts.app')

@section('title', 'Pengaturan — Peran ' . $peran->name)
@section('breadcrumb', 'Konteks platform')
@section('heading', 'Atur Modul — ' . $peran->name)

@section('actions')
  <a href="{{ route('platform.peran.index') }}" class="btn btn-link">&larr; Semua Peran</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Modul yang Boleh Diakses</h3>
      </div>
      <form method="POST" action="{{ route('platform.peran.perbarui', $peran) }}">
        @csrf
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-6"><label class="form-label">Nama Peran</label><input type="text" name="name" class="form-control" value="{{ $peran->name }}" required></div>
            <div class="col-6"><label class="form-label">Deskripsi</label><input type="text" name="description" class="form-control" value="{{ $peran->description }}"></div>
          </div>

          @foreach ($kelompok as $grup)
            <div class="mb-3">
              <div class="fw-bold mb-1">{{ $grup['label'] }}</div>
              <div class="row">
                @foreach ($grup['permissions'] as $izin)
                  <div class="col-12 col-md-6">
                    <label class="form-check">
                      <input type="checkbox" name="permissions[]" value="{{ $izin->code }}" class="form-check-input" @checked(in_array($izin->code, $kodeDimiliki, true))>
                      <span class="form-check-label">{{ $izin->name }}</span>
                    </label>
                  </div>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
        <div class="card-footer text-end">
          <button type="submit" class="btn btn-primary">Simpan Modul</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pengguna dengan Peran Ini</h3></div>
      <div class="list-group list-group-flush">
        @forelse ($peran->users as $u)
          <div class="list-group-item">{{ $u->name }} <span class="text-secondary small">({{ $u->username }})</span></div>
        @empty
          <div class="list-group-item text-secondary">Belum ada pengguna dengan peran ini.</div>
        @endforelse
      </div>
    </div>

    @if ($peran->users->isEmpty())
      <form method="POST" action="{{ route('platform.peran.hapus', $peran) }}" onsubmit="return confirm('Hapus peran {{ $peran->name }}?')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-outline-danger w-100">Hapus Peran</button>
      </form>
    @endif
  </div>
</div>

@endsection
