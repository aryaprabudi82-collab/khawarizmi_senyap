@extends('layouts.app')

@section('title', 'Toko — Barang & Harga')
@section('breadcrumb', 'Konteks retail')
@section('heading', 'Barang Toko &amp; Harga Jual')

@section('actions')
  @can('stok_opname_toko')
    <a href="{{ route('retail.stok.index') }}" class="btn btn-link">Stok &amp; Opname &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Daftarkan Barang</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Stok <b>tidak disimpan sebagai kolom pada barangnya</b> &mdash; ia dihitung dari buku besar.
      Saldo tanpa buku besar tidak bisa direkonsiliasi: begitu satu transaksi gagal di tengah,
      angkanya melenceng tanpa cara menelusuri sejak kapan maupun karena apa.
    </div>
    <form method="POST" action="{{ route('retail.barang.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama Barang</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Kategori</label>
        <select name="category_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($kategori as $k)
            <option value="{{ $k->id }}">{{ $k->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-4 col-md-1"><label class="form-label">Satuan</label><input type="text" name="unit" class="form-control" value="pcs" required></div>
      <div class="col-4 col-md-2"><label class="form-label">Harga Pokok</label><input type="number" step="0.01" min="0" name="base_cost" class="form-control" value="0" required></div>
      <div class="col-4 col-md-1"><label class="form-label">Min. Stok</label><input type="number" min="0" name="minimum_stock" class="form-control" value="0"></div>
      <div class="col-12"><button class="btn btn-primary">Daftarkan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Master &amp; Patokan Marjin</h3></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <form method="POST" action="{{ route('retail.master.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12"><div class="form-label mb-0">Tambah Data Master</div></div>
          <div class="col-6 col-md-4">
            <select name="master" class="form-select" required>
              <option value="kategori">Kategori</option>
              <option value="suplier">Suplier</option>
              <option value="tingkat-harga">Tingkat Harga</option>
            </select>
          </div>
          <div class="col-6 col-md-3"><input type="text" name="code" class="form-control" placeholder="Kode" required></div>
          <div class="col-8 col-md-4"><input type="text" name="name" class="form-control" placeholder="Nama" required></div>
          <div class="col-4 col-md-1"><button class="btn btn-primary w-100">+</button></div>
        </form>
        <div class="form-hint mt-2">
          <b>Tingkat harga jadi baris, bukan tiga kolom tetap.</b> Khanza menetapkan tepat tiga
          (distributor, grosir, retail); koperasi rumah sakit hampir selalu punya tingkat keempat
          &mdash; harga karyawan &mdash; dan kolom tetap tidak bisa menyatakannya tanpa migrasi.
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <form method="POST" action="{{ route('retail.patokan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12"><div class="form-label mb-0">Patokan Marjin per Tingkat</div></div>
          <div class="col-6 col-md-5">
            <select name="price_tier_id" class="form-select" required>
              @foreach ($tingkat as $t)
                <option value="{{ $t->id }}">{{ $t->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-4 col-md-4"><input type="number" step="0.01" min="0" name="markup_percent" class="form-control" placeholder="% marjin" required></div>
          <div class="col-2 col-md-3"><button class="btn btn-primary w-100">Berlakukan</button></div>
        </form>
        <div class="form-hint mt-2">
          Patokan baru <b>menonaktifkan yang lama, bukan menimpanya</b> &mdash; `tokosetharga` Khanza
          satu baris tanpa kunci, jadi pertanyaan &ldquo;patokan mana yang berlaku waktu barang ini
          dihargai&rdquo; tidak punya jawaban. Patokan ini hanya <b>mengusulkan</b> harga; harga akhir
          tetap ditetapkan manusia.
        </div>
        @if ($patokan->isNotEmpty())
          <div class="mt-2 small">
            @foreach ($patokan as $p)
              <span class="badge bg-blue-lt">{{ $p->tier->name }}: {{ (float) $p->markup_percent }}%</span>
            @endforeach
          </div>
        @endif
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Barang</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Stok</th><th>Harga Pokok</th><th>Harga Jual</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($produk as $p)
          <tr>
            <td class="font-monospace small">{{ $p->code }}</td>
            <td>{{ $p->name }}<div class="text-secondary small">{{ $p->unit }}</div></td>
            <td class="small">{{ $p->category?->name ?: '—' }}</td>
            <td>
              @php $stok = $saldo[$p->id] ?? 0; @endphp
              <span class="badge bg-{{ $stok < $p->minimum_stock ? 'red' : 'green' }}-lt">{{ $stok }}</span>
            </td>
            <td class="small">{{ number_format((float) $p->base_cost, 0, ',', '.') }}</td>
            <td class="small">
              @forelse ($p->prices as $h)
                <div>{{ $h->tier->name }}: {{ number_format((float) $h->price, 0, ',', '.') }}</div>
              @empty
                <span class="text-secondary">belum ditetapkan</span>
              @endforelse
            </td>
            <td>
              @if ($tingkat->isNotEmpty())
                <form method="POST" action="{{ route('retail.harga.simpan', $p) }}" class="d-flex gap-1">
                  @csrf
                  <select name="price_tier_id" class="form-select form-select-sm" style="width:9rem">
                    @foreach ($tingkat as $t)
                      <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                  </select>
                  <input type="number" step="0.01" min="0" name="price" class="form-control form-control-sm" style="width:8rem" placeholder="Harga">
                  <button class="btn btn-sm btn-outline-primary">Simpan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada barang.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
