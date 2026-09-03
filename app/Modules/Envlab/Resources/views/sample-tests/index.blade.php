@extends('layouts.app')

@section('title', 'Pengujian Sampel Lab Kesling')
@section('breadcrumb', 'Konteks envlab')
@section('heading', 'Pengujian Sampel Lab Kesehatan Lingkungan & K3')

@section('content')

@can('permintaan_pengujian_sampel_lab_kesehatan_lingkungan')
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Permintaan Baru</h3></div>
    <div class="card-body">
      <form method="POST" action="{{ route('envlab-tests.simpan') }}" class="row g-2">
        @csrf
        <div class="col-12 col-md-4">
          <select name="customer_id" class="form-select" required>
            <option value="">— pelanggan —</option>
            @foreach ($pelanggan as $p)
              <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->kind }})</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-4">
          <select name="sample_type_id" class="form-select" required>
            <option value="">— jenis sampel —</option>
            @foreach ($jenisSampel as $s)
              <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-4">
          <input type="text" name="sample_description" class="form-control" placeholder="Deskripsi sampel (opsional), mis. titik outlet IPAL">
        </div>
        <div class="col-12">
          <label class="form-label">Parameter yang diuji</label>
          <div class="row">
            @foreach ($parameter as $p)
              <div class="col-6 col-md-3">
                <label class="form-check">
                  <input type="checkbox" class="form-check-input" name="parameter_ids[]" value="{{ $p->id }}">
                  <span class="form-check-label">{{ $p->name }}</span>
                </label>
              </div>
            @endforeach
          </div>
          @if ($parameter->isEmpty())
            <div class="form-hint text-danger">Belum ada parameter pengujian di Data Master.</div>
          @endif
        </div>
        <div class="col-12"><button class="btn btn-primary">Ajukan Permintaan</button></div>
      </form>
    </div>
  </div>
@endcan

<div class="card">
  <div class="card-body border-bottom py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach (['diminta','ditolak','diproses','hasil-tersedia','terverifikasi','selesai','dibatalkan'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ \App\Modules\Envlab\Models\SampleTest::statusLabel($s) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-outline-primary w-100">Tampilkan</button>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>No. Permintaan</th><th>Pelanggan</th><th>Jenis Sampel</th>
          <th>Status</th><th>Pembayaran</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $d)
          <tr>
            <td class="font-monospace small">{{ $d->request_number }}</td>
            <td>{{ $d->customer_name }}</td>
            <td>{{ $d->sample_type_name }}</td>
            <td>
              @php
                $rona = match ($d->status) {
                  'diminta' => 'orange', 'diproses' => 'blue', 'hasil-tersedia' => 'azure',
                  'terverifikasi' => 'azure', 'selesai' => 'green', 'ditolak', 'dibatalkan' => 'red',
                  default => 'secondary',
                };
              @endphp
              <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Envlab\Models\SampleTest::statusLabel($d->status) }}</span>
            </td>
            <td>
              @if ($d->payment_status === 'lunas')
                <span class="badge bg-green-lt">Lunas</span>
              @else
                <span class="badge bg-orange-lt">Belum Bayar</span>
              @endif
            </td>
            <td><a href="{{ route('envlab-tests.show', $d) }}" class="btn btn-sm btn-primary">Buka</a></td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada permintaan pengujian.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if ($daftar->hasPages())
    <div class="card-footer">{{ $daftar->links() }}</div>
  @endif
</div>

@endsection
