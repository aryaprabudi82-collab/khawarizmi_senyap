@extends('layouts.app')

@section('title', 'Data Master — Unit & Praktisi')
@section('breadcrumb', 'Konteks organization')
@section('heading', 'Unit Layanan & Praktisi')

@section('actions')
  <a href="{{ route('master.index') }}" class="btn btn-link">&larr; Layanan &amp; Tarif</a>
@endsection

@section('content')

<div class="row g-3">

  {{-- Unit --}}
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Unit Layanan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Kuota</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($unit as $u)
              <tr>
                <td class="font-monospace small">{{ $u->code }}</td>
                <td>{{ $u->name }}</td>
                <td><span class="badge bg-secondary-lt">{{ $u->kind }}</span></td>
                <td>{{ $u->daily_quota ?? '—' }}</td>
                <td>
                  @if ($u->is_active)
                    <span class="badge bg-green-lt">Aktif</span>
                  @else
                    <span class="badge bg-red-lt">Nonaktif</span>
                  @endif
                </td>
                <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-unit-{{ $u->id }}">Ubah</button></td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada unit.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('master.unit.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama unit" required></div>
          <div class="col-4">
            <select name="kind" class="form-select form-select-sm">
              <option value="poliklinik">Poliklinik</option>
              <option value="igd">IGD</option>
              <option value="rawat-inap">Rawat Inap</option>
              <option value="penunjang">Penunjang</option>
              <option value="penunjang-medis">Penunjang Medis</option>
            </select>
          </div>
          <div class="col-8">
            <input type="number" name="daily_quota" class="form-control form-control-sm" placeholder="Kuota harian (opsional)" min="1">
          </div>
          <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Praktisi --}}
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Praktisi</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Spesialisasi</th><th>Unit</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($praktisi as $p)
              <tr>
                <td class="font-monospace small">{{ $p->code }}</td>
                <td>{{ $p->displayName() }}</td>
                <td class="text-secondary">{{ $p->specialty ?? '—' }}</td>
                <td>
                  @foreach ($p->units as $u)
                    <span class="badge bg-blue-lt">{{ $u->name }}</span>
                  @endforeach
                </td>
                <td>
                  @if ($p->is_active)
                    <span class="badge bg-green-lt">Aktif</span>
                  @else
                    <span class="badge bg-red-lt">Nonaktif</span>
                  @endif
                </td>
                <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-praktisi-{{ $p->id }}">Ubah</button></td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada praktisi.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('master.praktisi.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-2"><input type="text" name="title" class="form-control form-control-sm" placeholder="Gelar"></div>
          <div class="col-4"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-3"><input type="text" name="specialty" class="form-control form-control-sm" placeholder="Spesialisasi"></div>
          <div class="col-8">
            <select name="unit_id" class="form-select form-select-sm" required>
              <option value="">— unit utama —</option>
              @foreach ($unitAktif as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

@foreach ($unit as $u)
  <div class="modal fade" id="edit-unit-{{ $u->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('master.unit.perbarui', $u) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Unit</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $u->name }}" required></div>
          <div class="mb-2">
            <label class="form-label">Jenis</label>
            <select name="kind" class="form-select">
              @foreach (['poliklinik','igd','rawat-inap','penunjang','penunjang-medis'] as $k)
                <option value="{{ $k }}" @selected($u->kind === $k)>{{ $k }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Kuota harian</label><input type="number" name="daily_quota" class="form-control" value="{{ $u->daily_quota }}" min="1"></div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($u->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@foreach ($praktisi as $p)
  <div class="modal fade" id="edit-praktisi-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('master.praktisi.perbarui', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Praktisi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2 mb-2">
            <div class="col-8"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $p->name }}" required></div>
            <div class="col-4"><label class="form-label">Gelar</label><input type="text" name="title" class="form-control" value="{{ $p->title }}"></div>
          </div>
          <div class="mb-2"><label class="form-label">Spesialisasi</label><input type="text" name="specialty" class="form-control" value="{{ $p->specialty }}"></div>
          <div class="mb-2"><label class="form-label">Unit tempat bertugas</label>
            @foreach ($unitAktif as $u)
              <label class="form-check">
                <input type="checkbox" name="units[]" value="{{ $u->id }}" class="form-check-input"
                       @checked($p->units->contains('id', $u->id))>
                <span class="form-check-label">{{ $u->name }}</span>
              </label>
            @endforeach
          </div>
          <label class="form-check mb-2"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($p->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
