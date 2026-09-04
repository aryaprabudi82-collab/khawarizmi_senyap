@extends('layouts.app')

@section('title', 'Farmasi — Retur Obat Ranap')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Retur Obat Rawat Inap')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Retur</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('pharmacy.retur-ranap.simpan') }}">
      @csrf
      <div class="row g-2 mb-3 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label" for="cari-kunjungan">No. Registrasi</label>
          <div class="input-group">
            <input type="text" id="cari-kunjungan" class="form-control" placeholder="mis. 20260904-00001">
            <button type="button" id="btn-cari-kunjungan" class="btn btn-outline-secondary">Cari</button>
          </div>
          <input type="hidden" name="registration_id" id="registration_id">
          <div id="info-kunjungan" class="form-hint mt-1"></div>
        </div>
        <div class="col-12 col-md-8">
          <label class="form-label">Catatan</label>
          <input type="text" name="notes" class="form-control">
        </div>
      </div>

      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Obat/Alkes/BHP</th><th>Satuan</th><th style="width:140px">Jumlah</th></tr></thead>
          <tbody>
            @foreach ($obat as $o)
              <tr>
                <td>
                  {{ $o->name }}
                  <input type="hidden" name="drug_id[]" value="{{ $o->id }}">
                </td>
                <td class="text-secondary">{{ $o->unit }}</td>
                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <button class="btn btn-primary">Ajukan Retur</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Retur Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Retur</th><th>Pasien</th><th>No. RM</th><th>Obat/BHP</th><th>Waktu</th></tr></thead>
      <tbody>
        @forelse ($retur as $r)
          <tr>
            <td class="font-monospace small">{{ $r->return_number }}</td>
            <td>{{ $r->patient_name }}</td>
            <td class="font-monospace small">{{ $r->patient_mrn }}</td>
            <td class="text-secondary small">
              @foreach ($r->items as $baris)
                {{ $baris->drug_name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
              @endforeach
            </td>
            <td class="text-secondary small">{{ $r->returned_at->format('d-m-Y H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada retur obat ranap.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var tombol = document.getElementById('btn-cari-kunjungan');
    var input = document.getElementById('cari-kunjungan');
    var hidden = document.getElementById('registration_id');
    var info = document.getElementById('info-kunjungan');
    if (!tombol) return;

    tombol.addEventListener('click', function () {
      var nomor = input.value.trim();
      if (nomor === '') return;

      fetch('{{ route('pharmacy.retur-ranap.cari-kunjungan') }}?nomor=' + encodeURIComponent(nomor))
        .then(function (r) { return r.json(); })
        .then(function (hasil) {
          if (hasil.kunjungan) {
            hidden.value = hasil.kunjungan.id;
            info.textContent = 'Ditemukan: ' + hasil.kunjungan.patient_name + ' (RM ' + hasil.kunjungan.patient_mrn + ')';
            info.classList.remove('text-danger');
          } else {
            hidden.value = '';
            info.textContent = 'Kunjungan tidak ditemukan.';
            info.classList.add('text-danger');
          }
        })
        .catch(function () { info.textContent = 'Pencarian gagal, coba lagi.'; });
    });
  });
</script>
@endpush

@endsection
