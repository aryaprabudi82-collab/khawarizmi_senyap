@extends('layouts.app')

@section('title', 'Aset — Pengadaan')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'Pengadaan Aset/Inventaris Baru')

@section('actions')
  <a href="{{ route('asset.pengajuan.index') }}" class="btn btn-link">&larr; Pengajuan</a>
@endsection

@section('content')

@can('suplier_inventaris')
<div class="card mb-3">
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
    <form method="POST" action="{{ route('asset.suplier.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
      <div class="col-6 col-md-3"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama suplier" required></div>
      <div class="col-6 col-md-3"><input type="text" name="contact_person" class="form-control form-control-sm" placeholder="Kontak (opsional)"></div>
      <div class="col-6 col-md-2"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Telepon (opsional)"></div>
      <div class="col-6 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
    </form>
  </div>
</div>
@endcan

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Buat PO Baru</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('asset.po.simpan') }}">
      @csrf
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label">Suplier</label>
          <select name="supplier_id" class="form-select" required>
            @foreach ($suplier as $s)
              <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Dari Pengajuan (opsional)</label>
          <select name="requisition_id" class="form-select">
            <option value="">— berdiri sendiri —</option>
            @foreach ($pengajuanTerbuka as $p)
              <option value="{{ $p->id }}">{{ $p->requisition_number }} &middot; {{ $p->unit_name }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Nama Barang</th><th style="width:150px">Kategori</th><th style="width:130px">Jenis</th><th style="width:150px">Produsen</th><th style="width:90px">Jumlah</th><th style="width:130px">Harga Satuan</th></tr></thead>
          <tbody>
            @for ($i = 0; $i < 3; $i++)
              <tr>
                <td><input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Nama aset"></td>
                <td>
                  <select name="category_id[]" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach ($kategori as $k)
                      <option value="{{ $k->id }}">{{ $k->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="type_id[]" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach ($jenis as $j)
                      <option value="{{ $j->id }}">{{ $j->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="manufacturer_id[]" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach ($produsen as $p)
                      <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" step="1" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                <td><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm"></td>
              </tr>
            @endfor
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Buat PO</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">PO Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. PO</th><th>Suplier</th><th class="text-end">Total</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($po as $p)
          <tr>
            <td class="font-monospace small">{{ $p->po_number }}</td>
            <td>{{ $p->supplier->name }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $p->total_amount, 0, ',', '.') }}</td>
            <td>
              @php $warna = ['draf' => 'secondary', 'dipesan' => 'yellow', 'diterima-sebagian' => 'blue', 'diterima' => 'green', 'dibatalkan' => 'red'][$p->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
            </td>
            <td><a href="{{ route('asset.po.show', $p) }}" class="btn btn-sm btn-outline-primary">Detail</a></td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada PO.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
