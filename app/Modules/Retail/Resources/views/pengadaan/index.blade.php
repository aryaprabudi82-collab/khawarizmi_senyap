@extends('layouts.app')

@section('title', 'Toko — Pengadaan')
@section('breadcrumb', 'Konteks retail')
@section('heading', 'Pengadaan, Penerimaan &amp; Hutang Toko')

@section('actions')
  @can('toko_barang')
    <a href="{{ route('retail.index') }}" class="btn btn-link">&larr; Barang &amp; Harga</a>
  @endcan
  @can('stok_opname_toko')
    <a href="{{ route('retail.stok.index') }}" class="btn btn-link">Stok &amp; Opname &rarr;</a>
  @endcan
@endsection

@section('content')

{{--
  Hutang tampil TERPISAH dan di atas: daftar penerimaan terbaru justru
  menyembunyikan nota lama yang belum dibayar, padahal itulah yang jatuh
  tempo lebih dulu.
--}}
<div class="card mb-3 {{ $hutang->isNotEmpty() ? 'border-warning' : '' }}">
  <div class="card-header">
    <h3 class="card-title">Hutang ke Suplier</h3>
    @if ($hutang->isNotEmpty())
      <span class="badge bg-yellow-lt ms-2">{{ $hutang->count() }}</span>
    @endif
  </div>
  <div class="card-body">
    <div class="form-hint mb-0">
      Sisa hutang <b>dihitung</b> dari selisih nilai penerimaan dan yang sudah dibayar &mdash; saldo
      hutang yang disimpan sebagai kolom akan melenceng begitu satu pembayaran gagal di tengah, dan
      yang tertinggal cuma angka yang tidak bisa ditelusuri ke nota mana pun.
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Terima</th><th>Suplier</th><th>Nilai</th><th>Terbayar</th><th>Sisa</th><th>Jatuh Tempo</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($hutang as $h)
          <tr>
            <td class="font-monospace small">{{ $h->receipt_number }}</td>
            <td class="small">{{ $h->order->supplier->name ?? '—' }}</td>
            <td class="small">{{ number_format((float) $h->total_amount, 0, ',', '.') }}</td>
            <td class="small">{{ number_format((float) $h->paid_amount, 0, ',', '.') }}</td>
            <td class="small"><b>{{ number_format($h->sisaHutang(), 0, ',', '.') }}</b></td>
            <td class="small {{ $h->terlambatBayar() ? 'text-danger' : '' }}">{{ $h->due_on?->format('d-m-Y') ?: '—' }}</td>
            <td>
              <form method="POST" action="{{ route('retail.pengadaan.penerimaan.bayar', $h) }}" class="d-flex gap-1">
                @csrf
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" style="width:9rem" value="{{ $h->sisaHutang() }}">
                <input type="text" name="method" class="form-control form-control-sm" style="width:7rem" value="tunai">
                <button class="btn btn-sm btn-primary">Bayar</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada hutang terbuka.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Pengajuan Barang</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      <b>Penolakan wajib beralasan, persetujuan tidak</b> &mdash; pengajuan yang disetujui berbukti
      pada pesanan yang lahir sesudahnya; yang ditolak tidak meninggalkan apa pun selain catatan itu.
    </div>
    <form method="POST" action="{{ route('retail.pengadaan.pengajuan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4"><label class="form-label">Pemohon</label><input type="text" name="requested_by_name" class="form-control" required></div>
      <div class="col-12 col-md-8"><label class="form-label">Keperluan</label><input type="text" name="purpose" class="form-control"></div>
      @foreach (range(0, 3) as $i)
        <div class="col-8 col-md-5">
          <select name="items[{{ $i }}][product_id]" class="form-select form-select-sm">
            <option value="">&mdash; barang {{ $i + 1 }} &mdash;</option>
            @foreach ($produk as $p)
              <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-4 col-md-2"><input type="number" min="1" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" placeholder="Jumlah"></div>
      @endforeach
      <div class="col-12"><button class="btn btn-primary">Ajukan</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pemohon</th><th>Barang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pengajuan as $a)
          <tr>
            <td class="font-monospace small">{{ $a->requisition_number }}</td>
            <td class="small">{{ $a->requested_by_name }}</td>
            <td class="small">{{ $a->items->map(fn ($i) => ($i->product->name ?? '?').' ×'.$i->quantity)->implode(', ') }}</td>
            <td>
              @php $warna = ['diajukan' => 'yellow', 'disetujui' => 'green', 'ditolak' => 'red', 'diproses' => 'blue'][$a->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $a->status }}</span>
              @if ($a->decision_note)<div class="small text-secondary">{{ $a->decision_note }}</div>@endif
            </td>
            <td>
              @if ($a->status === 'diajukan')
                <form method="POST" action="{{ route('retail.pengadaan.pengajuan.putuskan', $a) }}" class="d-flex gap-1">
                  @csrf
                  <select name="keputusan" class="form-select form-select-sm" style="width:7rem">
                    <option value="setuju">Setujui</option>
                    <option value="tolak">Tolak</option>
                  </select>
                  <input type="text" name="decision_note" class="form-control form-control-sm" placeholder="Alasan (wajib bila ditolak)">
                  <button class="btn btn-sm btn-primary">Simpan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada pengajuan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Pesanan ke Suplier</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('retail.pengadaan.pesanan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Suplier</label>
        <select name="supplier_id" class="form-select" required>
          @foreach ($suplier as $s)
            <option value="{{ $s->id }}">{{ $s->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Dari Pengajuan (opsional)</label>
        <select name="requisition_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($pengajuan->where('status', 'disetujui') as $a)
            <option value="{{ $a->id }}">{{ $a->requisition_number }}</option>
          @endforeach
        </select>
        <div class="form-hint">Hanya pengajuan yang sudah disetujui.</div>
      </div>
      <div class="col-6 col-md-4"><label class="form-label">Perkiraan Datang</label><input type="date" name="expected_on" class="form-control"></div>
      @foreach (range(0, 3) as $i)
        <div class="col-6 col-md-5">
          <select name="items[{{ $i }}][product_id]" class="form-select form-select-sm">
            <option value="">&mdash; barang {{ $i + 1 }} &mdash;</option>
            @foreach ($produk as $p)
              <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-3 col-md-2"><input type="number" min="1" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" placeholder="Jumlah"></div>
        <div class="col-3 col-md-2"><input type="number" step="0.01" min="0" name="items[{{ $i }}][unit_cost]" class="form-control form-control-sm" placeholder="Harga"></div>
      @endforeach
      <div class="col-12"><button class="btn btn-primary">Buat Pesanan</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pesanan</th><th>Suplier</th><th>Barang</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($pesanan as $o)
          <tr>
            <td class="font-monospace small">{{ $o->order_number }}</td>
            <td class="small">{{ $o->supplier->name }}</td>
            <td class="small">{{ $o->items->map(fn ($i) => ($i->product->name ?? '?').' ×'.$i->quantity)->implode(', ') }}</td>
            <td>
              @php $warna = ['draft' => 'secondary', 'dikirim' => 'yellow', 'sebagian' => 'blue', 'diterima' => 'green', 'dibatalkan' => 'red'][$o->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $o->status }}</span>
            </td>
            <td>
              <div class="d-flex gap-1">
                {{-- Surat pemesanan: tampilan cetak pesanan yang sama, bukan entitas kedua. --}}
                <a href="{{ route('retail.pengadaan.pesanan.surat', $o) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Surat</a>
                @if ($o->status === 'draft')
                  <form method="POST" action="{{ route('retail.pengadaan.pesanan.kirim', $o) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary">Kirim</button>
                  </form>
                @elseif (in_array($o->status, ['dikirim', 'sebagian'], true))
                  <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#terima-{{ $o->id }}">Terima</button>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada pesanan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Retur ke Suplier</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Alasan <b>wajib</b>: tanpa alasan, retur tidak bisa dibedakan dari barang yang hilang lalu
      dicatat sebagai retur.
    </div>
    <form method="POST" action="{{ route('retail.pengadaan.retur.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Suplier</label>
        <select name="supplier_id" class="form-select" required>
          @foreach ($suplier as $s)
            <option value="{{ $s->id }}">{{ $s->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-8"><label class="form-label">Alasan</label><input type="text" name="reason" class="form-control" required></div>
      @foreach (range(0, 2) as $i)
        <div class="col-8 col-md-5">
          <select name="items[{{ $i }}][product_id]" class="form-select form-select-sm">
            <option value="">&mdash; barang {{ $i + 1 }} &mdash;</option>
            @foreach ($produk as $p)
              <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-4 col-md-2"><input type="number" min="1" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" placeholder="Jumlah"></div>
      @endforeach
      <div class="col-12"><button class="btn btn-primary">Catat Retur</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Retur</th><th>Suplier</th><th>Barang</th><th>Alasan</th><th>Nilai</th></tr></thead>
      <tbody>
        @forelse ($retur as $r)
          <tr>
            <td class="font-monospace small">{{ $r->return_number }}</td>
            <td class="small">{{ $r->supplier->name }}</td>
            <td class="small">{{ $r->items->map(fn ($i) => ($i->product->name ?? '?').' ×'.$i->quantity)->implode(', ') }}</td>
            <td class="small">{{ $r->reason }}</td>
            <td class="small">{{ number_format((float) $r->total_amount, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada retur.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($pesanan as $o)
  @if (in_array($o->status, ['dikirim', 'sebagian'], true))
    <div class="modal fade" id="terima-{{ $o->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" method="POST" action="{{ route('retail.pengadaan.pesanan.terima', $o) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Terima {{ $o->order_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="form-hint mb-2">
              Penerimaan <b>tidak boleh melebihi sisa pesanan</b>. Barang yang datang lebih banyak
              daripada yang dipesan berarti pesanannya salah dicatat, atau ada kiriman yang tidak
              pernah dipesan siapa pun &mdash; keduanya harus berhenti di meja penerimaan.
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6"><label class="form-label">No. Faktur Suplier</label><input type="text" name="supplier_invoice_number" class="form-control"></div>
              <div class="col-6"><label class="form-label">Jatuh Tempo Bayar</label><input type="date" name="due_on" class="form-control"></div>
            </div>
            @php $sisa = $o->sisaPerProduk(); @endphp
            @foreach ($o->items as $i => $baris)
              <div class="row g-1 align-items-end mb-1">
                <div class="col-7"><span class="small">{{ $baris->product->name ?? '?' }}</span>
                  <div class="text-secondary small">sisa {{ $sisa[$baris->product_id] ?? 0 }}</div>
                </div>
                <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $baris->product_id }}">
                <div class="col-5"><input type="number" min="0" max="{{ $sisa[$baris->product_id] ?? 0 }}" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" value="{{ $sisa[$baris->product_id] ?? 0 }}"></div>
              </div>
            @endforeach
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Catat Penerimaan</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
