@extends('layouts.app')

@section('title', 'Data Pegawai')
@section('breadcrumb', 'Konteks organization')
@section('heading', 'Data Pegawai')

@section('content')

{{--
  Kartu ringkasan per kategori. Ditaruh di kepala layar karena pertanyaan
  pertama tentang daftar ketenagaan hampir selalu "berapa banyak", dan
  menjawabnya dengan menyuruh orang menghitung baris adalah pekerjaan yang
  tidak perlu ada.
--}}
<div class="row row-cards mb-3">
  @foreach ($ringkasan as $kode => $jumlah)
    <div class="col-6 col-md-3">
      <a href="{{ route('pegawai.index', ['kategori' => $kode]) }}"
         class="card card-sm text-decoration-none {{ $kategori === $kode ? 'border-primary' : '' }}">
        <div class="card-body">
          <div class="h1 mb-0">{{ number_format($jumlah, 0, ',', '.') }}</div>
          <div class="text-secondary small">{{ $daftarKategori[$kode] }}</div>
        </div>
      </a>
    </div>
  @endforeach
</div>

<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2">
      <div class="col-12 col-md">
        <input type="search" name="cari" class="form-control" value="{{ $cari }}"
               placeholder="Cari nama, NIP, jabatan, atau unit kerja">
      </div>

      <div class="col-6 col-md-auto">
        <select name="kategori" class="form-select">
          <option value="">Semua kategori</option>
          @foreach ($daftarKategori as $kode => $label)
            <option value="{{ $kode }}" @selected($kategori === $kode)>{{ $label }}</option>
          @endforeach
        </select>
      </div>

      <div class="col-6 col-md-auto">
        <select name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach ($daftarStatus as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
          @endforeach
        </select>
      </div>

      <div class="col-12 col-md-auto">
        <select name="unit" class="form-select">
          <option value="">Semua unit kerja</option>
          @foreach ($daftarUnit as $u)
            <option value="{{ $u }}" @selected($unit === $u)>{{ Str::limit($u, 46) }}</option>
          @endforeach
        </select>
      </div>

      <div class="col-auto">
        <button class="btn btn-outline-primary">Cari</button>
      </div>

      @if ($cari !== '' || $kategori !== '' || $status !== '' || $unit !== '')
        <div class="col-auto">
          <a href="{{ route('pegawai.index') }}" class="btn btn-link">Reset</a>
        </div>
      @endif
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>Nama</th>
          <th>Kategori</th>
          <th>Jabatan</th>
          <th>Unit Kerja</th>
          <th>Status</th>
          <th>Spesialisasi</th>
          <th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($pegawai as $p)
          <tr>
            <td>
              <div class="fw-semibold">{{ $p->displayName() }}</div>
              @if ($p->employee_number)
                <div class="text-secondary font-monospace small">{{ $p->employee_number }}</div>
              @endif
            </td>

            <td>
              @php
                $warna = match ($p->category) {
                    'dokter' => 'bg-blue-lt',
                    'perawat' => 'bg-azure-lt',
                    'penunjang' => 'bg-yellow-lt',
                    default => 'bg-secondary-lt',
                };
              @endphp
              <span class="badge {{ $warna }}">{{ $p->categoryLabel() }}</span>

              {{-- Jenis penunjang hanya berarti bagi kategori penunjang. --}}
              @if ($p->support_type)
                <div class="text-secondary small mt-1">{{ $p->support_type }}</div>
              @endif
            </td>

            <td>{{ $p->position ?? '—' }}</td>
            <td class="text-secondary small">{{ $p->unit_name ?? '—' }}</td>

            <td class="small">
              {{ $p->employment_status ?? '—' }}
              @if ($p->entry_status)
                <div class="text-secondary">{{ $p->entry_status }}</div>
              @endif
            </td>

            <td class="text-secondary small">{{ $p->specialty ?? '—' }}</td>

            <td>
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#ubah-pegawai-{{ $p->id }}">Ubah</button>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="text-center text-secondary py-4">
              @if ($cari !== '' || $kategori !== '' || $status !== '' || $unit !== '')
                Tidak ada pegawai yang cocok dengan penyaring ini.
              @else
                Belum ada data pegawai.
              @endif
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex align-items-center justify-content-between">
    <span class="text-secondary small">
      {{ number_format($pegawai->total(), 0, ',', '.') }} pegawai
    </span>

    @if ($pegawai->hasPages())
      {{ $pegawai->links() }}
    @endif
  </div>
</div>

{{--
  Modal edit dibuat HANYA untuk baris yang tampil di halaman ini, bukan untuk
  seluruh 1.524 pegawai. Membuat semuanya sekaligus berarti menuliskan ribuan
  form tersembunyi ke setiap permintaan halaman — persoalan yang sama yang
  membuat layar master lama berat.
--}}
@foreach ($pegawai as $p)
  <div class="modal fade" id="ubah-pegawai-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('pegawai.perbarui', $p) }}">
        @csrf

        <div class="modal-header">
          <h5 class="modal-title">Ubah Data Pegawai</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="alert alert-warning" role="alert">
            <strong>NIP {{ $p->employee_number ?? '—' }}</strong> tidak bisa diubah dari sini —
            nomor itulah yang menautkan baris ini ke daftar kepegawaian.
            <div class="mt-1 small">
              Suntingan di layar ini akan <strong>tertimpa</strong> bila berkas SDM terbaru
              dimuat ulang, karena daftar kepegawaian adalah sumber kebenarannya.
              Untuk koreksi permanen, perbaiki di data SDM.
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Gelar</label>
              <input name="title" class="form-control" value="{{ $p->title }}" placeholder="dr., Ns., Apt.">
            </div>

            <div class="col-md-9">
              <label class="form-label required">Nama</label>
              <input name="name" class="form-control" value="{{ $p->name }}" required maxlength="150">
            </div>

            <div class="col-md-6">
              <label class="form-label required">Kategori</label>
              <select name="category" class="form-select" required>
                @foreach ($daftarKategori as $kode => $label)
                  <option value="{{ $kode }}" @selected($p->category === $kode)>{{ $label }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Jenis penunjang</label>
              <input name="support_type" class="form-control" value="{{ $p->support_type }}"
                     maxlength="30" placeholder="farmasi, radiologi, cssd, gizi…">
              <small class="form-hint">Hanya dipakai bila kategorinya Penunjang Pelayanan.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Jabatan</label>
              <input name="position" class="form-control" value="{{ $p->position }}" maxlength="120">
            </div>

            <div class="col-md-6">
              <label class="form-label">Unit kerja</label>
              <input name="unit_name" class="form-control" value="{{ $p->unit_name }}" maxlength="150">
            </div>

            <div class="col-md-6">
              <label class="form-label">Status kepegawaian</label>
              <input name="employment_status" class="form-control" value="{{ $p->employment_status }}" maxlength="60">
            </div>

            <div class="col-md-6">
              <label class="form-label">Jalur masuk</label>
              <input name="entry_status" class="form-control" value="{{ $p->entry_status }}"
                     maxlength="30" placeholder="PRSUI, PNS, PPPK…">
            </div>

            <div class="col-md-12">
              <label class="form-label">Spesialisasi</label>
              <input name="specialty" class="form-control" value="{{ $p->specialty }}" maxlength="100">
            </div>

            <div class="col-md-8">
              <label class="form-label">Nomor SIP</label>
              <input name="sip_number" class="form-control" value="{{ $p->sip_number }}" maxlength="120">
            </div>

            <div class="col-md-4">
              <label class="form-label">SIP berlaku sampai</label>
              <input type="date" name="sip_valid_until" class="form-control"
                     value="{{ $p->sip_valid_until?->format('Y-m-d') }}">
            </div>

            <div class="col-md-6">
              <label class="form-label">Telepon</label>
              <input name="phone" class="form-control" value="{{ $p->phone }}" maxlength="40">
            </div>

            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="{{ $p->email }}" maxlength="150">
            </div>

            <div class="col-12">
              <label class="form-check">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                       @checked($p->is_active)>
                <span class="form-check-label">Masih aktif bertugas</span>
              </label>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-link" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
@endforeach
@endsection
