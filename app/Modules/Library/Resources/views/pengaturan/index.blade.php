@extends('layouts.app')

@section('title', 'Perpustakaan — Pengaturan')
@section('breadcrumb', 'Konteks library')
@section('heading', 'Pengaturan Peminjaman & Denda')

@section('actions')
  @can('peminjaman_perpustakaan')
    <a href="{{ route('library.sirkulasi.index') }}" class="btn btn-link">&larr; Sirkulasi</a>
  @endcan
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Aturan Peminjaman</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Menyimpan aturan baru <b>menonaktifkan yang lama, bukan menimpanya</b>. Khanza menyimpannya
      sebagai satu baris tanpa kunci, jadi pertanyaan &ldquo;aturan mana yang berlaku waktu itu&rdquo;
      tidak punya jawaban. Aturan yang berlaku saat meminjam juga <b>dibekukan ke pinjamannya</b>,
      sehingga perubahan di sini tidak menggeser jatuh tempo pinjaman yang sedang berjalan &mdash;
      kalau menggeser, buku yang kemarin terlambat mendadak jadi tepat waktu.
    </div>
    @if ($aktif)
      <div class="alert alert-info">
        Berlaku sejak {{ $aktif->effective_from->format('d-m-Y') }}:
        maksimal <b>{{ $aktif->max_items }}</b> eksemplar,
        lama pinjam <b>{{ $aktif->loan_days }}</b> hari,
        denda <b>{{ number_format((float) $aktif->daily_fine, 0, ',', '.') }}</b> per hari.
      </div>
    @else
      <div class="alert alert-warning">
        Belum ada pengaturan. Lama pinjam dan tarif denda harus ada <b>sebelum</b> ada yang
        meminjam, karena keduanya dibekukan ke pinjamannya.
      </div>
    @endif
    <form method="POST" action="{{ route('library.pengaturan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3"><label class="form-label">Batas Eksemplar</label><input type="number" min="1" name="max_items" class="form-control" value="{{ $aktif->max_items ?? 2 }}" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Lama Pinjam (hari)</label><input type="number" min="1" name="loan_days" class="form-control" value="{{ $aktif->loan_days ?? 7 }}" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Denda per Hari</label><input type="number" step="0.01" min="0" name="daily_fine" class="form-control" value="{{ (float) ($aktif->daily_fine ?? 0) }}" required></div>
      <div class="col-6 col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">Berlakukan</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Berlaku Sejak</th><th>Batas</th><th>Lama Pinjam</th><th>Denda/Hari</th><th>Status</th></tr></thead>
      <tbody>
        @foreach ($riwayat as $r)
          <tr>
            <td class="small">{{ $r->effective_from->format('d-m-Y') }}</td>
            <td class="small">{{ $r->max_items }}</td>
            <td class="small">{{ $r->loan_days }} hari</td>
            <td class="small">{{ number_format((float) $r->daily_fine, 0, ',', '.') }}</td>
            <td>
              @if ($r->is_active)
                <span class="badge bg-green-lt">berlaku</span>
              @else
                <span class="badge bg-secondary-lt">riwayat</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Jenis Denda</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Untuk denda <b>selain keterlambatan</b> &mdash; kerusakan, kehilangan. Daftarnya
      <b>sengaja lahir kosong</b>: besarannya diskresi RSP UI, dan menebaknya berarti menagih
      pemustaka dengan angka yang tidak pernah disepakati siapa pun. Denda keterlambatan tidak
      menunggu daftar ini: besarannya keluar dari tarif harian yang dibekukan pada pinjamannya.
    </div>
    <form method="POST" action="{{ route('library.pengaturan.denda.simpan') }}" class="row g-2">
      @csrf
      <div class="col-4 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-8 col-md-6"><label class="form-label">Jenis Denda</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-6 col-md-2"><label class="form-label">Besaran</label><input type="number" step="0.01" min="0" name="amount" class="form-control" required></div>
      <div class="col-6 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Jenis</th><th>Besaran</th></tr></thead>
      <tbody>
        @forelse ($jenisDenda as $d)
          <tr><td class="font-monospace small">{{ $d->code }}</td><td>{{ $d->name }}</td><td class="small">{{ number_format((float) $d->amount, 0, ',', '.') }}</td></tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Belum ditetapkan RSP UI.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
