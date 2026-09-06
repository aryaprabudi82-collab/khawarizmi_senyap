@extends('layouts.app')

@section('title', 'Piutang & Hutang Lain')
@section('breadcrumb', 'Konteks finance')
@section('heading', 'Piutang & Hutang Lain')

@section('actions')
  <a href="{{ route('hutang.index') }}" class="btn btn-link">Hutang Vendor &rarr;</a>
@endsection

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info">
  <b>Layar ini memuat dua arah yang berlawanan, dan keduanya tidak pernah dijumlahkan.</b>
  <b>Piutang</b> &mdash; jasa perusahaan dan peminjaman uang &mdash; adalah uang yang akan masuk.
  <b>Beban hutang lain</b> adalah uang yang akan keluar, dan disimpan di buku hutang yang sama dengan
  hutang vendor karena bentuknya memang sama. Khanza menaruh keduanya di satu menu; di sini keduanya
  tetap satu layar tapi mekanismenya terpisah sesuai arahnya.
</div>

@if ($belumDipetakan > 0)
  <div class="alert alert-warning">
    <b>Rp {{ number_format($belumDipetakan, 2, ',', '.') }} piutang belum terpetakan ke bagan akun.</b>
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-4">
        <label class="form-label">Jenis piutang</label>
        <select name="jenis" class="form-select">
          <option value="">Semua jenis</option>
          @foreach ($daftarJenis as $k => $label)
            <option value="{{ $k }}" @selected($jenis === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Bayar dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per Jenis Piutang</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Jenis</th><th class="text-end">Nilai</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($perJenis as $b)
              <tr>
                <td>{{ $daftarJenis[$b->kind] ?? $b->kind }}</td>
                <td class="text-end">{{ number_format($b->nilai, 0, ',', '.') }}</td>
                <td class="text-end"><b>{{ number_format($b->sisa, 0, ',', '.') }}</b></td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada piutang.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Umur Piutang</h3><div class="card-subtitle">Yang belum jatuh tempo dipisahkan</div></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kelompok</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($umur as $b)
              <tr>
                <td class="{{ $b->kelompok === 'lebih dari 90 hari' ? 'text-danger' : '' }}">{{ $b->kelompok }}</td>
                <td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Per Pihak</h3></div>
      <div class="table-responsive" style="max-height:240px; overflow-y:auto;">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Pihak</th><th class="text-end">Sisa</th></tr></thead>
          <tbody>
            @forelse ($perPihak as $b)
              <tr><td>{{ $b->debtor_name }}</td><td class="text-end">{{ number_format($b->sisa, 0, ',', '.') }}</td></tr>
            @empty
              <tr><td colspan="2" class="text-center text-secondary py-3">&mdash;</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Piutang Berjalan</h3><div class="card-subtitle">Sisa selalu dihitung dari nilai dikurangi pembayaran</div></div>
  <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Pihak</th><th>Jenis</th><th>Jatuh tempo</th><th class="text-end">Nilai</th><th class="text-end">Sisa</th><th style="width:30%"></th></tr></thead>
      <tbody>
        @forelse ($terutang as $b)
          <tr>
            <td class="font-monospace small">{{ $b->receivable_number }}</td>
            <td>{{ $b->debtor_name }}</td>
            <td class="small text-secondary">{{ $daftarJenis[$b->kind] ?? $b->kind }}</td>
            <td class="{{ $b->due_date < now()->toDateString() ? 'text-danger' : '' }}">{{ $b->due_date }}</td>
            <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
            <td class="text-end"><b>{{ number_format($b->sisa, 0, ',', '.') }}</b></td>
            <td>
              <div class="d-flex gap-1">
                <form method="POST" action="{{ route('piutang-lain.bayar', $b->id) }}" class="d-flex gap-1">
                  @csrf
                  <input name="paid_on" type="date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                  <input name="amount" type="number" step="0.01" class="form-control form-control-sm" placeholder="Nilai" required>
                  <button class="btn btn-sm btn-primary">Terima</button>
                </form>
                <form method="POST" action="{{ route('piutang-lain.hapuskan', $b->id) }}" class="d-flex gap-1">
                  @csrf
                  <input name="reason" class="form-control form-control-sm" placeholder="Alasan hapus" required>
                  <button class="btn btn-sm btn-outline-danger">Hapuskan</button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Tidak ada piutang berjalan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Beban Hutang Lain</h3>
    <div class="card-subtitle">Arah sebaliknya &mdash; RS yang berhutang. Langsung diakui tanpa alur validasi karena tidak ada vendor yang menitipkan apa pun</div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Kepada</th><th>Jatuh tempo</th><th class="text-end">Nilai</th><th class="text-end">Sisa</th><th style="width:26%"></th></tr></thead>
      <tbody>
        @forelse ($hutangLain as $b)
          <tr>
            <td class="font-monospace small">{{ $b->payable_number }}</td>
            <td>{{ $b->supplier_name }}</td>
            <td class="{{ $b->due_date < now()->toDateString() ? 'text-danger' : '' }}">{{ $b->due_date }}</td>
            <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
            <td class="text-end"><b>{{ number_format($b->sisa, 0, ',', '.') }}</b></td>
            <td>
              <form method="POST" action="{{ route('piutang-lain.bayar-hutang', $b->id) }}" class="d-flex gap-1">
                @csrf
                <input name="paid_on" type="date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                <input name="amount" type="number" step="0.01" class="form-control form-control-sm" placeholder="Nilai" required>
                <button class="btn btn-sm btn-primary">Bayar</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada beban hutang lain.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Piutang</h3></div>
      <form method="POST" action="{{ route('piutang-lain.simpan') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Kategori</label>
            <select name="category_id" class="form-select" required>
              @foreach ($kategori->where('is_active', true) as $k)
                <option value="{{ $k->id }}">{{ $k->name }} &mdash; {{ $daftarJenis[$k->kind] ?? $k->kind }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-12 col-md-7"><label class="form-label">Pihak yang berhutang</label><input name="debtor_name" class="form-control" required></div>
          <div class="col-12 col-md-5"><label class="form-label">Kontak</label><input name="debtor_contact" class="form-control"></div>
          <div class="col-6 col-md-4"><label class="form-label">Tanggal</label><input name="issued_on" type="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6 col-md-4"><label class="form-label">Jatuh tempo</label><input name="due_date" type="date" class="form-control" value="{{ now()->addDays(30)->toDateString() }}" required></div>
          <div class="col-6 col-md-4"><label class="form-label">Nilai (Rp)</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
          <div class="col-12 col-md-5"><label class="form-label">No. rujukan</label><input name="reference_number" class="form-control"></div>
          <div class="col-12"><label class="form-label">Uraian</label><input name="description" class="form-control" required></div>
        </div>
        <button class="btn btn-primary mt-3">Simpan Piutang</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Beban Hutang Lain</h3></div>
      <form method="POST" action="{{ route('piutang-lain.simpan-hutang') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-12"><label class="form-label">Kepada (pemberi hutang)</label><input name="supplier_name" class="form-control" required></div>
          <div class="col-12 col-md-6"><label class="form-label">No. perjanjian</label><input name="invoice_number" class="form-control" required></div>
          <div class="col-6 col-md-6"><label class="form-label">Nilai (Rp)</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
          <div class="col-6"><label class="form-label">Tanggal</label><input name="invoice_date" type="date" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6"><label class="form-label">Jatuh tempo</label><input name="due_date" type="date" class="form-control" value="{{ now()->addDays(30)->toDateString() }}" required></div>
        </div>
        <button class="btn btn-primary mt-3">Simpan Beban Hutang</button>
      </form>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Tambah Kategori Piutang</h3></div>
      <form method="POST" action="{{ route('piutang-lain.kategori') }}" class="card-body">
        @csrf
        <div class="row g-2">
          <div class="col-5"><label class="form-label">Kode</label><input name="code" class="form-control" required></div>
          <div class="col-7">
            <label class="form-label">Jenis</label>
            <select name="kind" class="form-select" required>
              @foreach ($daftarJenis as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
          </div>
          <div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
        </div>
        <button class="btn btn-primary mt-3">Tambah Kategori</button>
      </form>
    </div>
  </div>
</div>

@if ($dihapuskan->isNotEmpty())
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">Piutang Dihapuskan</h3>
      <div class="card-subtitle">Tidak ikut dihitung, tapi tetap terlihat &mdash; penghapusan yang menumpuk adalah gejala penagihan yang tidak berjalan</div>
    </div>
    <div class="table-responsive" style="max-height:240px; overflow-y:auto;">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Nomor</th><th>Pihak</th><th class="text-end">Nilai</th><th>Alasan</th></tr></thead>
        <tbody>
          @foreach ($dihapuskan as $b)
            <tr>
              <td class="font-monospace small">{{ $b->receivable_number }}</td>
              <td>{{ $b->debtor_name }}</td>
              <td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td>
              <td class="text-secondary small">{{ $b->write_off_reason }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

<div class="card">
  <div class="card-header"><h3 class="card-title">Penerimaan {{ $dari }} &mdash; {{ $sampai }}</h3></div>
  <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Tanggal</th><th>Pihak</th><th>Jenis</th><th class="text-end">Nilai</th></tr></thead>
      <tbody>
        @forelse ($pembayaran as $b)
          <tr><td>{{ $b->paid_on }}</td><td>{{ $b->debtor_name }}</td><td class="small text-secondary">{{ $daftarJenis[$b->kind] ?? $b->kind }}</td><td class="text-end">{{ number_format($b->amount, 0, ',', '.') }}</td></tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada penerimaan pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
