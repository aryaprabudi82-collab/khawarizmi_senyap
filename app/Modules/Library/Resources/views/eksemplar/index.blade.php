@extends('layouts.app')

@section('title', 'Perpustakaan — Eksemplar')
@section('breadcrumb', 'Konteks library')
@section('heading', 'Eksemplar Fisik')

@section('actions')
  <a href="{{ route('library.index') }}" class="btn btn-link">&larr; Katalog</a>
  @can('peminjaman_perpustakaan')
    <a href="{{ route('library.sirkulasi.index') }}" class="btn btn-link">Sirkulasi &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Daftarkan Eksemplar</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Satu judul bisa punya banyak eksemplar; yang dipinjam adalah <b>eksemplar</b>, bukan judul.
      Hanya koleksi cetak yang muncul di sini &mdash; ebook tidak punya eksemplar fisik.
    </div>
    <form method="POST" action="{{ route('library.eksemplar.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Nomor Inventaris</label><input type="text" name="inventory_number" class="form-control" required></div>
      <div class="col-12 col-md-5">
        <label class="form-label">Koleksi</label>
        <select name="collection_id" class="form-select" required>
          @foreach ($koleksi as $k)
            <option value="{{ $k->id }}">{{ $k->code }} &middot; {{ $k->title }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Asal</label>
        <select name="acquisition" class="form-select" required>
          @foreach ($asal as $a)
            <option value="{{ $a }}">{{ ucfirst($a) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Tanggal Perolehan</label><input type="date" name="acquired_at" class="form-control"></div>
      <div class="col-6 col-md-1"><label class="form-label">Harga</label><input type="number" step="0.01" min="0" name="price" class="form-control"></div>

      <div class="col-6 col-md-2">
        <label class="form-label">Kondisi</label>
        <select name="condition" class="form-select" required>
          @foreach ($kondisi as $k)
            <option value="{{ $k }}">{{ ucfirst($k) }}</option>
          @endforeach
        </select>
        <div class="form-hint">Kondisi FISIK saja &mdash; "sedang dipinjam" dihitung, tidak disimpan.</div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Ruang</label>
        <select name="room_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($ruang as $r)
            <option value="{{ $r->id }}">{{ $r->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-3 col-md-1"><label class="form-label">Rak</label><input type="text" name="shelf_no" class="form-control"></div>
      <div class="col-3 col-md-1"><label class="form-label">Boks</label><input type="text" name="box_no" class="form-control"></div>
      <div class="col-12 col-md-5"><label class="form-label">Catatan Kondisi</label><input type="text" name="condition_note" class="form-control"></div>

      <div class="col-12"><button class="btn btn-primary">Daftarkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Eksemplar</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Judul</th><th>Lokasi</th><th>Kondisi</th><th>Sirkulasi</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($eksemplar as $e)
          <tr>
            <td class="font-monospace small">{{ $e->inventory_number }}</td>
            <td class="small">{{ $e->collection->title }}</td>
            <td class="small text-secondary">{{ $e->room?->name ?: '—' }}{{ $e->shelf_no ? ' / rak '.$e->shelf_no : '' }}</td>
            <td><span class="badge bg-{{ $e->condition === 'baik' ? 'green' : 'red' }}-lt">{{ $e->condition }}</span></td>
            <td>
              {{-- Dihitung dari peminjaman yang belum kembali, bukan dari kolom status. --}}
              @if ($e->sedangDipinjam())
                <span class="badge bg-blue-lt">dipinjam</span>
              @else
                <span class="text-secondary small">di rak</span>
              @endif
            </td>
            <td>
              <form method="POST" action="{{ route('library.eksemplar.kondisi', $e) }}" class="d-flex gap-1">
                @csrf
                <select name="condition" class="form-select form-select-sm" style="width:7rem">
                  @foreach ($kondisi as $k)
                    <option value="{{ $k }}" @selected($e->condition === $k)>{{ ucfirst($k) }}</option>
                  @endforeach
                </select>
                <input type="text" name="condition_note" class="form-control form-control-sm" value="{{ $e->condition_note }}" placeholder="Catatan">
                <button class="btn btn-sm btn-outline-primary">Simpan</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada eksemplar.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
