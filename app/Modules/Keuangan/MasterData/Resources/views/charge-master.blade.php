@extends('layouts.app')

@section('title', 'Charge Description Master')
@section('breadcrumb', 'Master Keuangan — Modul A')
@section('heading', 'Charge Description Master')

@section('actions')
  <a href="{{ route('master-keuangan.index') }}" class="btn btn-link">&larr; Master Keuangan</a>
@endsection

@section('content')

@include('keuangan_master::_pesan')

<form method="GET" class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2">
    <label class="form-label">Berlaku pada</label>
    <input type="date" name="tanggal" class="form-control" value="{{ $tanggal }}">
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label">Golongan</label>
    <select name="golongan" class="form-select">
      <option value="">Semua golongan</option>
      @foreach ($golonganTersedia as $kode => $label)
        <option value="{{ $kode }}" @selected($golongan === $kode)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
  <div class="col-12 col-md-5 text-md-end text-secondary small">
    Berperiode: yang tampil adalah item yang berlaku pada tanggal itu, bukan yang berlaku hari ini.
  </div>
</form>

@if ($belumDipetakan->isNotEmpty())
  <div class="alert alert-warning">
    <b>{{ $belumDipetakan->count() }} item belum punya akun pendapatan</b> sehingga tidak bisa
    diaktifkan dan tidak bisa ditagihkan sama sekali. Item aktif tanpa akun berarti ada uang masuk
    yang tidak pernah sampai ke buku besar &mdash; dan selisihnya baru ketahuan berbulan-bulan
    kemudian saat ada yang menutup buku.
  </div>
@endif

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Item berlaku pada {{ $tanggal }}</h3>
    <div class="card-actions text-secondary small">{{ $item->count() }} item</div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>Kode</th><th>Nama</th><th>Golongan</th><th>Sumber tarif</th>
          <th>Akun pendapatan</th><th>Status</th><th class="w-1">Tindakan</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($item as $i)
          <tr>
            <td class="font-monospace small">{{ $i->code }}</td>
            <td>{{ $i->name }}</td>
            <td><span class="badge bg-secondary-lt">{{ $i->golongan }}</span></td>
            <td class="font-monospace small text-secondary">
              {{ $i->source_context }}@if ($i->source_id)#{{ $i->source_id }}@endif
            </td>
            <td>
              @if ($i->revenue_account_id)
                <span class="badge bg-green-lt">terpetakan</span>
              @else
                <span class="badge bg-red-lt">belum</span>
              @endif
            </td>
            <td>
              @if ($i->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-yellow-lt">Nonaktif</span>
              @endif
            </td>
            <td>
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#petakan-{{ $i->id }}">Akun</button>

              @unless ($i->is_active)
                <form method="POST" action="{{ route('master-keuangan.item.aktifkan', $i->id) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-success">Aktifkan</button>
                </form>
              @endunless
            </td>
          </tr>

          {{-- Modal pemetaan akun --}}
          <div class="modal fade" id="petakan-{{ $i->id }}" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST" action="{{ route('master-keuangan.item.petakan', $i->id) }}">
                  @csrf
                  <div class="modal-header">
                    <h5 class="modal-title">Pemetaan akun &mdash; {{ $i->code }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p class="text-secondary small">
                      Hanya akun yang <b>boleh dijurnal</b> yang bisa dipilih. Akun ikhtisar yang
                      menjumlahkan anaknya sengaja tidak ikut: saldonya akan terhitung dua kali
                      sementara neraca tetap seimbang, sehingga tidak ada yang terlihat salah.
                    </p>

                    <div class="mb-3">
                      <label class="form-label required">Akun pendapatan</label>
                      <select name="revenue_account_id" class="form-select" required>
                        <option value="">— pilih —</option>
                        @foreach ($akun as $a)
                          <option value="{{ $a->id }}" @selected($i->revenue_account_id == $a->id)>
                            {{ $a->code }} — {{ $a->name }}
                          </option>
                        @endforeach
                      </select>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">
                        Akun beban pokok
                        @if (in_array($i->golongan, ['obat', 'bhp', 'alkes'], true))
                          <span class="text-danger">(wajib untuk {{ $i->golongan }})</span>
                        @endif
                      </label>
                      <select name="cogs_account_id" class="form-select">
                        <option value="">— tidak ada —</option>
                        @foreach ($akun as $a)
                          <option value="{{ $a->id }}" @selected($i->cogs_account_id == $a->id)>
                            {{ $a->code }} — {{ $a->name }}
                          </option>
                        @endforeach
                      </select>
                      @if (in_array($i->golongan, ['obat', 'bhp', 'alkes'], true))
                        <div class="form-hint">
                          Tanpa akun beban pokok, HPP-nya tidak bisa dijurnalkan dan nilai persediaan
                          di buku besar akan terus melenceng dari gudang.
                        </div>
                      @endif
                    </div>

                    <div class="mb-0">
                      <label class="form-label">Akun potongan</label>
                      <select name="discount_account_id" class="form-select">
                        <option value="">— tidak ada —</option>
                        @foreach ($akun as $a)
                          <option value="{{ $a->id }}" @selected($i->discount_account_id == $a->id)>
                            {{ $a->code }} — {{ $a->name }}
                          </option>
                        @endforeach
                      </select>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-link" data-bs-dismiss="modal">Batal</button>
                    <button class="btn btn-primary">Simpan pemetaan</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        @empty
          <tr>
            <td colspan="7" class="text-center text-secondary py-4">
              Belum ada item yang berlaku pada {{ $tanggal }}.
              <a href="{{ route('master-keuangan.index') }}">Tautkan tarif lebih dulu.</a>
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
