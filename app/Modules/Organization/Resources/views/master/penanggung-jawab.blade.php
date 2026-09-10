@extends('layouts.app')

@section('title', 'Penanggung Jawab Unit Penunjang')
@section('breadcrumb', 'Konteks organization')
@section('heading', 'Penanggung Jawab Unit Penunjang')

@section('actions')
  @can('tarif_ralan')
    <a href="{{ route('master.organisasi') }}" class="btn btn-link">&larr; Master Organisasi</a>
  @endcan
@endsection

@section('content')

<div class="alert alert-info">
  <p class="mb-1"><b>Penugasan adalah peristiwa berjangka waktu, bukan satu baris berkolom.</b> <code>set_pjlab</code> Khanza menaruh <b>enam</b> dokter penanggung jawab dalam <b>satu</b> baris &mdash; lab, radiologi, hemodialisa, UTD, patologi anatomi, mikrobiologi &mdash; dengan <i>primary key</i> gabungan dari tiga di antaranya. Menukar penanggung jawab lab berarti mengubah sebagian kunci baris, yaitu membuat baris yang berbeda: yang lama tidak berpindah, ia tinggal atau tertimpa tergantung urutan tulis.</p>
  <p class="mb-0"><b>Dan tidak ada riwayat sama sekali.</b> Pertanyaan "siapa penanggung jawab laboratorium bulan Maret" &mdash; yang justru ditanyakan saat ada hasil dipersoalkan, saat akreditasi memeriksa, atau saat insiden ditelusuri &mdash; tidak punya jawaban di sana. Yang tersimpan cuma siapa yang menjabat hari ini, dan itu bukan yang ditanyakan.</p>
</div>

@if ($tanpaPj->isNotEmpty())
  <div class="alert alert-warning">
    <b>{{ $tanpaPj->count() }} unit penunjang belum punya penanggung jawab.</b> Ini bukan keadaan yang sah menurut akreditasi, dan lebih baik terlihat di sini sebagai pekerjaan daripada baru ketahuan saat diperiksa.
    <div class="mt-1">{{ $tanpaPj->pluck('name')->implode(', ') }}</div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Tetapkan Penanggung Jawab</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('master.penanggung-jawab.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-3">
        <label class="form-label">Unit Penunjang</label>
        <select name="unit_id" class="form-select" required>
          <option value="">— pilih unit —</option>
          @foreach ($unitPenunjang as $u)
            <option value="{{ $u->id }}" @selected(old('unit_id') == $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Penanggung Jawab</label>
        <select name="practitioner_id" class="form-select" required>
          <option value="">— pilih praktisi —</option>
          @foreach ($praktisi as $p)
            <option value="{{ $p->id }}" @selected(old('practitioner_id') == $p->id)>{{ $p->name }}@if ($p->specialty) · {{ $p->specialty }}@endif</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">Mulai Menjabat</label><input type="date" name="start_date" class="form-control" value="{{ old('start_date', now()->toDateString()) }}" required></div>
      <div class="col-6 col-md-2"><label class="form-label">Nomor SK</label><input type="text" name="decree_number" class="form-control" value="{{ old('decree_number') }}"></div>
      <div class="col-12 col-md-2"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control" value="{{ old('note') }}"></div>
      <div class="col-12"><button class="btn btn-primary">Tetapkan</button></div>
    </form>
    <div class="form-hint mt-2">Penugasan yang masih berjalan <b>ditutup otomatis</b> sehari sebelum yang baru mulai. Dua penanggung jawab bersamaan bukan kelonggaran administratif &mdash; ia berarti tidak ada yang tahu tanda tangan siapa yang sah pada hasil pemeriksaan.</div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat Penugasan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Unit</th><th>Penanggung Jawab</th><th>Mulai</th><th>Selesai</th><th>Nomor SK</th><th>Status</th></tr></thead>
      <tbody>
        @forelse ($penugasan as $barisUnit)
          @foreach ($barisUnit as $s)
            <tr>
              <td>@if ($loop->first)<b>{{ $s->unit->name }}</b>@endif</td>
              <td>{{ $s->practitioner->name }}</td>
              <td>{{ $s->start_date->format('d/m/Y') }}</td>
              <td>{{ $s->end_date?->format('d/m/Y') ?? '—' }}</td>
              <td class="text-secondary">{{ $s->decree_number ?: '—' }}</td>
              <td>
                @if ($s->masihMenjabat())
                  <span class="badge bg-green-lt">menjabat</span>
                @else
                  <span class="badge bg-secondary-lt">selesai</span>
                @endif
              </td>
            </tr>
          @endforeach
        @empty
          <tr><td colspan="6" class="text-secondary">Belum ada penugasan tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer text-secondary">
    Penugasan lama <b>tidak dihapus</b> saat penanggung jawab berganti. Hasil pemeriksaan lama harus tetap bisa menemukan penanggung jawabnya pada tanggal pemeriksaan itu &mdash; bukan penanggung jawab hari ini. Kolom "selesai" yang kosong berarti <b>masih menjabat</b>, bukan tidak diketahui.
  </div>
</div>

@endsection
