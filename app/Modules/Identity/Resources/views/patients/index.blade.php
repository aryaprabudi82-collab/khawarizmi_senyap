@extends('layouts.app')

@section('title', 'Master Pasien')
@section('breadcrumb', 'Konteks identity')
@section('heading', 'Master Pasien')

@section('actions')
  <a href="{{ route('pasien.create') }}" class="btn btn-primary">Pasien Baru</a>
@endsection

@section('content')
<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2">
      <div class="col">
        <input type="search" name="cari" class="form-control" value="{{ $cari }}"
               placeholder="Cari nomor RM, NIK, nomor telepon, atau nama pasien">
      </div>
      <div class="col-auto"><button class="btn btn-outline-primary">Cari</button></div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>No. RM</th><th>Nama</th><th>NIK</th><th>L/P</th>
          <th>Tgl. Lahir</th><th>Telepon</th><th>Alamat</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($pasien as $p)
          <tr>
            <td class="font-monospace">{{ $p->medical_record_number }}</td>
            <td class="fw-semibold">{{ $p->name }}</td>
            <td class="font-monospace small">{{ $p->nik ?? '—' }}</td>
            <td>{{ $p->sex }}</td>
            <td>{{ $p->birth_date?->format('d-m-Y') ?? '—' }}</td>
            <td>{{ $p->phone ?? '—' }}</td>
            <td class="text-secondary small">{{ Str::limit($p->address, 40) ?: '—' }}</td>
            <td>
              <a class="btn btn-sm btn-outline-primary"
                 href="{{ route('registrasi.create', ['cari' => $p->medical_record_number, 'pasien_id' => $p->id]) }}">
                Daftarkan
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-secondary py-4">
            {{ $cari ? 'Tidak ada pasien yang cocok.' : 'Belum ada pasien terdaftar.' }}
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($pasien->hasPages())
    <div class="card-footer">{{ $pasien->links() }}</div>
  @endif
</div>
@endsection
