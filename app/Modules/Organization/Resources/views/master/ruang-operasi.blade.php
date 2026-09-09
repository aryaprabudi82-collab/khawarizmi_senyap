@extends('layouts.app')

@section('title', 'Master Ruang Operasi')
@section('breadcrumb', 'Konteks organization')
@section('heading', 'Master Ruang Operasi')

@section('actions')
  @can('tarif_ralan')
    <a href="{{ route('master.organisasi') }}" class="btn btn-link">&larr; Master Organisasi</a>
  @endcan
@endsection

@section('content')

<div class="alert alert-info">
  <p class="mb-1"><b>Kenapa daftar ini ada.</b> Sebelumnya nama ruang operasi diketik bebas di <b>dua</b> tempat sekaligus &mdash; saat menjadwalkan operasi dan saat mencatat laporan operasinya &mdash; jadi ruang yang sama diketik dua kali oleh dua orang berbeda.</p>
  <p class="mb-0"><b>Akibatnya sudah nyata, bukan kekhawatiran.</b> Laporan RL mengelompokkan utilisasi kamar operasi berdasarkan teks itu: "OK 1" dan "OK1" terhitung sebagai dua ruang berbeda pada laporan wajib yang dikirim ke Kemenkes, dan tidak ada satu pun galat yang muncul &mdash; angkanya tetap tampak wajar, hanya saja terbelah.</p>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Tambah Ruang Operasi</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('master.ruang-operasi.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" value="{{ old('code') }}" maxlength="20" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ old('name') }}" maxlength="60" required></div>
      <div class="col-12 col-md-3">
        <label class="form-label">Unit Pemilik</label>
        <select name="unit_id" class="form-select">
          <option value="">— tanpa unit —</option>
          @foreach ($unitAktif as $u)
            <option value="{{ $u->id }}" @selected(old('unit_id') == $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
        <div class="form-hint">Boleh kosong &mdash; sebagian rumah sakit menaruh seluruh kamar operasi di bawah satu instalasi bedah sentral.</div>
      </div>
      <div class="col-12 col-md-4"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control" value="{{ old('note') }}"></div>
      <div class="col-12"><button class="btn btn-primary">Simpan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Daftar Ruang Operasi</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Unit</th><th>Catatan</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @forelse ($ruang as $r)
          <tr>
            <td><code>{{ $r->code }}</code></td>
            <td colspan="4">
              <form method="POST" action="{{ route('master.ruang-operasi.perbarui', $r) }}" class="row g-1">
                @csrf
                <div class="col-12 col-md-3"><input type="text" name="name" class="form-control form-control-sm" value="{{ $r->name }}" required></div>
                <div class="col-12 col-md-3">
                  <select name="unit_id" class="form-select form-select-sm">
                    <option value="">— tanpa unit —</option>
                    @foreach ($unitAktif as $u)
                      <option value="{{ $u->id }}" @selected($r->unit_id === $u->id)>{{ $u->name }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-12 col-md-3"><input type="text" name="note" class="form-control form-control-sm" value="{{ $r->note }}" placeholder="catatan"></div>
                <div class="col-6 col-md-2">
                  <label class="form-check form-switch mt-1">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($r->is_active)>
                    <span class="form-check-label">Aktif</span>
                  </label>
                </div>
                <div class="col-6 col-md-1"><button class="btn btn-sm w-100">Simpan</button></div>
              </form>
            </td>
            <td></td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-secondary">Belum ada ruang operasi terdaftar. Daftar ini sengaja lahir kosong: berapa kamar operasi RSP UI dan bagaimana penomorannya adalah kenyataan fisik gedung yang tidak bisa ditebak dari luar &mdash; menebaknya berarti menyediakan pilihan yang tidak ada di gedungnya, lalu jadwal operasi menunjuk ruang yang tidak pernah dibangun.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer text-secondary">
    <b>Kode tidak bisa diubah.</b> Operasi dan jadwal yang sudah tercatat menunjuk ruang lewat kodenya; mengubah kode berarti seluruh riwayat menunjuk ruang yang tidak ada lagi, dan laporan utilisasinya berhenti di tanggal perubahan tanpa ada yang memberi tahu. Ruang yang salah kode dinonaktifkan, lalu yang benar dibuat baru.
  </div>
</div>

@endsection
