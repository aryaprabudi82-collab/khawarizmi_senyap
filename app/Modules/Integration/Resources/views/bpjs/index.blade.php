@extends('layouts.app')

@section('title', 'Integrasi BPJS')
@section('breadcrumb', 'Konteks integration')
@section('heading', 'BPJS — Eligibilitas & SEP')

@section('content')

<div class="row g-3">

  {{-- Cek eligibilitas --}}
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Cek Eligibilitas Peserta</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('integrasi.bpjs.cek-kartu') }}" class="row g-2">
          @csrf
          <div class="col-12">
            <label class="form-label">No. Kartu BPJS</label>
            <input type="text" name="no_kartu" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">Tanggal Pelayanan</label>
            <input type="date" name="tanggal_pelayanan" class="form-control" value="{{ now()->toDateString() }}" required>
          </div>
          <div class="col-12"><button class="btn btn-primary w-100">Cek</button></div>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Buat SEP</h3></div>
      <div class="card-body">
        <div class="form-hint mb-2">
          Biasanya dibuka langsung dari layar registrasi kunjungan; formulir ini disediakan sebagai jalan pintas administratif.
        </div>
        <form method="POST" id="form-buat-sep" action="{{ route('integrasi.bpjs.sep.simpan', ['registrasi' => '__ID__']) }}" class="row g-2">
          @csrf
          <div class="col-4">
            <label class="form-label">ID Kunjungan</label>
            <input type="number" name="registrasi_id" class="form-control" required>
          </div>
          <div class="col-8">
            <label class="form-label">No. Kartu</label>
            <input type="text" name="no_kartu" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">No. Rujukan (rawat jalan)</label>
            <input type="text" name="no_rujukan" class="form-control">
          </div>
          <div class="col-12"><button class="btn btn-primary w-100">Terbitkan SEP</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Pemetaan Poli &rarr; Kode BPJS</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th>Kode Poli BPJS</th></tr></thead>
          <tbody>
            @foreach ($unit as $u)
              <tr>
                <td>{{ $u->name }}</td>
                <td class="font-monospace small">{{ $pemetaanPoli[$u->id]->external_id ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('integrasi.bpjs.pemetaan-poli') }}" class="row g-2">
          @csrf
          <div class="col-7">
            <select name="unit_id" class="form-select form-select-sm" required>
              @foreach ($unit as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-3"><input type="text" name="kode_poli_bpjs" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Simpan</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- SEP terbaru --}}
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header"><h3 class="card-title">SEP Terbaru</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. SEP</th><th>Kunjungan</th><th>No. Kartu</th><th>Jenis</th><th>Status</th><th>Diajukan</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($sepTerbaru as $s)
              <tr>
                <td class="font-monospace small">{{ $s->sep_number ?? '—' }}</td>
                <td>{{ $s->registration_number }}</td>
                <td>{{ $s->no_kartu }}</td>
                <td>{{ $s->jenis_pelayanan === '1' ? 'Ranap' : 'Ralan' }}</td>
                <td>
                  @php
                    $warna = ['diajukan' => 'yellow', 'terbit' => 'green', 'gagal' => 'red', 'batal' => 'secondary'][$s->status] ?? 'secondary';
                  @endphp
                  <span class="badge bg-{{ $warna }}-lt">{{ $s->status }}</span>
                </td>
                <td class="text-secondary small">{{ $s->requested_at->format('d-m-Y H:i') }}</td>
                <td>
                  @if ($s->isActive())
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#batal-sep-{{ $s->id }}">Batal</button>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada SEP.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@foreach ($sepTerbaru as $s)
  @if ($s->isActive())
    <div class="modal fade" id="batal-sep-{{ $s->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('integrasi.bpjs.sep.batal', $s) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Batalkan SEP {{ $s->sep_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Alasan</label>
            <textarea name="alasan" class="form-control" required></textarea>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-danger">Batalkan</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

<script>
  document.getElementById('form-buat-sep').addEventListener('submit', function (e) {
    var id = this.querySelector('[name=registrasi_id]').value;
    this.action = this.action.replace('__ID__', encodeURIComponent(id));
  });
</script>

@endsection
