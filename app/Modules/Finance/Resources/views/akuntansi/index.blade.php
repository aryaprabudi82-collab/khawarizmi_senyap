@extends('layouts.app')

@section('title', 'Akuntansi — Pemetaan & Penutupan')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Pemetaan Akun & Penutupan Periode')

@section('content')

<div class="alert alert-warning">
  <b>Bagan akun yang terpasang baru contoh</b> ({{ $akun->count() }} akun dari seeder), bukan bagan akun RSP UI yang
  sebenarnya. Mekanismenya sudah jalan, tapi angkanya baru benar-benar berarti setelah bagan akun disusun bersama
  bagian keuangan.
</div>

@if ($belumDipetakan > 0)
  <div class="alert alert-danger">
    <b>Rp {{ number_format($belumDipetakan, 0, ',', '.') }} belum dipetakan ke akun mana pun</b> pada rentang ini.
    Angka ini sengaja ditampilkan, bukan disembunyikan &mdash; laporan yang tampak rapi padahal ada uang tak
    terpetakan justru menyesatkan.
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Uang Masuk Per Akun Bayar</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Cara bayar</th><th>Akun</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($perCaraBayar as $b)
              <tr>
                <td>{{ $b['key'] }}</td>
                <td>
                  @if ($b['account_code'])
                    <span class="font-monospace small">{{ $b['account_code'] }}</span> {{ $b['account_name'] }}
                  @else
                    <span class="badge bg-red-lt">belum dipetakan</span>
                  @endif
                </td>
                <td class="text-end font-monospace">Rp {{ number_format($b['total'], 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pembayaran pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pendapatan Per Akun</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sumber</th><th>Akun</th><th class="text-end">Jumlah</th></tr></thead>
          <tbody>
            @forelse ($perSumber as $b)
              <tr>
                <td>{{ str_replace('_', ' ', $b['key']) }}</td>
                <td>
                  @if ($b['account_code'])
                    <span class="font-monospace small">{{ $b['account_code'] }}</span> {{ $b['account_name'] }}
                  @else
                    <span class="badge bg-red-lt">belum dipetakan</span>
                  @endif
                </td>
                <td class="text-end font-monospace">Rp {{ number_format($b['total'], 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada pendapatan pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Petakan ke akun</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('akuntansi.pemetaan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis</label>
        <select name="kind" class="form-select" required>
          <option value="cara-bayar">Cara bayar</option>
          <option value="sumber-pendapatan">Sumber pendapatan</option>
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Kunci</label><input type="text" name="key" class="form-control" placeholder="mis. tunai / kamar" required></div>
      <div class="col-12 col-md-4">
        <label class="form-label">Akun</label>
        <select name="account_id" class="form-select" required>
          @foreach ($akun as $a)
            <option value="{{ $a->id }}">{{ $a->code }} &mdash; {{ $a->name }} ({{ $a->type }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control" maxlength="255"></div>
      <div class="col-12"><button class="btn btn-primary">Simpan Pemetaan</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Jenis</th><th>Kunci</th><th>Akun</th><th>Catatan</th></tr></thead>
      <tbody>
        @forelse ($pemetaan as $p)
          <tr>
            <td class="text-secondary small">{{ $p->kind }}</td>
            <td>{{ $p->key }}</td>
            <td><span class="font-monospace small">{{ $p->account->code }}</span> {{ $p->account->name }}</td>
            <td class="text-secondary small">{{ $p->note }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada pemetaan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@can('pendapatan_per_akun_closing')
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Penutupan periode</h3>
      <div class="card-subtitle">Setelah ditutup, angkanya dibekukan &mdash; koreksi yang masuk belakangan tidak lagi mengubah buku yang sudah ditutup.</div>
    </div>
    <div class="card-body">
      <form method="POST" action="{{ route('akuntansi.tutup') }}" class="row g-2">
        @csrf
        <div class="col-6 col-md-2"><label class="form-label">Tahun</label><input type="number" name="tahun" class="form-control" value="{{ now()->year }}" min="2000" max="2100" required></div>
        <div class="col-6 col-md-2"><label class="form-label">Bulan</label><input type="number" name="bulan" class="form-control" value="{{ now()->month }}" min="1" max="12" required></div>
        <div class="col-12 col-md-6"><label class="form-label">Catatan</label><input type="text" name="catatan" class="form-control" maxlength="1000"></div>
        <div class="col-12"><button class="btn btn-warning">Tutup Periode</button></div>
      </form>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Periode</th><th>Ditutup</th><th class="text-end">Baris dibekukan</th><th>Status</th><th class="w-1"></th></tr></thead>
        <tbody>
          @forelse ($penutupan as $t)
            <tr>
              <td>{{ $t->label() }}</td>
              <td class="text-secondary small">{{ $t->closed_at->format('d-m-Y H:i') }}</td>
              <td class="text-end">{{ $t->lines->count() }}</td>
              <td>
                @if ($t->isReopened())
                  <span class="badge bg-secondary-lt" title="{{ $t->reopen_reason }}">dibuka kembali</span>
                @else
                  <span class="badge bg-green-lt">tertutup</span>
                @endif
              </td>
              <td>
                @if (! $t->isReopened())
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#buka-{{ $t->id }}">Buka Kembali</button>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada periode yang ditutup.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  @foreach ($penutupan as $t)
    @if (! $t->isReopened())
      <div class="modal fade" id="buka-{{ $t->id }}" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <form class="modal-content" method="POST" action="{{ route('akuntansi.buka', $t) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title">Buka kembali periode {{ $t->label() }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <p class="text-secondary small">Angka yang sudah dibekukan akan berhenti berlaku. Alasannya dicatat berikut siapa yang membukanya.</p>
              <label class="form-label">Alasan</label>
              <textarea name="alasan" class="form-control" minlength="5" required></textarea>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-danger">Buka Kembali</button></div>
          </form>
        </div>
      </div>
    @endif
  @endforeach
@endcan

@endsection
