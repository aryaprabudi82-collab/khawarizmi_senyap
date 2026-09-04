@extends('layouts.app')

@section('title', 'Farmasi — Hibah Obat & BHP')
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Hibah Obat, Alkes & BHP')

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">&larr; Resep</a>
@endsection

@section('content')

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Pemberi Hibah</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($donor as $d)
              <tr><td class="font-monospace small">{{ $d->code }}</td><td>{{ $d->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada pemberi hibah.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('pharmacy.hibah.donor.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-8"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama pemberi" required></div>
          <div class="col-12"><input type="text" name="contact" class="form-control form-control-sm" placeholder="Kontak (opsional)"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Tambah Pemberi Hibah</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Terima Hibah</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('pharmacy.hibah.simpan') }}">
          @csrf
          <div class="row g-2 mb-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Pemberi Hibah</label>
              <select name="donor_id" class="form-select" required>
                @foreach ($donor as $d)
                  <option value="{{ $d->id }}">{{ $d->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12 col-md-6"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control"></div>
          </div>

          <div class="table-responsive mb-2">
            <table class="table table-sm">
              <thead><tr><th>Obat/Alkes/BHP</th><th style="width:110px">Jumlah</th><th style="width:130px">No. Batch</th><th style="width:130px">Kedaluwarsa</th></tr></thead>
              <tbody>
                @foreach ($obat as $o)
                  <tr>
                    <td>
                      {{ $o->name }}
                      <input type="hidden" name="drug_id[]" value="{{ $o->id }}">
                    </td>
                    <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                    <td><input type="text" name="batch_number[]" class="form-control form-control-sm"></td>
                    <td><input type="date" name="expiry_date[]" class="form-control form-control-sm"></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          <button class="btn btn-primary">Simpan Hibah</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Hibah Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Hibah</th><th>Pemberi</th><th>Obat/BHP</th><th>Waktu</th></tr></thead>
      <tbody>
        @forelse ($hibah as $h)
          <tr>
            <td class="font-monospace small">{{ $h->receipt_number }}</td>
            <td>{{ $h->donor->name }}</td>
            <td class="text-secondary small">
              @foreach ($h->items as $baris)
                {{ $baris->drug_name }} ({{ rtrim(rtrim(number_format((float) $baris->quantity, 2, ',', '.'), '0'), ',') }})@if (!$loop->last), @endif
              @endforeach
            </td>
            <td class="text-secondary small">{{ $h->received_at->format('d-m-Y H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada hibah tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
