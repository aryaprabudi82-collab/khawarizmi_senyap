@extends('layouts.app')

@section('title', 'Piutang Pasien')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Piutang Pasien')

@section('actions')
  <a href="{{ route('tagihan.index') }}" class="btn btn-link">&larr; Kasir</a>
@endsection

@section('content')

<div class="alert alert-info">
  Piutang <b>pasien</b> &mdash; pasien pulang tanpa melunasi, sisanya jatuh tempo dan dicicil.
  Berbeda dari piutang <b>penjamin</b> (klaim BPJS/asuransi) yang ditagih di layar Piutang Penjamin.
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <div class="btn-group">
      @foreach (['belum-lunas' => 'Belum Lunas', 'terlambat' => 'Lewat Jatuh Tempo', 'lunas' => 'Sudah Lunas'] as $kode => $label)
        <a href="{{ route('piutang-pasien.index', ['saring' => $kode]) }}"
           class="btn btn-sm {{ $saring === $kode ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
      @endforeach
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">{{ $daftar->count() }} piutang</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>Pasien</th><th>Tagihan</th><th>Jenis</th>
          <th class="text-end">Pokok</th><th class="text-end">Sisa</th>
          <th>Jatuh Tempo</th><th class="w-1"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($daftar as $p)
          <tr>
            <td>{{ $p->patient_name }}</td>
            <td><a href="{{ route('tagihan.show', $p->invoice_id) }}" class="font-monospace small">{{ $p->invoice->invoice_number }}</a></td>
            <td><span class="badge bg-{{ $p->care_type === 'ranap' ? 'purple' : 'blue' }}-lt">{{ $p->care_type }}</span></td>
            <td class="text-end">Rp {{ number_format((float) $p->principal_amount, 0, ',', '.') }}</td>
            <td class="text-end">
              @if ($p->isSettled())
                <span class="badge bg-green-lt">lunas</span>
              @else
                Rp {{ number_format($p->outstanding(), 0, ',', '.') }}
              @endif
            </td>
            <td>
              {{ $p->due_date->format('d-m-Y') }}
              @if ($p->isOverdue())
                <span class="badge bg-red-lt">terlambat</span>
              @endif
            </td>
            <td>
              @if (! $p->isSettled())
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#batal-piutang-{{ $p->id }}">Batalkan</button>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada piutang pada saringan ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($daftar->reject(fn ($p) => $p->isSettled()) as $p)
  <div class="modal fade" id="batal-piutang-{{ $p->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('piutang-pasien.batal', $p) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Batalkan piutang {{ $p->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="text-secondary small">Pembayaran yang sudah masuk tidak ikut dibatalkan &mdash; itu tindakan tersendiri di layar tagihan.</p>
          <label class="form-label">Alasan pembatalan</label>
          <textarea name="alasan" class="form-control" minlength="5" required></textarea>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-danger">Batalkan Piutang</button></div>
      </form>
    </div>
  </div>
@endforeach

@endsection
