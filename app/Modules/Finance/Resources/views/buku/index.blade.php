@extends('layouts.app')

@section('title', 'Bagan Akun & Buku Besar')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Bagan Akun & Buku Besar')

@section('actions')
  <a href="{{ route('akuntansi.index') }}" class="btn btn-link">Akuntansi &rarr;</a>
@endsection

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Bagan akun inilah yang membuat pemetaan di layar kas, hutang, dan piutang bisa diisi.</b>
  Sebelum layar ini ada, seluruh peringatan &quot;belum dipetakan ke bagan akun&quot; tidak mungkin
  diselesaikan siapa pun &mdash; tidak ada akun yang bisa dipilih dan tidak ada cara membuatnya.
</div>

@unless ($neraca->seimbang)
  <div class="alert alert-danger">
    <b>Neraca saldo tidak seimbang: selisih Rp {{ number_format($neraca->selisih, 2, ',', '.') }}.</b>
    Ada jurnal yang debit dan kreditnya tidak sama. Angkanya ditampilkan apa adanya supaya ketahuan,
    bukan disembunyikan di balik pembulatan.
  </div>
@endunless

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Tahun buku</label><input type="number" name="tahun" class="form-control" value="{{ $tahun }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis akun</label>
        <select name="jenis" class="form-select">
          <option value="">Semua</option>
          @foreach ($daftarJenis as $j)<option value="{{ $j }}" @selected($jenis === $j)>{{ $j }}</option>@endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Buku besar akun</label>
        <select name="akun" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($akun as $a)
            <option value="{{ $a->id }}" @selected($akunTerpilih && $akunTerpilih->id === $a->id)>{{ $a->code }} {{ $a->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

@if ($bukuBesar)
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">Buku Besar &mdash; {{ $akunTerpilih->code }} {{ $akunTerpilih->name }}</h3>
      <div class="card-subtitle">
        Arah normal <b>{{ $bukuBesar['arah_normal'] }}</b> &mdash; saldo disajikan menurut arah itu, bukan bertanda,
        supaya akun kredit-normal tidak tampil negatif
      </div>
    </div>
    <div class="card-body border-bottom">
      <div class="row g-3">
        @foreach ([
          ['Saldo awal tahun', $bukuBesar['saldo_awal_tahun']],
          ['Saldo pembuka rentang', $bukuBesar['saldo_pembuka']],
          ['Total debit', $bukuBesar['total_debit']],
          ['Total kredit', $bukuBesar['total_kredit']],
          ['Saldo akhir', $bukuBesar['saldo_akhir']],
        ] as [$judul, $nilai])
          <div class="col-6 col-lg">
            <div class="text-secondary small">{{ $judul }}</div>
            <div class="h3 mb-0">{{ number_format($nilai, 0, ',', '.') }}</div>
          </div>
        @endforeach
      </div>
    </div>
    <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Tanggal</th><th>Nomor</th><th>Uraian</th><th class="text-end">Debit</th><th class="text-end">Kredit</th><th class="text-end">Saldo</th></tr></thead>
        <tbody>
          @forelse ($bukuBesar['mutasi'] as $b)
            <tr>
              <td>{{ $b->entry_date }}</td>
              <td class="font-monospace small">{{ $b->entry_number }}</td>
              <td>{{ $b->baris_uraian ?: $b->description }}</td>
              <td class="text-end">{{ $b->debit > 0 ? number_format($b->debit, 0, ',', '.') : '' }}</td>
              <td class="text-end">{{ $b->credit > 0 ? number_format($b->credit, 0, ',', '.') : '' }}</td>
              <td class="text-end"><b>{{ number_format($b->saldo, 0, ',', '.') }}</b></td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada mutasi pada rentang ini.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endif

<div class="row">
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Neraca Saldo</h3><div class="card-subtitle">Selisih wajib nol</div></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Akun</th><th>Jenis</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr></thead>
          <tbody>
            @forelse ($neraca->baris as $b)
              <tr>
                <td class="font-monospace small">{{ $b->code }}</td>
                <td>{{ $b->name }}</td>
                <td class="small text-secondary">{{ $b->type }}</td>
                <td class="text-end">{{ number_format($b->debit, 0, ',', '.') }}</td>
                <td class="text-end">{{ number_format($b->credit, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada jurnal pada rentang ini.</td></tr>
            @endforelse
          </tbody>
          <tfoot>
            <tr class="fw-bold">
              <td colspan="3">Total</td>
              <td class="text-end">{{ number_format($neraca->total_debit, 0, ',', '.') }}</td>
              <td class="text-end">{{ number_format($neraca->total_kredit, 0, ',', '.') }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Jurnal Harian</h3></div>
      <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Nomor</th><th>Sumber</th><th class="text-end">Nilai</th></tr></thead>
          <tbody>
            @forelse ($jurnal as $b)
              <tr>
                <td>{{ $b->entry_date }}</td>
                <td class="font-monospace small">{{ $b->entry_number }}</td>
                <td class="small text-secondary">{{ $b->reference_type }}</td>
                <td class="text-end">{{ number_format($b->debit, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada jurnal.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Bagan Akun</h3><div class="card-subtitle">Akun dinonaktifkan, tidak pernah dihapus</div></div>
      <div class="table-responsive" style="max-height:280px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th></th></tr></thead>
          <tbody>
            @foreach ($akun as $a)
              <tr class="{{ $a->is_active ? '' : 'text-secondary' }}">
                <td class="font-monospace small">{{ $a->code }}</td>
                <td>{{ $a->name }} @unless($a->is_active)<span class="badge bg-secondary">nonaktif</span>@endunless</td>
                <td class="small text-secondary">{{ $a->type }}</td>
                <td class="text-end">
                  @if ($a->is_active)
                    <form method="POST" action="{{ route('buku.akun.nonaktif', $a->id) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-secondary">Nonaktifkan</button>
                    </form>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <form method="POST" action="{{ route('buku.akun.simpan') }}" class="card-body border-top">
        @csrf
        <div class="row g-2">
          <div class="col-4"><label class="form-label">Kode</label><input name="code" class="form-control" required></div>
          <div class="col-8"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
          <div class="col-12">
            <label class="form-label">Jenis</label>
            <select name="type" class="form-select" required>
              @foreach ($daftarJenis as $j)<option value="{{ $j }}">{{ $j }}</option>@endforeach
            </select>
          </div>
        </div>
        <button class="btn btn-primary mt-3">Tambah Akun</button>
      </form>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Saldo Awal Tahun {{ $tahun }}</h3><div class="card-subtitle">Menghitung ulang mengganti, bukan menambah</div></div>
      <div class="table-responsive" style="max-height:200px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Akun</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr></thead>
          <tbody>
            @forelse ($saldoAwal as $b)
              <tr><td class="small">{{ $b->code }} {{ $b->name }}</td><td class="text-end">{{ number_format($b->opening_debit, 0, ',', '.') }}</td><td class="text-end">{{ number_format($b->opening_credit, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada saldo awal untuk tahun ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <form method="POST" action="{{ route('buku.saldo-awal') }}" class="card-body border-top">
        @csrf
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Akun</label>
            <select name="account_id" class="form-select" required>
              @foreach ($akunAktif as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-4"><label class="form-label">Tahun</label><input name="fiscal_year" type="number" class="form-control" value="{{ $tahun }}" required></div>
          <div class="col-4"><label class="form-label">Debit</label><input name="opening_debit" type="number" step="0.01" min="0" class="form-control" value="0"></div>
          <div class="col-4"><label class="form-label">Kredit</label><input name="opening_credit" type="number" step="0.01" min="0" class="form-control" value="0"></div>
        </div>
        <button class="btn btn-primary mt-3">Simpan Saldo Awal</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Posting Jurnal Manual</h3>
        <div class="card-subtitle">Wajib seimbang, dan tidak bisa masuk periode yang sudah ditutup</div>
      </div>
      <form method="POST" action="{{ route('buku.jurnal') }}" class="card-body">
        @csrf
        <div class="row g-2 mb-3">
          <div class="col-12 col-md-4"><label class="form-label">Tanggal</label><input name="entry_date" type="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-12 col-md-8"><label class="form-label">Uraian</label><input name="description" class="form-control" required></div>
        </div>
        <table class="table table-sm">
          <thead><tr><th>Akun</th><th class="text-end">Debit</th><th class="text-end">Kredit</th><th>Keterangan</th></tr></thead>
          <tbody>
            @for ($i = 0; $i < 4; $i++)
              <tr>
                <td>
                  <select name="lines[{{ $i }}][account_id]" class="form-select form-select-sm">
                    @foreach ($akunAktif as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach
                  </select>
                </td>
                <td><input name="lines[{{ $i }}][debit]" type="number" step="0.01" min="0" class="form-control form-control-sm text-end"></td>
                <td><input name="lines[{{ $i }}][credit]" type="number" step="0.01" min="0" class="form-control form-control-sm text-end"></td>
                <td><input name="lines[{{ $i }}][description]" class="form-control form-control-sm"></td>
              </tr>
            @endfor
          </tbody>
        </table>
        <button class="btn btn-primary">Posting Jurnal</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Saldo Akun per Bulan {{ $tahun }}</h3></div>
      <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Bulan</th><th>Akun</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr></thead>
          <tbody>
            @forelse ($perBulan as $b)
              <tr><td>{{ $b->bulan }}</td><td class="small">{{ $b->code }} {{ $b->name }}</td><td class="text-end">{{ number_format($b->debit, 0, ',', '.') }}</td><td class="text-end">{{ number_format($b->credit, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada mutasi pada tahun ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
