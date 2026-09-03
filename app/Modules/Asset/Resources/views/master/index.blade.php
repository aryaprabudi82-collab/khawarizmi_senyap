@extends('layouts.app')

@section('title', 'Aset — Registri Inventaris')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Aset & Inventaris')

@section('actions')
  <a href="{{ route('asset.pemeliharaan.index') }}" class="btn btn-link">Pemeliharaan &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Aset</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Aset</th><th>Nama</th><th>Kategori</th><th>Lokasi</th><th>Kondisi</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($aset as $a)
              <tr>
                <td class="font-monospace small">{{ $a->asset_number }}</td>
                <td>{{ $a->name }}</td>
                <td class="text-secondary">{{ $a->category->name }}</td>
                <td class="text-secondary">{{ $a->location->name ?? '—' }}</td>
                <td>
                  @php $warnaKondisi = ['baik' => 'green', 'rusak-ringan' => 'yellow', 'rusak-berat' => 'red'][$a->condition]; @endphp
                  <span class="badge bg-{{ $warnaKondisi }}-lt">{{ $a->condition }}</span>
                </td>
                <td>
                  @php $warnaStatus = ['aktif' => 'green', 'dalam-perbaikan' => 'yellow', 'dihapuskan' => 'secondary'][$a->status]; @endphp
                  <span class="badge bg-{{ $warnaStatus }}-lt">{{ $a->status }}</span>
                </td>
                <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-{{ $a->id }}">Ubah</button></td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada aset.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('asset.aset.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12 col-md-3"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama aset" required></div>
          <div class="col-6 col-md-2">
            <select name="category_id" class="form-select form-select-sm" required>
              @foreach ($kategori as $k)
                <option value="{{ $k->id }}">{{ $k->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="location_id" class="form-select form-select-sm">
              <option value="">— lokasi —</option>
              @foreach ($lokasi as $l)
                <option value="{{ $l->id }}">{{ $l->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2"><input type="text" name="brand" class="form-control form-control-sm" placeholder="Merk"></div>
          <div class="col-6 col-md-2"><input type="date" name="acquisition_date" class="form-control form-control-sm"></div>
          <div class="col-6 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kategori</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($kategori as $k)
              <tr><td class="font-monospace small">{{ $k->code }}</td><td>{{ $k->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada kategori.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('asset.kategori.simpan') }}" class="row g-2">
          @csrf
          <div class="col-5"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Lokasi</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($lokasi as $l)
              <tr><td class="font-monospace small">{{ $l->code }}</td><td>{{ $l->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada lokasi.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('asset.lokasi.simpan') }}" class="row g-2">
          @csrf
          <div class="col-5"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

@foreach ($aset as $a)
  <div class="modal fade" id="edit-{{ $a->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('asset.aset.perbarui', $a) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Aset</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $a->name }}" required></div>
          <div class="mb-2">
            <label class="form-label">Kategori</label>
            <select name="category_id" class="form-select">
              @foreach ($kategori as $k)
                <option value="{{ $k->id }}" @selected($a->category_id === $k->id)>{{ $k->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Lokasi</label>
            <select name="location_id" class="form-select">
              <option value="">—</option>
              @foreach ($lokasi as $l)
                <option value="{{ $l->id }}" @selected($a->location_id === $l->id)>{{ $l->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Kondisi</label>
            <select name="condition" class="form-select">
              @foreach (['baik','rusak-ringan','rusak-berat'] as $c)
                <option value="{{ $c }}" @selected($a->condition === $c)>{{ $c }}</option>
              @endforeach
            </select>
          </div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($a->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
