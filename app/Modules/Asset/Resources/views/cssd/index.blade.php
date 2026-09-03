@extends('layouts.app')

@section('title', 'Aset — CSSD')
@section('breadcrumb', 'Konteks asset')
@section('heading', 'CSSD — Sirkulasi Instrumen Steril')

@section('actions')
  <a href="{{ route('asset.index') }}" class="btn btn-link">&larr; Aset</a>
  <a href="{{ route('asset.pemeliharaan.index') }}" class="btn btn-link">Pemeliharaan &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Set Instrumen</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
          <tbody>
            @forelse ($set as $s)
              <tr><td class="font-monospace small">{{ $s->code }}</td><td>{{ $s->name }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">Belum ada set instrumen.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('asset.cssd.set.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama set" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Terima Set Kotor</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('asset.cssd.terima') }}" class="row g-2">
          @csrf
          <div class="col-12">
            <label class="form-label">Set Instrumen</label>
            <select name="cssd_item_id" class="form-select" required>
              @foreach ($set as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Unit Asal</label>
            <select name="unit_id" class="form-select" required>
              @foreach ($unit as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="notes" class="form-control"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Terima</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Sirkulasi Terbaru</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Sirkulasi</th><th>Set</th><th>Unit</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($sirkulasi as $c)
              <tr>
                <td class="font-monospace small">{{ $c->circulation_number }}</td>
                <td>{{ $c->cssdItem->name }}</td>
                <td class="text-secondary small">{{ $c->unit_name }}</td>
                <td>
                  @php
                    $warna = ['kotor' => 'red', 'diproses' => 'yellow', 'steril' => 'green', 'didistribusikan' => 'blue'][$c->status];
                  @endphp
                  <span class="badge bg-{{ $warna }}-lt">{{ $c->status }}</span>
                </td>
                <td>
                  @if ($c->status === 'kotor')
                    <form method="POST" action="{{ route('asset.cssd.proses', $c) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-primary">Mulai Proses</button>
                    </form>
                  @elseif ($c->status === 'diproses')
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#steril-{{ $c->id }}">Tandai Steril</button>
                  @elseif ($c->status === 'steril')
                    <form method="POST" action="{{ route('asset.cssd.distribusi', $c) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-info">Distribusikan</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada sirkulasi CSSD.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@foreach ($sirkulasi as $c)
  @if ($c->status === 'diproses')
    <div class="modal fade" id="steril-{{ $c->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('asset.cssd.steril', $c) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Sterilisasi {{ $c->circulation_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Metode Sterilisasi</label>
            <select name="sterilization_method" class="form-select">
              <option value="autoklaf-uap">Autoklaf Uap</option>
              <option value="etilen-oksida">Etilen Oksida</option>
              <option value="plasma">Plasma</option>
            </select>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-success">Tandai Steril</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
