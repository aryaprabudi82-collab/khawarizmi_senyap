@extends('layouts.app')

@section('title', 'Pengajuan Biaya')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Pengajuan Biaya')

@section('actions')
  <a href="{{ route('kas.index') }}" class="btn btn-link">Kas Harian &rarr;</a>
@endsection

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Tiga tahap, dan itu memang disengaja:</b> mengajukan, menyetujui, lalu memvalidasi persetujuan.
  Ketiganya orang berbeda &mdash; yang butuh uangnya, yang berwenang menyetujui, dan yang memastikan
  persetujuannya sah sebelum uang keluar. Aturan itu ditegakkan di dalam sistem, bukan cuma lewat hak akses,
  karena di rumah sakit kecil satu orang lazim memegang beberapa peran sekaligus.
  <b>Pencairan menulis langsung ke kas harian</b>, sebesar yang <b>disetujui</b> &mdash; bukan yang diajukan.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">Semua status</option>
          @foreach (['diajukan', 'disetujui', 'tervalidasi', 'dicairkan', 'ditolak'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Unit</label><input name="unit" class="form-control" value="{{ $unit }}" placeholder="Semua unit"></div>
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Rekap per Unit</h3>
        <div class="card-subtitle">Selisih diminta dan disetujui adalah angka yang dicari saat menyusun anggaran berikutnya</div>
      </div>
      <div class="table-responsive" style="max-height:280px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th class="text-end">Pengajuan</th><th class="text-end">Diminta</th><th class="text-end">Disetujui</th><th class="text-end">Selisih</th><th class="text-end">Ditolak</th></tr></thead>
          <tbody>
            @forelse ($perUnit as $b)
              <tr>
                <td>{{ $b->unit_name }}</td>
                <td class="text-end">{{ $b->pengajuan }}</td>
                <td class="text-end">{{ number_format($b->diminta, 0, ',', '.') }}</td>
                <td class="text-end">{{ number_format($b->disetujui, 0, ',', '.') }}</td>
                <td class="text-end {{ $b->selisih > 0 ? 'text-danger' : '' }}">{{ number_format($b->selisih, 0, ',', '.') }}</td>
                <td class="text-end">{{ $b->ditolak }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada pengajuan pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rekap per Pos Pengeluaran</h3></div>
      <div class="table-responsive" style="max-height:280px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pos</th><th class="text-end">Diminta</th><th class="text-end">Disetujui</th></tr></thead>
          <tbody>
            @forelse ($perPos as $b)
              <tr><td>{{ $b->pos }}</td><td class="text-end">{{ number_format($b->diminta, 0, ',', '.') }}</td><td class="text-end">{{ number_format($b->disetujui, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@if ($menunggu->isNotEmpty())
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Menunggu Tindakan</h3><div class="card-subtitle">Pekerjaan yang belum selesai</div></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Nomor</th><th>Unit</th><th>Keperluan</th><th>Status</th><th class="text-end">Diminta</th><th class="text-end">Disetujui</th><th style="width:34%"></th></tr></thead>
        <tbody>
          @foreach ($menunggu as $b)
            <tr>
              <td class="font-monospace small">{{ $b->request_number }}</td>
              <td>{{ $b->unit_name }}</td>
              <td>{{ $b->purpose }}</td>
              <td><span class="badge bg-secondary">{{ $b->status }}</span></td>
              <td class="text-end">{{ number_format($b->requested_amount, 0, ',', '.') }}</td>
              <td class="text-end">{{ $b->approved_amount === null ? '—' : number_format($b->approved_amount, 0, ',', '.') }}</td>
              <td>
                @if ($b->status === 'diajukan')
                  <div class="d-flex gap-1">
                    <form method="POST" action="{{ route('pengajuan-biaya.setuju', $b->id) }}" class="d-flex gap-1">
                      @csrf
                      <input name="approved_amount" type="number" step="0.01" class="form-control form-control-sm" placeholder="Nilai disetujui">
                      <button class="btn btn-sm btn-success">Setujui</button>
                    </form>
                    <form method="POST" action="{{ route('pengajuan-biaya.tolak', $b->id) }}" class="d-flex gap-1">
                      @csrf
                      <input name="reason" class="form-control form-control-sm" placeholder="Alasan" required>
                      <button class="btn btn-sm btn-outline-danger">Tolak</button>
                    </form>
                  </div>
                @elseif ($b->status === 'disetujui')
                  <form method="POST" action="{{ route('pengajuan-biaya.validasi', $b->id) }}">
                    @csrf
                    <button class="btn btn-sm btn-primary">Validasi Persetujuan</button>
                  </form>
                @elseif ($b->status === 'tervalidasi')
                  <form method="POST" action="{{ route('pengajuan-biaya.cairkan', $b->id) }}" class="d-flex gap-1">
                    @csrf
                    <input name="paid_on" type="date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                    <button class="btn btn-sm btn-primary">Cairkan</button>
                  </form>
                @endif
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
      <div class="card-header"><h3 class="card-title">Ajukan Biaya</h3></div>
      <form method="POST" action="{{ route('pengajuan-biaya.simpan') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-12"><label class="form-label">Unit pengaju</label><input name="unit_name" class="form-control" required></div>
          <div class="col-6"><label class="form-label">Tanggal</label><input name="requested_on" type="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6"><label class="form-label">Nilai (Rp)</label><input name="requested_amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
          <div class="col-12">
            <label class="form-label">Pos pengeluaran</label>
            <select name="category_id" class="form-select">
              <option value="">&mdash; belum ditentukan &mdash;</option>
              @foreach ($pos as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-12"><label class="form-label">Keperluan</label><input name="purpose" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Alasan</label><textarea name="justification" class="form-control" rows="2"></textarea></div>
        </div>
        <button class="btn btn-primary mt-3">Ajukan</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Daftar Pengajuan</h3></div>
      <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Nomor</th><th>Tanggal</th><th>Unit</th><th>Keperluan</th><th>Status</th><th class="text-end">Diminta</th><th class="text-end">Disetujui</th></tr></thead>
          <tbody>
            @forelse ($daftar as $b)
              <tr>
                <td class="font-monospace small">{{ $b->request_number }}</td>
                <td>{{ $b->requested_on->toDateString() }}</td>
                <td>{{ $b->unit_name }}</td>
                <td>{{ $b->purpose }}</td>
                <td class="small">{{ $b->status }}</td>
                <td class="text-end">{{ number_format($b->requested_amount, 0, ',', '.') }}</td>
                <td class="text-end">{{ $b->approved_amount === null ? '—' : number_format($b->approved_amount, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada pengajuan pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
