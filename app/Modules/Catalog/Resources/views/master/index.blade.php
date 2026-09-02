@extends('layouts.app')

@section('title', 'Data Master — Layanan & Tarif')
@section('breadcrumb', 'Konteks catalog')
@section('heading', 'Layanan, Penjamin & Tarif')

@section('actions')
  <a href="{{ route('master.organisasi') }}" class="btn btn-link">Unit &amp; Praktisi &rarr;</a>
@endsection

@section('content')

<div class="row g-3">

  {{-- Penjamin --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Penjamin</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($penjamin as $p)
              <tr>
                <td class="font-monospace small">{{ $p->code }}</td>
                <td>{{ $p->name }}</td>
                <td><span class="badge bg-secondary-lt">{{ $p->kind }}</span></td>
                <td>
                  @if ($p->is_active)
                    <span class="badge bg-green-lt">Aktif</span>
                  @else
                    <span class="badge bg-red-lt">Nonaktif</span>
                  @endif
                </td>
                <td>
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-penjamin-{{ $p->id }}">Ubah</button>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada penjamin.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('master.penjamin.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-4"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama penjamin" required></div>
          <div class="col-3">
            <select name="kind" class="form-select form-select-sm">
              <option value="umum">Umum</option>
              <option value="bpjs">BPJS</option>
              <option value="asuransi">Asuransi</option>
              <option value="perusahaan">Perusahaan</option>
            </select>
          </div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Layanan --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Layanan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($layanan as $l)
              <tr>
                <td class="font-monospace small">{{ $l->code }}</td>
                <td>{{ $l->name }}</td>
                <td><span class="badge bg-secondary-lt">{{ $l->category }}</span></td>
                <td>
                  @if ($l->is_active)
                    <span class="badge bg-green-lt">Aktif</span>
                  @else
                    <span class="badge bg-red-lt">Nonaktif</span>
                  @endif
                </td>
                <td>
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-layanan-{{ $l->id }}">Ubah</button>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada layanan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('master.layanan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-4"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama layanan" required></div>
          <div class="col-3">
            <select name="category" class="form-select form-select-sm">
              <option value="registrasi">Registrasi</option>
              <option value="konsultasi">Konsultasi</option>
              <option value="tindakan">Tindakan</option>
              <option value="penunjang">Penunjang</option>
            </select>
          </div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

{{-- Tarif --}}
<div class="card">
  <div class="card-header"><h3 class="card-title">Tarif Berlaku</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr><th>Layanan</th><th>Penjamin</th><th>Kelas</th><th class="text-end">Tarif</th><th class="text-end">Pasien Lama</th><th>Berlaku Sejak</th></tr>
      </thead>
      <tbody>
        @forelse ($tarif as $t)
          <tr>
            <td>{{ $t->service->name }}</td>
            <td>{{ $t->payer->name }}</td>
            <td>{{ $t->care_class }}</td>
            <td class="text-end">Rp {{ number_format((float) $t->amount, 0, ',', '.') }}</td>
            <td class="text-end text-secondary">
              {{ $t->amount_returning !== null ? 'Rp ' . number_format((float) $t->amount_returning, 0, ',', '.') : '—' }}
            </td>
            <td class="text-secondary small">{{ $t->valid_from->format('d-m-Y') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada tarif ditetapkan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="card-body border-top">
    <form method="POST" action="{{ route('master.tarif.simpan') }}" class="row g-2 align-items-end">
      @csrf
      <div class="col-12 col-md-3">
        <label class="form-label">Layanan</label>
        <select name="service_id" class="form-select" required>
          @foreach ($layanan as $l)
            <option value="{{ $l->id }}">{{ $l->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Penjamin</label>
        <select name="payer_id" class="form-select" required>
          @foreach ($penjamin as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Kelas</label>
        <input type="text" name="care_class" class="form-control" value="-">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Tarif (Rp)</label>
        <input type="number" step="1" min="0" name="amount" class="form-control" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Berlaku sejak</label>
        <input type="date" name="valid_from" class="form-control" value="{{ now()->toDateString() }}" required>
      </div>
      <div class="col-12 col-md-1">
        <button class="btn btn-primary w-100">Simpan</button>
      </div>
      <div class="col-12">
        <div class="form-hint">
          Tarif lama untuk kombinasi layanan-penjamin-kelas yang sama otomatis ditutup, bukan ditimpa —
          kunjungan lama tetap dihitung dengan tarif yang berlaku saat itu.
        </div>
      </div>
    </form>
  </div>
</div>

@foreach ($penjamin as $p)
  <div class="modal fade" id="edit-penjamin-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('master.penjamin.perbarui', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Penjamin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $p->name }}" required></div>
          <div class="mb-2">
            <label class="form-label">Jenis</label>
            <select name="kind" class="form-select">
              @foreach (['umum','bpjs','asuransi','perusahaan'] as $k)
                <option value="{{ $k }}" @selected($p->kind === $k)>{{ $k }}</option>
              @endforeach
            </select>
          </div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($p->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@foreach ($layanan as $l)
  <div class="modal fade" id="edit-layanan-{{ $l->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('master.layanan.perbarui', $l) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ubah Layanan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ $l->name }}" required></div>
          <div class="mb-2">
            <label class="form-label">Kategori</label>
            <select name="category" class="form-select">
              @foreach (['registrasi','konsultasi','tindakan','penunjang'] as $k)
                <option value="{{ $k }}" @selected($l->category === $k)>{{ $k }}</option>
              @endforeach
            </select>
          </div>
          <label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" @checked($l->is_active)><span class="form-check-label">Aktif</span></label>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
