@extends('layouts.app')

@section('title', 'Kanal Pembayaran Bank')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Kanal Pembayaran Bank')

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Satu layar untuk semua bank.</b> Yang berbeda antar bank cuma cara datanya masuk; begitu sudah masuk,
  semuanya sejumlah uang dengan nomor rujukan yang harus dicocokkan ke tagihan.
  <b>Pembayaran yang dicocokkan langsung tercatat pada tagihannya</b> seperti pembayaran di kasir &mdash;
  layar ini hanya menyimpan pemberitahuannya, bukan salinan uangnya.
</div>

<div class="alert alert-warning">
  <b>Penghubung otomatis ke bank belum ada.</b> Kanal, pencocokan, dan pencatatannya sudah lengkap, tapi
  berkas atau API tiap bank harus dibangun per bank saat kerja samanya benar-benar ada &mdash; lengkap dengan
  kredensialnya. Sampai itu terjadi, pemberitahuan dimasukkan manual dari rekening koran, dan itu tetap
  cara yang lazim dipakai banyak rumah sakit.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label">Kanal</label>
        <select name="kanal" class="form-select">
          <option value="">Semua kanal</option>
          @foreach ($kanal as $k)
            <option value="{{ $k->id }}" @selected($channelId === $k->id)>{{ $k->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">Semua</option>
          @foreach (['diterima' => 'Menggantung', 'tercocok' => 'Tercocok', 'ditolak' => 'Ditolak'] as $s => $label)
            <option value="{{ $s }}" @selected($status === $s)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Rekap per Kanal</h3><div class="card-subtitle">Yang menggantung adalah uang yang sudah masuk tapi belum diketahui tagihannya</div></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kanal</th><th class="text-end">Masuk</th><th class="text-end">Tercocok</th><th class="text-end">Menggantung</th><th class="text-end">Nilai tercocok</th><th class="text-end">Nilai menggantung</th></tr></thead>
      <tbody>
        @forelse ($rekap as $b)
          <tr>
            <td>{{ $b->name }}</td>
            <td class="text-end">{{ $b->pemberitahuan }}</td>
            <td class="text-end">{{ $b->tercocok }}</td>
            <td class="text-end {{ $b->menggantung > 0 ? 'text-danger' : '' }}">{{ $b->menggantung }}</td>
            <td class="text-end">{{ number_format($b->nilai_tercocok, 0, ',', '.') }}</td>
            <td class="text-end {{ $b->nilai_menggantung > 0 ? 'text-danger' : '' }}">{{ number_format($b->nilai_menggantung, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pembayaran kanal pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@if ($menggantung->isNotEmpty())
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Menunggu Dicocokkan</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Kanal</th><th>Rujukan</th><th>Penyetor</th><th>Waktu</th><th class="text-end">Nilai</th><th style="width:34%"></th></tr></thead>
        <tbody>
          @foreach ($menggantung as $b)
            <tr>
              <td class="small">{{ $b->channel_name }}</td>
              <td class="font-monospace small">{{ $b->reference_number }}</td>
              <td>{{ $b->payer_name ?: '—' }}</td>
              <td class="small text-secondary">{{ $b->paid_at }}</td>
              <td class="text-end"><b>{{ number_format($b->amount, 0, ',', '.') }}</b></td>
              <td>
                <div class="d-flex gap-1">
                  <form method="POST" action="{{ route('kanal.cocokkan', $b->id) }}" class="d-flex gap-1">
                    @csrf
                    <input name="invoice_id" type="number" class="form-control form-control-sm" placeholder="ID tagihan" required>
                    <button class="btn btn-sm btn-success">Cocokkan</button>
                  </form>
                  <form method="POST" action="{{ route('kanal.tolak', $b->id) }}" class="d-flex gap-1">
                    @csrf
                    <input name="reason" class="form-control form-control-sm" placeholder="Alasan" required>
                    <button class="btn btn-sm btn-outline-danger">Tolak</button>
                  </form>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

<div class="row">
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Kanal Terdaftar</h3><div class="card-subtitle">Nomor rekening sengaja tidak ditebak &mdash; uang bisa masuk ke rekening yang salah</div></div>
      <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Ralan</th><th>Ranap</th></tr></thead>
          <tbody>
            @foreach ($kanal as $k)
              <tr class="{{ $k->is_active ? '' : 'text-secondary' }}">
                <td class="font-monospace small">{{ $k->code }}</td>
                <td>{{ $k->name }}</td>
                <td class="small text-secondary">{{ $k->kind }}</td>
                <td>{{ $k->allows_ralan ? 'ya' : '—' }}</td>
                <td>{{ $k->allows_ranap ? 'ya' : '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <form method="POST" action="{{ route('kanal.simpan') }}" class="card-body border-top">
        @csrf
        <div class="row g-2">
          <div class="col-5"><label class="form-label">Kode</label><input name="code" class="form-control" required></div>
          <div class="col-7"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
          <div class="col-6">
            <label class="form-label">Jenis</label>
            <select name="kind" class="form-select" required>
              @foreach ($jenisKanal as $j)<option value="{{ $j }}">{{ $j }}</option>@endforeach
            </select>
          </div>
          <div class="col-6"><label class="form-label">Bank</label><input name="bank_name" class="form-control"></div>
          <div class="col-12"><label class="form-label">No. rekening</label><input name="account_number" class="form-control" placeholder="Kosongkan bila belum pasti"></div>
          <div class="col-6"><label class="form-check"><input type="checkbox" name="allows_ralan" value="1" class="form-check-input" checked> Boleh rawat jalan</label></div>
          <div class="col-6"><label class="form-check"><input type="checkbox" name="allows_ranap" value="1" class="form-check-input" checked> Boleh rawat inap</label></div>
        </div>
        <button class="btn btn-primary mt-3">Tambah Kanal</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Pemberitahuan dari Rekening Koran</h3></div>
      <form method="POST" action="{{ route('kanal.terima', $kanalAktif->first()?->id ?? 0) }}" class="card-body" id="form-terima">
        @csrf
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Kanal</label>
            <select class="form-select" onchange="document.getElementById('form-terima').action = this.value" required>
              @foreach ($kanalAktif as $k)
                <option value="{{ route('kanal.terima', $k->id) }}">{{ $k->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6"><label class="form-label">No. rujukan bank</label><input name="reference_number" class="form-control" required></div>
          <div class="col-6"><label class="form-label">Virtual account</label><input name="virtual_account" class="form-control"></div>
          <div class="col-6"><label class="form-label">Nama penyetor</label><input name="payer_name" class="form-control"></div>
          <div class="col-3"><label class="form-label">Nilai (Rp)</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
          <div class="col-3"><label class="form-label">Waktu</label><input name="paid_at" type="datetime-local" class="form-control" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
        </div>
        <button class="btn btn-primary mt-3">Terima Pemberitahuan</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Riwayat Pemberitahuan</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kanal</th><th>Rujukan</th><th>Waktu</th><th class="text-end">Nilai</th><th>Status</th><th>Tagihan</th></tr></thead>
          <tbody>
            @forelse ($inbox as $b)
              <tr>
                <td class="small">{{ $b->channel_code }}</td>
                <td class="font-monospace small">{{ $b->reference_number }}</td>
                <td class="small text-secondary">{{ $b->paid_at }}</td>
                <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
                <td class="small">{{ $b->status }}</td>
                <td class="font-monospace small">{{ $b->invoice_number ?: '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pemberitahuan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
