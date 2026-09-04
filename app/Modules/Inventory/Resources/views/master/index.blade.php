@extends('layouts.app')

@section('title', 'Logistik — Barang Non-Medis')
@section('breadcrumb', 'Konteks inventory')
@section('heading', 'Barang, Kategori & Suplier')

@section('actions')
  <a href="{{ route('inventory.permintaan.index') }}" class="btn btn-link">Permintaan &rarr;</a>
  @can('stok_opname_logistik')
    <a href="{{ route('inventory.opname.index') }}" class="btn btn-outline-primary">Stok Opname</a>
  @endcan
  @can('ipsrs_riwayat_barang')
    <a href="{{ route('inventory.laporan.index') }}" class="btn btn-outline-primary">Riwayat &amp; Sirkulasi</a>
  @endcan
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Barang</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Satuan</th><th class="text-end">Stok</th><th>Aktif</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($barang as $b)
              <tr>
                <td class="font-monospace small">{{ $b->code }}</td>
                <td>{{ $b->name }}</td>
                <td class="text-secondary">{{ $b->category->name }}</td>
                <td>{{ $b->unit_of_measure }}</td>
                <td class="text-end font-monospace">
                  {{ rtrim(rtrim(number_format((float) $b->quantity_on_hand, 2, ',', '.'), '0'), ',') }}
                  @if ($b->isBelowReorderPoint())
                    <span class="badge bg-red-lt ms-1">Rendah</span>
                  @endif
                </td>
                <td>
                  @if ($b->is_active)
                    <span class="badge bg-green-lt">Aktif</span>
                  @else
                    <span class="badge bg-red-lt">Nonaktif</span>
                  @endif
                </td>
                <td>
                  <div class="btn-group">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-barang-{{ $b->id }}">Ubah</button>
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#masuk-{{ $b->id }}">Masuk</button>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#opname-{{ $b->id }}">Opname</button>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada barang.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('inventory.barang.simpan') }}" class="row g-2">
          @csrf
          <div class="col-6 col-md-2"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-6 col-md-3"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama barang" required></div>
          <div class="col-6 col-md-2">
            <select name="category_id" class="form-select form-select-sm" required>
              @foreach ($kategori as $k)
                <option value="{{ $k->id }}">{{ $k->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2"><input type="text" name="unit_of_measure" class="form-control form-control-sm" placeholder="Satuan (pcs, box)" required></div>
          <div class="col-6 col-md-2"><input type="number" name="reorder_point" class="form-control form-control-sm" placeholder="Ambang stok"></div>
          <div class="col-6 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
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
        <form method="POST" action="{{ route('inventory.kategori.simpan') }}" class="row g-2">
          @csrf
          <div class="col-5"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Suplier</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($suplier as $s)
              <tr><td class="font-monospace small">{{ $s->code }}</td><td>{{ $s->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada suplier.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('inventory.suplier.simpan') }}" class="row g-2">
          @csrf
          <div class="col-5"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

@foreach ($barang as $b)
  <div class="modal fade" id="edit-barang-{{ $b->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inventory.barang.perbarui', $b) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Barang</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $b->name }}" required></div>
          <div class="mb-2">
            <label class="form-label">Kategori</label>
            <select name="category_id" class="form-select">
              @foreach ($kategori as $k)
                <option value="{{ $k->id }}" @selected($b->category_id === $k->id)>{{ $k->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Satuan</label><input type="text" name="unit_of_measure" class="form-control" value="{{ $b->unit_of_measure }}" required></div>
          <div class="mb-2"><label class="form-label">Ambang Stok</label><input type="number" name="reorder_point" class="form-control" value="{{ $b->reorder_point }}"></div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($b->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="masuk-{{ $b->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inventory.barang.masuk', $b) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Stok Masuk — {{ $b->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Jumlah ({{ $b->unit_of_measure }})</label><input type="number" step="0.01" name="quantity" class="form-control" required></div>
          <div class="mb-2">
            <label class="form-label">Sumber</label>
            <select name="source" class="form-select">
              <option value="pembelian">Pembelian</option>
              <option value="hibah">Hibah</option>
              <option value="retur-suplier">Retur dari Suplier</option>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-success">Simpan</button></div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="opname-{{ $b->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inventory.barang.opname', $b) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Stok Opname — {{ $b->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="form-hint mb-2">Stok tercatat saat ini: {{ $b->quantity_on_hand }} {{ $b->unit_of_measure }}.</div>
          <div class="mb-2"><label class="form-label">Hasil Hitung Fisik</label><input type="number" step="0.01" name="counted_quantity" class="form-control" value="{{ $b->quantity_on_hand }}" required></div>
          <div class="mb-2"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-secondary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
