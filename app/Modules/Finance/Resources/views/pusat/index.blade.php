@extends('layouts.app')

@section('title', 'Pusat Keuangan')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Pusat Keuangan')

@section('content')

@php
  $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp

{{-- Rentang --}}
<form method="GET" class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2">
    <label class="form-label">Dari</label>
    <input type="date" name="dari" class="form-control" value="{{ $dari }}">
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label">Sampai</label>
    <input type="date" name="sampai" class="form-control" value="{{ $sampai }}">
  </div>
  <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
  <div class="col-12 col-md-6 text-md-end">
    <span class="text-secondary small">
      Neraca disajikan per {{ $sampai }}; laba-rugi sepanjang rentang.
    </span>
  </div>
</form>

{{-- Kesiapan: ditaruh paling atas, sebelum satu pun angka dibaca --}}
@php $belumSiap = collect($kesiapan)->reject(fn ($k) => $k['siap']); @endphp
@if ($belumSiap->isNotEmpty())
  <div class="alert alert-warning">
    <h4 class="alert-title">Laporan di bawah belum bisa dipakai menutup buku</h4>
    <ul class="mb-2 mt-2">
      @foreach ($belumSiap as $k)
        <li><b>{{ $k['judul'] }}</b> &mdash; {{ $k['akibat'] }}</li>
      @endforeach
    </ul>
    <div class="small">
      Disebut di sini, bukan di catatan kaki: laporan yang tersaji rapi akan dipercaya apa adanya,
      dan neraca yang tampak seimbang padahal separuh transaksinya belum masuk jurnal tidak
      memperlihatkan apa pun yang terlihat salah.
    </div>
  </div>
@endif

{{-- Angka pokok --}}
<div class="row row-cards mb-3">
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Total aset</div>
      <div class="h2 mb-0">{{ $rp($neraca->total_aset) }}</div>
      <div class="text-secondary small">per {{ $sampai }}</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Kewajiban + modal</div>
      <div class="h2 mb-0">{{ $rp($neraca->total_kewajiban_modal) }}</div>
      <div class="small {{ $neraca->seimbang ? 'text-success' : 'text-danger' }}">
        {{ $neraca->seimbang ? 'Seimbang' : 'Selisih ' . $rp($neraca->selisih) }}
      </div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Surplus / defisit periode</div>
      <div class="h2 mb-0 {{ $labaRugi->surplus < 0 ? 'text-danger' : '' }}">
        {{ $rp($labaRugi->surplus) }}
      </div>
      <div class="text-secondary small">{{ $dari }} s.d. {{ $sampai }}</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="text-secondary small">Kas periode ini</div>
      <div class="h2 mb-0">{{ $rp(($kas->masuk ?? 0) - ($kas->keluar ?? 0)) }}</div>
      <div class="text-secondary small">
        masuk {{ $rp($kas->masuk ?? 0) }} &middot; keluar {{ $rp($kas->keluar ?? 0) }}
      </div>
    </div></div>
  </div>
</div>

<div class="row g-3">

  {{-- NERACA --}}
  <div class="col-12 col-xl-6">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Neraca</h3>
        <div class="card-subtitle">Posisi keuangan per {{ $sampai }}</div>
      </div>

      @if ($neraca->belum_dijurnal)
        <div class="card-body text-secondary">
          Belum ada jurnal sampai tanggal ini. Ini <b>bukan</b> berarti aset rumah sakit nol &mdash;
          berarti belum ada transaksi yang masuk buku besar.
        </div>
      @else
        <div class="table-responsive">
          <table class="table table-sm card-table">
            <tbody>
              @foreach ([\App\Modules\Finance\Models\Account::GOL_ASET,
                         \App\Modules\Finance\Models\Account::GOL_KEWAJIBAN,
                         \App\Modules\Finance\Models\Account::GOL_MODAL] as $gol)
                <tr class="bg-light">
                  <th colspan="2">{{ \App\Modules\Finance\Models\Account::LABEL_GOLONGAN[$gol] }}</th>
                </tr>
                @forelse ($neraca->kelompok[$gol] ?? [] as $b)
                  <tr>
                    <td><span class="font-monospace text-secondary">{{ $b->code }}</span> {{ $b->name }}</td>
                    <td class="text-end">{{ $rp($b->saldo) }}</td>
                  </tr>
                @empty
                  <tr><td colspan="2" class="text-secondary">Belum ada saldo.</td></tr>
                @endforelse
                <tr>
                  <td class="text-end fw-semibold">Jumlah {{ strtolower(\App\Modules\Finance\Models\Account::LABEL_GOLONGAN[$gol]) }}</td>
                  <td class="text-end fw-semibold">{{ $rp($neraca->total[$gol]) }}</td>
                </tr>
              @endforeach

              <tr>
                <td>Surplus / defisit berjalan</td>
                <td class="text-end">{{ $rp($neraca->surplus_berjalan) }}</td>
              </tr>
              <tr class="border-top">
                <th>Aset</th>
                <th class="text-end">{{ $rp($neraca->total_aset) }}</th>
              </tr>
              <tr>
                <th>Kewajiban + modal + surplus</th>
                <th class="text-end">{{ $rp($neraca->total_kewajiban_modal) }}</th>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card-footer small {{ $neraca->seimbang ? 'text-secondary' : 'text-danger' }}">
          @if ($neraca->seimbang)
            Neraca seimbang. Surplus berjalan ikut dihitung sebagai bagian modal &mdash; tanpa itu
            neraca tidak akan pernah seimbang selama ada pendapatan yang belum ditutup ke modal.
          @else
            <b>Selisih {{ $rp($neraca->selisih) }}.</b> Ada jurnal yang tidak seimbang.
            Angkanya ditampilkan apa adanya supaya ketahuan, bukan disembunyikan lewat pembulatan.
          @endif
        </div>
      @endif
    </div>
  </div>

  {{-- LABA-RUGI --}}
  <div class="col-12 col-xl-6">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Surplus / Defisit</h3>
        <div class="card-subtitle">{{ $dari }} s.d. {{ $sampai }}</div>
      </div>

      @if ($labaRugi->belum_dijurnal)
        <div class="card-body text-secondary">
          Belum ada pendapatan maupun beban terjurnal pada rentang ini.
        </div>
      @else
        <div class="table-responsive">
          <table class="table table-sm card-table">
            <tbody>
              <tr class="bg-light"><th colspan="2">Pendapatan</th></tr>
              @forelse ($labaRugi->pendapatan as $b)
                <tr>
                  <td><span class="font-monospace text-secondary">{{ $b->code }}</span> {{ $b->name }}</td>
                  <td class="text-end">{{ $rp($b->saldo) }}</td>
                </tr>
              @empty
                <tr><td colspan="2" class="text-secondary">Belum ada.</td></tr>
              @endforelse
              <tr>
                <td class="text-end fw-semibold">Jumlah pendapatan</td>
                <td class="text-end fw-semibold">{{ $rp($labaRugi->total_pendapatan) }}</td>
              </tr>

              <tr class="bg-light"><th colspan="2">Beban</th></tr>
              @forelse ($labaRugi->beban as $b)
                <tr>
                  <td><span class="font-monospace text-secondary">{{ $b->code }}</span> {{ $b->name }}</td>
                  <td class="text-end">{{ $rp($b->saldo) }}</td>
                </tr>
              @empty
                <tr><td colspan="2" class="text-secondary">Belum ada.</td></tr>
              @endforelse
              <tr>
                <td class="text-end fw-semibold">Jumlah beban</td>
                <td class="text-end fw-semibold">{{ $rp($labaRugi->total_beban) }}</td>
              </tr>

              <tr class="border-top">
                <th>Surplus / defisit</th>
                <th class="text-end {{ $labaRugi->surplus < 0 ? 'text-danger' : '' }}">
                  {{ $rp($labaRugi->surplus) }}
                </th>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card-footer text-secondary small">
          Disebut <b>surplus/defisit</b>, bukan laba/rugi: RSP UI rumah sakit pendidikan, dan
          menamainya &quot;laba&quot; mengubah cara orang membaca angkanya.
        </div>
      @endif
    </div>
  </div>

  {{-- Hutang & piutang --}}
  <div class="col-12 col-xl-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Umur hutang vendor</h3></div>
      <div class="table-responsive">
        <table class="table table-sm card-table">
          <thead><tr><th>Kelompok umur</th><th class="text-end">Jumlah</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($hutang as $h)
              <tr>
                <td>{{ $h->kelompok ?? $h->bucket ?? '—' }}</td>
                <td class="text-end">{{ $h->jumlah ?? $h->count ?? 0 }}</td>
                <td class="text-end">{{ $rp($h->nilai ?? $h->total ?? 0) }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada hutang berjalan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer"><a href="{{ route('hutang.index') }}">Buka layar Hutang Vendor</a></div>
    </div>
  </div>

  <div class="col-12 col-xl-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Umur piutang non-pasien</h3></div>
      <div class="table-responsive">
        <table class="table table-sm card-table">
          <thead><tr><th>Kelompok umur</th><th class="text-end">Jumlah</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($piutangLain as $p)
              <tr>
                <td>{{ $p->kelompok ?? $p->bucket ?? '—' }}</td>
                <td class="text-end">{{ $p->jumlah ?? $p->count ?? 0 }}</td>
                <td class="text-end">{{ $rp($p->nilai ?? $p->total ?? 0) }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada piutang berjalan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer"><a href="{{ route('piutang-lain.index') }}">Buka layar Piutang &amp; Hutang Lain</a></div>
    </div>
  </div>

  {{-- Neraca saldo --}}
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Neraca saldo</h3>
        <div class="card-subtitle">Bahan baku kedua laporan di atas &mdash; menjawab apakah pembukuannya seimbang</div>
      </div>
      <div class="table-responsive" style="max-height:340px;overflow-y:auto">
        <table class="table table-sm card-table">
          <thead><tr><th>Kode</th><th>Akun</th><th>Golongan</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr></thead>
          <tbody>
            @forelse ($neracaSaldo->baris as $b)
              <tr>
                <td class="font-monospace">{{ $b->code }}</td>
                <td>{{ $b->name }}</td>
                <td class="text-secondary">
                  {{ \App\Modules\Finance\Models\Account::LABEL_GOLONGAN[\App\Modules\Finance\Models\Account::golonganDari($b->type)] ?? $b->type }}
                </td>
                <td class="text-end">{{ $rp($b->debit) }}</td>
                <td class="text-end">{{ $rp($b->credit) }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada jurnal pada rentang ini.</td></tr>
            @endforelse
          </tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="text-end">Jumlah</th>
              <th class="text-end">{{ $rp($neracaSaldo->total_debit) }}</th>
              <th class="text-end">{{ $rp($neracaSaldo->total_kredit) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  {{-- Pintasan --}}
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Layar keuangan lainnya</h3></div>
      <div class="card-body">
        <div class="row g-2">
          @foreach ([
            ['kas.index', 'Kas Harian', 'Pemasukan & pengeluaran, kategori, arus kas'],
            ['buku.index', 'Buku Besar', 'Bagan akun, jurnal, saldo per akun'],
            ['akuntansi.index', 'Akuntansi', 'Pemetaan cara bayar ke akun, tutup periode'],
            ['hutang.index', 'Hutang Vendor', 'Faktur, validasi, pembayaran'],
            ['piutang.index', 'Piutang Pasien', 'Penagihan piutang pasien'],
            ['piutang-lain.index', 'Piutang & Hutang Lain', 'Jasa perusahaan, peminjaman'],
            ['deposit.index', 'Deposit Pasien', 'Titipan uang muka'],
            ['estimasi-ranap.index', 'Perkiraan Biaya Ranap', 'Estimasi sebelum dirawat'],
            ['pengajuan-biaya.index', 'Pengajuan Biaya', 'Ajukan, setujui, cairkan'],
          ] as [$rute, $nama, $isi])
            @if (\Illuminate\Support\Facades\Route::has($rute))
              <div class="col-12 col-md-6 col-xl-4">
                <a href="{{ route($rute) }}" class="card card-sm card-link h-100">
                  <div class="card-body">
                    <div class="fw-semibold">{{ $nama }}</div>
                    <div class="text-secondary small">{{ $isi }}</div>
                  </div>
                </a>
              </div>
            @endif
          @endforeach
        </div>
        <div class="text-secondary small mt-3">
          Pencatatan tetap di layar masing-masing: kewenangan mencatat kas memang berbeda dari
          kewenangan menutup periode, dan menumpuk seluruh formulir di satu halaman membuat
          tidak ada satu pun yang bisa digerbangi terpisah.
        </div>
      </div>
    </div>
  </div>
</div>

@endsection
