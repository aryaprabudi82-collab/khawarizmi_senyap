@extends('layouts.app')

@section('title', 'Farmasi — Data Master')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Data Master Farmasi')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Obat, Alkes &amp; BHP</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Kategori</th><th>Golongan</th><th>Satuan Dasar</th><th class="text-end">Harga</th><th class="text-end">PPN</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($obat as $o)
          <tr>
            <td class="font-monospace small">{{ $o->code }}</td>
            <td>{{ $o->label() }}</td>
            <td><span class="badge bg-secondary-lt">{{ strtoupper($o->category) }}</span></td>
            <td class="text-secondary small">{{ $o->drugCategory->name ?? '—' }}</td>
            <td class="text-secondary small">{{ $o->drugClass->name ?? '—' }}</td>
            <td>{{ $o->unit }}</td>
            <td class="text-end font-monospace">Rp {{ number_format((float) $o->sell_price, 0, ',', '.') }}</td>
            <td class="text-end font-monospace text-secondary small">{{ $o->vat_rate !== null ? rtrim(rtrim(number_format((float) $o->vat_rate, 2, ',', '.'), '0'), ',') . '%' : 'bebas' }}</td>
            <td>
              @if ($o->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-red-lt">Nonaktif</span>
              @endif
            </td>
            <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#konversi-{{ $o->id }}">Konversi</button></td>
          </tr>
        @empty
          <tr><td colspan="10" class="text-center text-secondary py-3">Belum ada obat/alkes/BHP.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-body border-top">
    <form method="POST" action="{{ route('pharmacy.master.obat.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
      <div class="col-6 col-md-3"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama" required></div>
      <div class="col-6 col-md-3"><input type="text" name="generic_name" class="form-control form-control-sm" placeholder="Nama generik"></div>
      <div class="col-6 col-md-2">
        <select name="category" class="form-select form-select-sm">
          <option value="obat">Obat</option>
          <option value="bhp">BHP</option>
          <option value="alkes">Alkes</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="drug_category_id" class="form-select form-select-sm">
          <option value="">— kategori —</option>
          @foreach ($kategori as $k)
            <option value="{{ $k->id }}">{{ $k->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="drug_class_id" class="form-select form-select-sm">
          <option value="">— golongan —</option>
          @foreach ($golongan as $g)
            <option value="{{ $g->id }}">{{ $g->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="manufacturer_id" class="form-select form-select-sm">
          <option value="">— industri farmasi —</option>
          @foreach ($industri as $i)
            <option value="{{ $i->id }}">{{ $i->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-4 col-md-2"><input type="text" name="form" class="form-control form-control-sm" placeholder="Bentuk, mis. tablet"></div>
      <div class="col-4 col-md-2"><input type="text" name="strength" class="form-control form-control-sm" placeholder="Kekuatan, mis. 500 mg"></div>
      <div class="col-4 col-md-2"><input type="text" name="unit" class="form-control form-control-sm" placeholder="Satuan dasar" required></div>
      <div class="col-6 col-md-2"><input type="number" step="0.01" min="0" name="sell_price" class="form-control form-control-sm" placeholder="Harga jual" required></div>
      <div class="col-6 col-md-2"><input type="number" step="0.01" min="0" max="100" name="vat_rate" class="form-control form-control-sm" placeholder="PPN % (kosong = bebas)"></div>
      <div class="col-6 col-md-2"><input type="number" step="0.01" min="0" name="minimum_stock" class="form-control form-control-sm" placeholder="Stok minimum"></div>
      <div class="col-12 d-flex gap-3 flex-wrap">
        <label class="form-check"><input type="checkbox" name="requires_prescription" value="1" class="form-check-input" checked><span class="form-check-label small">Wajib resep</span></label>
        <label class="form-check"><input type="checkbox" name="is_narcotic" value="1" class="form-check-input"><span class="form-check-label small">Narkotika</span></label>
        <label class="form-check"><input type="checkbox" name="is_psychotropic" value="1" class="form-check-input"><span class="form-check-label small">Psikotropika</span></label>
        <label class="form-check"><input type="checkbox" name="is_high_alert" value="1" class="form-check-input"><span class="form-check-label small">High-alert</span></label>
      </div>
      <div class="col-12"><button class="btn btn-sm btn-outline-primary">Tambah Obat/Alkes/BHP</button></div>
    </form>
  </div>
</div>

<div class="row g-3">

  {{-- Kategori Obat --}}
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kategori Obat</h3></div>
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
        <form method="POST" action="{{ route('pharmacy.master.kategori.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama, mis. Antibiotik" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Golongan Obat --}}
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Golongan Obat</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($golongan as $g)
              <tr><td class="font-monospace small">{{ $g->code }}</td><td>{{ $g->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada golongan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('pharmacy.master.golongan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama, mis. Obat Keras" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Satuan --}}
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Satuan Barang</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($satuan as $s)
              <tr><td class="font-monospace small">{{ $s->code }}</td><td>{{ $s->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada satuan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('pharmacy.master.satuan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama, mis. Boks" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Suplier --}}
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Suplier</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>No. Izin</th></tr></thead>
          <tbody>
            @forelse ($suplier as $s)
              <tr><td class="font-monospace small">{{ $s->code }}</td><td>{{ $s->name }}</td><td class="text-secondary small">{{ $s->license_number ?? '—' }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada suplier.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('pharmacy.master.suplier.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-8"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama suplier" required></div>
          <div class="col-6"><input type="text" name="license_number" class="form-control form-control-sm" placeholder="No. izin PBF"></div>
          <div class="col-6"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Telepon"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Tambah Suplier</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Industri Farmasi --}}
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Industri Farmasi</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>No. Izin</th></tr></thead>
          <tbody>
            @forelse ($industri as $i)
              <tr><td class="font-monospace small">{{ $i->code }}</td><td>{{ $i->name }}</td><td class="text-secondary small">{{ $i->license_number ?? '—' }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada industri farmasi.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('pharmacy.master.industri.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-8"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama pabrikan" required></div>
          <div class="col-12"><input type="text" name="license_number" class="form-control form-control-sm" placeholder="No. izin industri"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Tambah Industri</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Metode Racik --}}
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Metode Racik</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($metodeRacik as $m)
              <tr><td class="font-monospace small">{{ $m->code }}</td><td>{{ $m->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada metode racik.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('pharmacy.master.metode-racik.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-8"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama, mis. Puyer" required></div>
          <div class="col-12"><input type="text" name="description" class="form-control form-control-sm" placeholder="Keterangan (opsional)"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Tambah Metode</button></div>
        </form>
      </div>
    </div>
  </div>

</div>

@foreach ($obat as $o)
  <div class="modal fade" id="konversi-{{ $o->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Konversi Satuan &middot; {{ $o->name }}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <table class="table table-sm mb-3">
            <thead><tr><th>Satuan</th><th class="text-end">= satuan dasar ({{ $o->unit }})</th><th>Beli</th><th>Serah</th></tr></thead>
            <tbody>
              @forelse ($o->units as $du)
                <tr>
                  <td>{{ $du->unit->name }}</td>
                  <td class="text-end font-monospace">{{ rtrim(rtrim((string) $du->conversion_to_base, '0'), '.') }}</td>
                  <td>{{ $du->is_purchase_unit ? 'Ya' : '—' }}</td>
                  <td>{{ $du->is_dispense_unit ? 'Ya' : '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-center text-secondary py-2">Hanya satuan dasar ({{ $o->unit }}), belum ada konversi.</td></tr>
              @endforelse
            </tbody>
          </table>
          <form method="POST" action="{{ route('pharmacy.master.konversi.simpan', $o) }}" class="row g-2">
            @csrf
            <div class="col-5">
              <select name="unit_id" class="form-select form-select-sm" required>
                <option value="">— satuan —</option>
                @foreach ($satuan as $s)
                  <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-4"><input type="number" step="0.0001" min="0.0001" name="conversion_to_base" class="form-control form-control-sm" placeholder="Nilai" required></div>
            <div class="col-3"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
            <div class="col-6"><label class="form-check"><input type="checkbox" name="is_purchase_unit" value="1" class="form-check-input"><span class="form-check-label small">Satuan beli</span></label></div>
            <div class="col-6"><label class="form-check"><input type="checkbox" name="is_dispense_unit" value="1" class="form-check-input"><span class="form-check-label small">Satuan serah</span></label></div>
          </form>
        </div>
      </div>
    </div>
  </div>
@endforeach

@endsection
