@extends('layouts.app')

@section('title', 'Penutupan Shift Kasir')
@section('breadcrumb', 'Konteks billing')
@section('heading', 'Penutupan Shift Kasir')

@section('actions')
  <form method="GET" class="d-flex gap-2">
    <input type="date" name="tanggal" value="{{ $tanggal }}" class="form-control form-control-sm">
    <button class="btn btn-sm">Lihat</button>
  </form>
@endsection

@section('content')

@if ($shift->isEmpty())
  <div class="alert alert-danger">
    <b>Belum ada shift kasir yang didefinisikan.</b> Penutupan tidak bisa dijalankan sama sekali sampai pembagian shift ditetapkan &mdash; dan selama itu tidak ada yang mencocokkan uang di laci dengan pembayaran yang tercatat sistem.
  </div>
@else
  <div class="alert alert-info">
    <p class="mb-1"><b>Yang diketik adalah hasil hitungan fisik laci, bukan angka yang mencocokkan.</b> Jumlah tercatat ditampilkan supaya petugas tahu pembandingnya &mdash; bukan supaya disalin. Selisihnya justru yang dicari.</p>
    <p class="mb-0"><b>Selisih tidak menghalangi penutupan, tapi wajib dijelaskan.</b> Menahan penutupan sampai selisihnya nol akan membuat orang mengetik angka yang cocok alih-alih angka yang ia hitung &mdash; dan sejak itu seluruh catatan kas jadi karangan yang rapi.</p>
  </div>

  <div class="row row-cards mb-3">
    @foreach ($shift as $s)
      @php $t = $tercatat[$s->id] ?? ['tunai' => '0', 'nontunai' => '0']; @endphp
      <div class="col-12 col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">{{ $s->name }}</h3>
            <div class="card-actions text-secondary">
              {{ substr($s->start_time, 0, 5) }}&ndash;{{ substr($s->end_time, 0, 5) }}
              @if ($s->crosses_midnight)<span class="badge bg-blue-lt ms-1">lewat tengah malam</span>@endif
            </div>
          </div>
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-6">
                <div class="form-label">Tunai tercatat sistem</div>
                <div class="h2 mb-0">{{ number_format((float) $t['tunai'], 2, ',', '.') }}</div>
              </div>
              <div class="col-6">
                <div class="form-label">Non-tunai (kartu/QRIS)</div>
                <div class="h3 mb-0 text-secondary">{{ number_format((float) $t['nontunai'], 2, ',', '.') }}</div>
                <div class="form-hint">Tidak masuk laci &mdash; tidak ikut hitungan selisih.</div>
              </div>
            </div>

            <form method="POST" action="{{ route('kasir.penutupan.simpan') }}" class="row g-2">
              @csrf
              <input type="hidden" name="shift_id" value="{{ $s->id }}">
              <input type="hidden" name="business_date" value="{{ $tanggal }}">

              <div class="col-12 col-md-5">
                <label class="form-label">Uang dihitung di laci</label>
                <input type="number" step="0.01" min="0" name="counted_cash" class="form-control" required>
              </div>
              <div class="col-12 col-md-7">
                <label class="form-label">Penjelasan bila ada selisih</label>
                <input type="text" name="variance_reason" class="form-control" placeholder="wajib diisi kalau tidak cocok">
              </div>
              <div class="col-12"><input type="text" name="note" class="form-control" placeholder="Catatan (opsional)"></div>
              <div class="col-12"><button class="btn btn-primary">Tutup Shift</button></div>
            </form>
          </div>
        </div>
      </div>
    @endforeach
  </div>
@endif

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat Penutupan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>Nomor</th><th>Tanggal</th><th>Shift</th><th>Kasir</th>
          <th class="text-end">Dihitung</th><th class="text-end">Tercatat</th>
          <th class="text-end">Selisih</th><th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($riwayat as $r)
          <tr>
            <td><code>{{ $r->closing_number }}</code></td>
            <td>{{ $r->business_date->format('d/m/Y') }}</td>
            <td>{{ $r->shift?->name ?? '—' }}</td>
            <td>{{ $r->cashier_name }}</td>
            <td class="text-end">{{ number_format((float) $r->counted_cash, 2, ',', '.') }}</td>
            <td class="text-end">{{ number_format((float) $r->recorded_cash, 2, ',', '.') }}</td>
            <td class="text-end">
              @if ($r->cocok())
                <span class="badge bg-green-lt">cocok</span>
              @else
                <span class="badge bg-red-lt">{{ number_format((float) $r->selisih(), 2, ',', '.') }}</span>
              @endif
            </td>
            <td class="text-secondary">{{ $r->variance_reason ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-secondary">Belum ada penutupan tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer text-secondary">
    Selisih <b>dihitung</b>, tidak disimpan sebagai kolom &mdash; selisih kas yang dibekukan akan salah begitu salah satu sisinya dikoreksi, dan angka itulah yang dipakai menuduh orang. Jumlah tercatat <b>dibekukan saat menutup</b>: pembayaran yang masuk setelahnya tidak boleh mengubah angka yang sudah ditandatangani petugas.
  </div>
</div>

@endsection
