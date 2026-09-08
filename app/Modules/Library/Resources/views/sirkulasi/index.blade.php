@extends('layouts.app')

@section('title', 'Perpustakaan — Sirkulasi')
@section('breadcrumb', 'Konteks library')
@section('heading', 'Meja Sirkulasi')

@section('actions')
  @can('inventaris_perpustakaan')
    <a href="{{ route('library.eksemplar.index') }}" class="btn btn-link">Eksemplar &rarr;</a>
  @endcan
  @can('anggota_perpustakaan')
    <a href="{{ route('library.anggota.index') }}" class="btn btn-link">Anggota &rarr;</a>
  @endcan
  @can('set_peminjaman_perpustakaan')
    <a href="{{ route('library.pengaturan.index') }}" class="btn btn-link">Pengaturan &rarr;</a>
  @endcan
@endsection

@section('content')

{{--
  Yang lewat tempo tampil TERPISAH dan di atas. Daftar terbaru justru
  menyembunyikan buku yang paling lama tidak kembali — padahal itulah yang
  perlu dikejar.
--}}
<div class="card mb-3 {{ $terlambat->isNotEmpty() ? 'border-warning' : '' }}">
  <div class="card-header">
    <h3 class="card-title">Lewat Jatuh Tempo</h3>
    @if ($terlambat->isNotEmpty())
      <span class="badge bg-yellow-lt ms-2">{{ $terlambat->count() }}</span>
    @endif
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pinjam</th><th>Anggota</th><th>Judul</th><th>Jatuh Tempo</th><th>Terlambat</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($terlambat as $p)
          <tr>
            <td class="font-monospace small">{{ $p->loan_number }}</td>
            <td>{{ $p->member->name }}</td>
            <td class="small">{{ $p->item->collection->title }}<div class="text-secondary font-monospace">{{ $p->item->inventory_number }}</div></td>
            <td class="text-danger small">{{ $p->due_date->format('d-m-Y') }}</td>
            <td class="small">{{ $p->hariTerlambat() }} hari</td>
            <td>@include('library::sirkulasi._kembali', ['pinjaman' => $p, 'kondisi' => $kondisi])</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada pinjaman yang lewat tempo.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Pinjam</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Jatuh tempo dan tarif denda <b>dibekukan saat meminjam</b>. Mengubah lama pinjam nanti tidak
      menggeser jatuh tempo pinjaman yang sedang berjalan &mdash; kalau menggeser, buku yang kemarin
      terlambat mendadak jadi tepat waktu tanpa ada yang menyentuhnya.
    </div>
    <form method="POST" action="{{ route('library.sirkulasi.pinjam') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-5">
        <label class="form-label">Anggota</label>
        <select name="member_id" class="form-select" required>
          @foreach ($anggota as $a)
            <option value="{{ $a->id }}">{{ $a->member_number }} &middot; {{ $a->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Eksemplar Tersedia</label>
        <select name="item_id" class="form-select" required>
          @foreach ($tersedia as $e)
            <option value="{{ $e->id }}">{{ $e->inventory_number }} &middot; {{ $e->collection->title }}</option>
          @endforeach
        </select>
        <div class="form-hint">
          Hanya yang berkondisi baik dan tidak sedang dipinjam &mdash; keduanya dihitung, bukan
          dibaca dari kolom status pada eksemplar.
        </div>
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Pinjam</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Sedang Dipinjam</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pinjam</th><th>Anggota</th><th>Judul</th><th>Pinjam</th><th>Jatuh Tempo</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($berjalan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->loan_number }}</td>
            <td>{{ $p->member->name }}</td>
            <td class="small">{{ $p->item->collection->title }}<div class="text-secondary font-monospace">{{ $p->item->inventory_number }}</div></td>
            <td class="small">{{ $p->borrowed_at->format('d-m-Y') }}</td>
            <td class="small {{ $p->terlambat() ? 'text-danger' : '' }}">{{ $p->due_date->format('d-m-Y') }}</td>
            <td>@include('library::sirkulasi._kembali', ['pinjaman' => $p, 'kondisi' => $kondisi])</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada pinjaman berjalan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Denda</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Denda</th><th>Anggota</th><th>Jenis</th><th>Dasar</th><th>Jumlah</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($denda as $d)
          <tr>
            <td class="font-monospace small">{{ $d->fine_number }}</td>
            <td>{{ $d->member->name }}</td>
            <td><span class="badge bg-blue-lt">{{ $d->kind }}</span></td>
            <td class="small">
              @if ($d->days_late)
                {{ $d->days_late }} hari &times; {{ number_format((float) $d->loan?->policy_daily_fine, 0, ',', '.') }}
              @else
                &mdash;
              @endif
            </td>
            <td class="small">{{ number_format((float) $d->amount, 0, ',', '.') }}</td>
            <td>
              @if ($d->paid_at)
                <span class="badge bg-green-lt">dibayar</span>
              @elseif ($d->waived_at)
                <span class="badge bg-secondary-lt">dibebaskan</span>
                <div class="small text-secondary">{{ $d->waived_reason }}</div>
              @else
                <span class="badge bg-yellow-lt">tertunggak</span>
              @endif
            </td>
            <td>
              @if ($d->tertunggak())
                <form method="POST" action="{{ route('library.sirkulasi.denda.selesai', $d) }}" class="d-flex gap-1">
                  @csrf
                  <select name="tindakan" class="form-select form-select-sm" style="width:7.5rem">
                    <option value="bayar">Bayar</option>
                    <option value="bebaskan">Bebaskan</option>
                  </select>
                  <input type="number" step="0.01" min="0" name="paid_amount" class="form-control form-control-sm"
                         style="width:8rem" value="{{ (float) $d->amount }}">
                  <input type="text" name="waived_reason" class="form-control form-control-sm"
                         placeholder="Alasan (wajib bila dibebaskan)">
                  <button class="btn btn-sm btn-primary">Simpan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada denda.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat Pengembalian</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Pinjam</th><th>Anggota</th><th>Judul</th><th>Jatuh Tempo</th><th>Kembali</th><th>Terlambat</th><th>Kondisi</th></tr></thead>
      <tbody>
        @forelse ($riwayat as $p)
          <tr>
            <td class="font-monospace small">{{ $p->loan_number }}</td>
            <td>{{ $p->member->name }}</td>
            <td class="small">{{ $p->item->collection->title }}</td>
            {{-- Dua kolom yang berbeda: satu kolom `tgl_kembali` seperti Khanza
                 hanya bisa menjawab salah satunya, padahal dendanya selisih keduanya. --}}
            <td class="small">{{ $p->due_date->format('d-m-Y') }}</td>
            <td class="small">{{ $p->returned_at->format('d-m-Y') }}</td>
            <td class="small">{{ $p->hariTerlambat() ?: '—' }}</td>
            <td>
              <span class="badge bg-{{ $p->returned_condition === 'baik' ? 'green' : 'red' }}-lt">{{ $p->returned_condition }}</span>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada pengembalian.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
