@extends('layouts.app')

@section('title', 'IGD/UGD')
@section('breadcrumb', 'Konteks encounter')
@section('heading', 'Instalasi Gawat Darurat')

@section('actions')
  <a href="{{ route('registrasi.index') }}" class="btn btn-link">Papan Antrean Ralan &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Daftarkan Pasien IGD</h3></div>
      <div class="card-body">
        <form method="GET" action="{{ route('igd.index') }}" class="mb-3">
          <label class="form-label">Cari Pasien</label>
          <div class="input-group">
            <input type="search" name="cari" class="form-control" value="{{ $cari }}" placeholder="Nomor RM, NIK, atau nama" autofocus>
            <button class="btn btn-primary">Cari</button>
          </div>
        </form>

        @if ($cari !== '')
          @if ($hasilCari->isEmpty())
            <div class="text-center py-3">
              <p class="text-secondary mb-3">Tidak ada pasien yang cocok dengan &ldquo;{{ $cari }}&rdquo;.</p>
              <a href="{{ route('pasien.create', ['nama' => $cari]) }}" class="btn btn-outline-primary">Daftarkan sebagai pasien baru</a>
            </div>
          @else
            <div class="list-group">
              @foreach ($hasilCari as $p)
                <div class="list-group-item">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <div class="fw-semibold">{{ $p->name }}</div>
                      <div class="small font-monospace text-secondary">{{ $p->medical_record_number }}</div>
                    </div>
                    <form method="POST" action="{{ route('igd.daftar') }}">
                      @csrf
                      <input type="hidden" name="pasien_id" value="{{ $p->id }}">
                      <select name="penjamin_id" class="form-select form-select-sm mb-1" required>
                        <option value="">— penjamin —</option>
                        @foreach ($penjamin as $pj)
                          <option value="{{ $pj->id }}">{{ $pj->name }}</option>
                        @endforeach
                      </select>
                      <button class="btn btn-sm btn-danger w-100">Daftarkan ke IGD</button>
                    </form>
                  </div>
                </div>
              @endforeach
            </div>
          @endif
        @endif
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Pasien di IGD Hari Ini</h3>
        <div class="card-actions text-secondary small">{{ $pasienIgd->count() }} pasien</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pasien</th><th>Triase</th><th>Keluhan</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($pasienIgd as $r)
              @php $t = $triase->get($r->id); @endphp
              <tr>
                <td>{{ $r->patient_name }}<div class="text-secondary small font-monospace">{{ $r->patient_mrn }}</div></td>
                <td>
                  @if ($t)
                    @php $warna = ['merah' => 'red', 'kuning' => 'yellow', 'hijau' => 'green', 'hitam' => 'dark'][$t->triage_level]; @endphp
                    <span class="badge bg-{{ $warna }}-lt text-uppercase">{{ $t->triage_level }}</span>
                  @else
                    <span class="badge bg-red text-white">Belum Ditriase</span>
                  @endif
                </td>
                <td class="text-secondary small">{{ $t->chief_complaint ?? '—' }}</td>
                <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#triase-{{ $r->id }}">{{ $t ? 'Retriase' : 'Triase' }}</button></td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada pasien di IGD saat ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@foreach ($pasienIgd as $r)
  @php $t = $triase->get($r->id); @endphp
  <div class="modal fade" id="triase-{{ $r->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('igd.triase', $r) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Triase — {{ $r->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Level Triase</label>
          <select name="triage_level" class="form-select mb-2" required>
            <option value="merah" @selected($t?->triage_level === 'merah')>Merah — Gawat Darurat</option>
            <option value="kuning" @selected($t?->triage_level === 'kuning')>Kuning — Mendesak</option>
            <option value="hijau" @selected($t?->triage_level === 'hijau')>Hijau — Tidak Mendesak</option>
            <option value="hitam" @selected($t?->triage_level === 'hitam')>Hitam — Meninggal</option>
          </select>
          <label class="form-label">Keluhan Utama</label>
          <textarea name="chief_complaint" class="form-control" rows="2" required>{{ $t?->chief_complaint }}</textarea>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
