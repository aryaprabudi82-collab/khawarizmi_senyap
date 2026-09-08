@extends('layouts.app')

@section('title', 'Hak Pasien — Permintaan & Serah Terima')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Hak Pasien')

@section('actions')
  <a href="{{ route('correspondence.persetujuan.index') }}" class="btn btn-link">Persetujuan Tindakan &rarr;</a>
@endsection

@section('content')

@php
  $labelHubungan = fn (string $h) => ucwords(str_replace('-', ' ', $h));
@endphp

{{--
  Yang belum dijawab tampil TERPISAH dan di atas. Daftar riwayat mengurut
  dari yang terbaru, dan itu justru menyembunyikan permintaan lama yang
  terlantar — padahal permintaan yang tidak dijawab siapa pun adalah cara
  kegagalan yang sebenarnya.
--}}
<div class="card mb-3 {{ $tertunggak->isNotEmpty() ? 'border-warning' : '' }}">
  <div class="card-header">
    <h3 class="card-title">Belum Dijawab</h3>
    @if ($tertunggak->isNotEmpty())
      <span class="badge bg-yellow-lt ms-2">{{ $tertunggak->count() }}</span>
    @endif
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Isi</th><th>Menunggu</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($tertunggak as $p)
          <tr>
            <td class="font-monospace small">{{ $p->request_number }}</td>
            <td>{{ $p->patient_name }}</td>
            <td><span class="badge bg-blue-lt">{{ $p->label() }}</span></td>
            <td class="small">{{ $p->detail }}</td>
            <td class="small">{{ number_format($p->jamMenunggu(), 1) }} jam</td>
            <td>
              <form method="POST" action="{{ route('correspondence.hak-pasien.jawab', $p) }}" class="d-flex gap-1">
                @csrf
                <select name="keputusan" class="form-select form-select-sm" style="width:8rem">
                  <option value="dipenuhi">Dipenuhi</option>
                  <option value="ditolak">Ditolak</option>
                </select>
                <input type="text" name="response_note" class="form-control form-control-sm"
                       placeholder="Alasan (wajib bila ditolak)">
                <button class="btn btn-sm btn-primary">Jawab</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada permintaan yang menunggu jawaban.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Catat Permintaan Pasien</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('correspondence.hak-pasien.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Jenis</label>
        <select name="request_type" class="form-select" required>
          @foreach ($jenis as $kode => $label)
            <option value="{{ $kode }}">{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
      <div class="col-6 col-md-4"><label class="form-label">ID Kunjungan (opsional)</label><input type="number" name="registration_id" class="form-control"></div>

      <div class="col-12 col-md-5"><label class="form-label">Nama Peminta</label><input type="text" name="requester_name" class="form-control" required></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Hubungan</label>
        <select name="requester_relationship" class="form-select" required>
          @foreach ($hubungan as $h)
            <option value="{{ $h }}">{{ $labelHubungan($h) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-3 col-md-2"><label class="form-label">Cuti Mulai</label><input type="date" name="leave_starts_at" class="form-control"></div>
      <div class="col-3 col-md-2"><label class="form-label">Cuti Selesai</label><input type="date" name="leave_ends_at" class="form-control"></div>

      <div class="col-12">
        <label class="form-label">Isi Permintaan</label>
        <textarea name="detail" class="form-control" rows="2" required placeholder="Dengan kata-kata peminta"></textarea>
        <div class="form-hint">Tanggal cuti hanya terpakai untuk pengajuan cuti perawatan.</div>
      </div>
      <div class="col-12"><button class="btn btn-primary">Simpan Permintaan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Riwayat Permintaan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Status</th><th>Jawaban</th><th>Oleh</th></tr></thead>
      <tbody>
        @forelse ($riwayat as $p)
          <tr>
            <td class="font-monospace small">{{ $p->request_number }}</td>
            <td>{{ $p->patient_name }}</td>
            <td><span class="badge bg-blue-lt">{{ $p->label() }}</span></td>
            <td>
              @php $warna = ['diminta' => 'yellow', 'dipenuhi' => 'green', 'ditolak' => 'red'][$p->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $p->status }}</span>
            </td>
            <td class="small">{{ $p->response_note ?: '—' }}</td>
            <td class="small">{{ $p->responded_by_name ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada permintaan tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Serah Terima Barang &amp; Anggota Tubuh</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Dua arah, bukan satu. Penitipan ikut dicatat supaya pertanyaan &ldquo;apakah masih ada
      barang pasien ini yang dipegang rumah sakit&rdquo; punya jawaban &mdash; penyerahan tanpa
      pembanding tidak bisa direkonsiliasi.
    </div>
    <form method="POST" action="{{ route('correspondence.hak-pasien.serah-terima') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2">
        <label class="form-label">Arah</label>
        <select name="arah" class="form-select" required>
          <option value="dititipkan">Dititipkan ke RS</option>
          <option value="diserahkan">Diserahkan ke keluarga</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis</label>
        <select name="kind" class="form-select" required>
          <option value="barang-pasien">Barang Pasien</option>
          <option value="anggota-tubuh">Anggota Tubuh</option>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Melunasi Titipan</label>
        <select name="settles_handover_id" class="form-select">
          <option value="">&mdash; bukan penyerahan titipan &mdash;</option>
          @foreach ($masihDititipkan as $t)
            <option value="{{ $t->id }}">{{ $t->handover_number }} &middot; {{ $t->patient_name }} &middot; {{ $t->description }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>

      <div class="col-12 col-md-5"><label class="form-label">Uraian</label><input type="text" name="description" class="form-control" required></div>
      <div class="col-6 col-md-2"><label class="form-label">Jumlah</label><input type="text" name="quantity" class="form-control" placeholder="1 buah"></div>
      <div class="col-6 col-md-5"><label class="form-label">Kondisi</label><input type="text" name="condition" class="form-control" required placeholder="Wajib — pembanding bila ada klaim rusak/kurang"></div>

      <div class="col-12 col-md-4">
        <label class="form-label">Label Wadah</label>
        <input type="text" name="container_label" class="form-control" placeholder="Wajib untuk anggota tubuh">
      </div>
      <div class="col-12 col-md-4"><label class="form-label">Nama Pihak Kedua</label><input type="text" name="counterparty_name" class="form-control" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Hubungan</label>
        <select name="counterparty_relationship" class="form-select" required>
          @foreach ($hubungan as $h)
            <option value="{{ $h }}">{{ $labelHubungan($h) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><label class="form-label">NIK/KTP</label><input type="text" name="counterparty_id_number" class="form-control"></div>

      <div class="col-12 col-md-4"><label class="form-label">Petugas</label><input type="text" name="officer_name" class="form-control" required></div>
      <div class="col-12"><button class="btn btn-primary">Simpan Serah Terima</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Arah</th><th>Uraian</th><th>Kondisi</th><th>Melunasi</th></tr></thead>
      <tbody>
        @forelse ($serahTerima as $s)
          <tr>
            <td class="font-monospace small">{{ $s->handover_number }}</td>
            <td>{{ $s->patient_name }}</td>
            <td><span class="badge bg-{{ $s->kind === 'anggota-tubuh' ? 'purple' : 'blue' }}-lt">{{ $s->kind }}</span></td>
            <td>{{ $s->direction }}</td>
            <td class="small">{{ $s->description }}@if ($s->container_label)<div class="text-secondary">wadah: {{ $s->container_label }}</div>@endif</td>
            <td class="small">{{ $s->condition }}</td>
            <td class="font-monospace small">{{ $s->settles?->handover_number ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada serah terima tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
