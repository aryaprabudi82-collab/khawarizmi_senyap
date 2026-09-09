@extends('layouts.app')

@section('title', 'Toko — Stok & Opname')
@section('breadcrumb', 'Konteks retail')
@section('heading', 'Stok, Sirkulasi &amp; Opname Toko')

@section('actions')
  @can('toko_barang')
    <a href="{{ route('retail.index') }}" class="btn btn-link">&larr; Barang &amp; Harga</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Stok Opname</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Stok menurut buku besar <b>dibekukan ke tiap baris saat sesi dibuka</b>, bukan dibaca ulang
      saat ditutup &mdash; kalau dibaca ulang, penjualan yang terjadi selama penghitungan fisik
      berlangsung akan tampak sebagai selisih hitung, dan petugas akan mengejar kehilangan yang
      tidak pernah ada. Selisihnya masuk buku besar sebagai <b>koreksi</b>, tidak menimpa saldo:
      selisih yang ditimpakan menghapus sebabnya bersama angkanya.
    </div>
    <form method="POST" action="{{ route('retail.stok.opname.buka') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-9"><input type="text" name="note" class="form-control" placeholder="Catatan sesi opname"></div>
      <div class="col-12 col-md-3"><button class="btn btn-primary w-100">Buka Sesi Opname</button></div>
    </form>
  </div>

  @foreach ($opname as $sesi)
    <div class="card-body border-top">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
          <b class="font-monospace">{{ $sesi->opname_number }}</b>
          <span class="text-secondary small">&middot; {{ $sesi->counted_on->format('d-m-Y') }} &middot; {{ $sesi->counted_by_name }}</span>
          @php $warna = ['berjalan' => 'yellow', 'selesai' => 'green', 'dibatalkan' => 'secondary'][$sesi->status]; @endphp
          <span class="badge bg-{{ $warna }}-lt ms-1">{{ $sesi->status }}</span>
        </div>
        @if ($sesi->status === 'berjalan')
          <form method="POST" action="{{ route('retail.stok.opname.tutup', $sesi) }}">
            @csrf
            <button class="btn btn-sm btn-outline-success">Tutup &amp; Terbitkan Koreksi</button>
          </form>
        @endif
      </div>

      @if ($sesi->status === 'berjalan')
        @php $belum = count($sesi->belumDihitung()); @endphp
        @if ($belum > 0)
          <div class="alert alert-warning py-2 small">
            {{ $belum }} barang belum dihitung fisik. Menutup sesi ini berarti mengoreksi stoknya ke
            angka yang tidak pernah dihitung siapa pun.
          </div>
        @endif

        <div class="table-responsive">
          <table class="table table-sm table-vcenter">
            <thead><tr><th>Barang</th><th>Sistem</th><th>Fisik</th><th>Selisih</th><th>Nilai</th><th class="w-1"></th></tr></thead>
            <tbody>
              @foreach ($sesi->items as $baris)
                <tr>
                  <td class="small">{{ $baris->product->name ?? $baris->product_id }}</td>
                  <td class="small">{{ $baris->system_quantity }}</td>
                  <td class="small">{{ $baris->counted_quantity ?? '—' }}</td>
                  {{-- Selisih dan nilainya DIHITUNG; `tokoopname.selisih` dan
                       `nomihilang` Khanza adalah nilai turunan yang dibekukan. --}}
                  <td class="small {{ ($baris->selisih() ?? 0) < 0 ? 'text-danger' : '' }}">{{ $baris->selisih() ?? '—' }}</td>
                  <td class="small">{{ $baris->nilaiSelisih() !== null ? number_format($baris->nilaiSelisih(), 0, ',', '.') : '—' }}</td>
                  <td>
                    <form method="POST" action="{{ route('retail.stok.opname.hitung', $baris) }}" class="d-flex gap-1">
                      @csrf
                      <input type="number" min="0" name="counted_quantity" class="form-control form-control-sm" style="width:6rem" value="{{ $baris->counted_quantity }}">
                      <input type="text" name="note" class="form-control form-control-sm" value="{{ $baris->note }}" placeholder="Catatan">
                      <button class="btn btn-sm btn-outline-primary">Simpan</button>
                    </form>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  @endforeach
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Buku Besar Stok</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Satu buku besar untuk tiga kode Khanza &mdash; riwayat barang, sirkulasi, dan sirkulasi 2
      membaca data yang sama dengan penyaring berbeda, dan tiga layar berarti tiga tempat
      memperbaiki satu kesalahan yang sama.
    </div>
    <form method="GET" action="{{ route('retail.stok.index') }}" class="row g-2">
      <div class="col-12 col-md-5">
        <select name="barang" class="form-select">
          <option value="">Semua barang</option>
          @foreach ($produk as $p)
            <option value="{{ $p->id }}" @selected((string) $filterBarang === (string) $p->id)>{{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Saring</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Waktu</th><th>Barang</th><th>Jenis</th><th>Jumlah</th><th>Rujukan</th><th>Catatan</th></tr></thead>
      <tbody>
        @forelse ($pergerakan as $m)
          <tr>
            <td class="small">{{ $m->occurred_at->format('d-m-Y H:i') }}</td>
            <td class="small">{{ $m->product->name ?? $m->product_id }}</td>
            <td><span class="badge bg-blue-lt">{{ $m->kind }}</span></td>
            <td class="small {{ $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
            <td class="font-monospace small">{{ $m->reference ?: '—' }}</td>
            <td class="small text-secondary">{{ $m->note }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pergerakan stok.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
