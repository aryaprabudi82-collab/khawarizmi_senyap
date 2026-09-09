@extends('layouts.app')

@section('title', 'Toko — Kasir & Piutang')
@section('breadcrumb', 'Konteks retail')
@section('heading', 'Kasir, Piutang &amp; Rekap Toko')

@section('actions')
  @can('toko_barang')
    <a href="{{ route('retail.index') }}" class="btn btn-link">Barang &amp; Harga &rarr;</a>
  @endcan
  @can('toko_pengadaan_barang')
    <a href="{{ route('retail.pengadaan.index') }}" class="btn btn-link">Pengadaan &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Rekap Periode</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Empat kode rekap Khanza &mdash; pendapatan harian, penjualan harian, piutang harian, dan
      keuntungan barang &mdash; dilayani <b>satu hitungan</b>. Keempatnya membaca data yang sama dari
      sudut berbeda, dan empat layar berarti empat tempat yang bisa berbeda jawabannya untuk hari
      yang sama.
    </div>
    <form method="GET" action="{{ route('retail.penjualan.index') }}" class="row g-2 mb-3">
      <div class="col-6 col-md-3"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $periode['dari'] }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $periode['sampai'] }}"></div>
      <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>

    <div class="row g-2">
      @foreach ([
        'Jumlah Nota' => $rekap['jumlah_nota'],
        'Nilai Penjualan' => number_format($rekap['nilai_penjualan'], 0, ',', '.'),
        'Pendapatan Kas' => number_format($rekap['pendapatan_kas'], 0, ',', '.'),
        'Nilai Retur' => number_format($rekap['nilai_retur'], 0, ',', '.'),
        'Modal Terjual' => number_format($rekap['modal_terjual'], 0, ',', '.'),
        'Keuntungan' => number_format($rekap['keuntungan'], 0, ',', '.'),
      ] as $judul => $nilai)
        <div class="col-6 col-md-2">
          <div class="border rounded p-2">
            <div class="text-secondary small">{{ $judul }}</div>
            <div class="fw-bold">{{ $nilai }}</div>
          </div>
        </div>
      @endforeach
    </div>
    <div class="form-hint mt-2">
      <b>Pendapatan kas</b> berbeda dari <b>nilai penjualan</b>: penjualan piutang menambah nilai
      penjualan hari ini tapi kasnya baru masuk saat dicicil. Menyamakan keduanya membuat laporan
      kas memuat uang yang belum diterima.
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Barang</th><th>Terjual</th><th>Omzet</th><th>Modal</th><th>Untung</th></tr></thead>
      <tbody>
        @forelse ($untungBarang as $u)
          <tr>
            <td class="font-monospace small">{{ $u->code }}</td>
            <td class="small">{{ $u->name }}</td>
            <td class="small">{{ $u->terjual }}</td>
            <td class="small">{{ number_format((float) $u->omzet, 0, ',', '.') }}</td>
            <td class="small">{{ number_format((float) $u->modal, 0, ',', '.') }}</td>
            <td class="small {{ (float) $u->untung < 0 ? 'text-danger' : '' }}"><b>{{ number_format((float) $u->untung, 0, ',', '.') }}</b></td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada penjualan pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3 {{ $piutang->isNotEmpty() ? 'border-warning' : '' }}">
  <div class="card-header">
    <h3 class="card-title">Piutang Belum Lunas</h3>
    @if ($piutang->isNotEmpty())
      <span class="badge bg-yellow-lt ms-2">{{ $piutang->count() }}</span>
    @endif
  </div>
  <div class="card-body">
    <div class="form-hint mb-0">
      Sisa piutang <b>dihitung</b> dari total dikurangi seluruh pembayaran dan retur yang tidak
      mengembalikan kas &mdash; `tokopiutang.sisapiutang` Khanza adalah saldo yang menempel pada
      notanya, dan saldo tanpa buku pembayaran akan melenceng begitu satu cicilan gagal di tengah.
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Nota</th><th>Pembeli</th><th>Total</th><th>Sisa</th><th>Jatuh Tempo</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($piutang as $p)
          <tr>
            <td class="font-monospace small">{{ $p->sale_number }}</td>
            <td class="small">{{ $p->buyer_name ?: ($p->member->name ?? '—') }}</td>
            <td class="small">{{ number_format((float) $p->total, 0, ',', '.') }}</td>
            <td class="small"><b>{{ number_format($p->sisaPiutang(), 0, ',', '.') }}</b></td>
            <td class="small {{ $p->terlambatBayar() ? 'text-danger' : '' }}">{{ $p->due_on?->format('d-m-Y') ?: '—' }}</td>
            <td>
              <form method="POST" action="{{ route('retail.penjualan.bayar', $p) }}" class="d-flex gap-1">
                @csrf
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" style="width:9rem" value="{{ $p->sisaPiutang() }}">
                <button class="btn btn-sm btn-primary">Bayar</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada piutang terbuka.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Kasir</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      <b>Tunai dan piutang satu tabel</b>, dibedakan cara bayarnya. Khanza memisahkannya jadi dua
      tabel; laporan penjualan yang lupa salah satunya menjawab dengan tenang dengan angka yang
      lebih kecil daripada kenyataannya.
    </div>
    <form method="POST" action="{{ route('retail.penjualan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2">
        <label class="form-label">Cara Bayar</label>
        <select name="payment_type" class="form-select" required>
          <option value="tunai">Tunai</option>
          <option value="piutang">Piutang</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Member</label>
        <select name="member_id" class="form-select">
          <option value="">&mdash; umum &mdash;</option>
          @foreach ($member as $m)
            <option value="{{ $m->id }}">{{ $m->member_number }} &middot; {{ $m->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Tingkat Harga</label>
        <select name="price_tier_id" class="form-select">
          <option value="">&mdash; ikut member &mdash;</option>
          @foreach ($tingkat as $t)
            <option value="{{ $t->id }}">{{ $t->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Nama Pembeli</label><input type="text" name="buyer_name" class="form-control"></div>
      <div class="col-6 col-md-2"><label class="form-label">Potongan Nota</label><input type="number" step="0.01" min="0" name="discount" class="form-control" value="0"></div>

      <div class="col-6 col-md-2"><label class="form-label">Uang Muka</label><input type="number" step="0.01" min="0" name="down_payment" class="form-control" value="0"></div>
      <div class="col-6 col-md-2"><label class="form-label">Jatuh Tempo</label><input type="date" name="due_on" class="form-control"></div>
      <div class="col-12 col-md-8">
        <label class="form-label">Catatan</label>
        <input type="text" name="note" class="form-control">
        <div class="form-hint">Uang muka dan jatuh tempo hanya berlaku untuk penjualan piutang.</div>
      </div>

      @foreach (range(0, 4) as $i)
        <div class="col-8 col-md-5">
          <select name="items[{{ $i }}][product_id]" class="form-select form-select-sm">
            <option value="">&mdash; barang {{ $i + 1 }} &mdash;</option>
            @foreach ($produk as $p)
              <option value="{{ $p->id }}">{{ $p->name }} (stok {{ $saldo[$p->id] ?? 0 }})</option>
            @endforeach
          </select>
        </div>
        <div class="col-4 col-md-2"><input type="number" min="1" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" placeholder="Jumlah"></div>
      @endforeach

      <div class="col-12"><button class="btn btn-primary">Simpan Penjualan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Member</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('retail.penjualan.member.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Nomor</label><input type="text" name="member_number" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Tingkat Harga</label>
        <select name="default_price_tier_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($tingkat as $t)
            <option value="{{ $t->id }}">{{ $t->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Telepon</label><input type="text" name="phone" class="form-control"></div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">+</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat Penjualan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Nota</th><th>Waktu</th><th>Pembeli</th><th>Cara Bayar</th><th>Total</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($penjualan as $j)
          <tr>
            <td class="font-monospace small">{{ $j->sale_number }}</td>
            <td class="small">{{ $j->sold_at->format('d-m-Y H:i') }}</td>
            <td class="small">{{ $j->buyer_name ?: '—' }}<div class="text-secondary">{{ $j->priceTier->name ?? '' }}</div></td>
            <td><span class="badge bg-{{ $j->payment_type === 'tunai' ? 'green' : 'yellow' }}-lt">{{ $j->payment_type }}</span></td>
            <td class="small">{{ number_format((float) $j->total, 0, ',', '.') }}</td>
            <td>
              @php $warna = ['lunas' => 'green', 'sebagian' => 'yellow', 'belum' => 'red'][$j->payment_status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $j->payment_status }}</span>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#retur-{{ $j->id }}">Retur</button>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada penjualan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($penjualan as $j)
  <div class="modal fade" id="retur-{{ $j->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('retail.penjualan.retur', $j) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Retur {{ $j->sale_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="form-hint mb-2">
            @if ($j->payment_type === 'piutang')
              Nota ini piutang &mdash; returnya <b>mengurangi tagihan</b>, bukan mengeluarkan kas.
              Menyamakannya dengan retur tunai membuat kas tercatat keluar untuk uang yang belum
              pernah masuk.
            @else
              Retur tidak boleh melebihi yang dijual: retur lebih banyak daripada yang pernah dibeli
              berarti barang dari tempat lain masuk ke stok sambil uangnya keluar dari kas.
            @endif
          </div>
          <div class="mb-2"><label class="form-label">Alasan</label><input type="text" name="reason" class="form-control" required></div>
          @php $sisa = $j->sisaBisaDiretur(); @endphp
          @foreach ($j->items as $i => $baris)
            <div class="row g-1 align-items-end mb-1">
              <div class="col-7"><span class="small">{{ $baris->product->name ?? '?' }}</span>
                <div class="text-secondary small">bisa diretur {{ $sisa[$baris->product_id] ?? 0 }}</div>
              </div>
              <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $baris->product_id }}">
              <div class="col-5"><input type="number" min="0" max="{{ $sisa[$baris->product_id] ?? 0 }}" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" value="0"></div>
            </div>
          @endforeach
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Catat Retur</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
