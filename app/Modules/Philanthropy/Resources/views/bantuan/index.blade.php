@extends('layouts.app')

@section('title', 'ZIS — Bantuan Dana Kesehatan')
@section('breadcrumb', 'Konteks philanthropy')
@section('heading', 'Penerima, Asesmen &amp; Penyaluran Dana Kesehatan')

@section('actions')
  @can('zis_kategori_asnaf_penerima_dankes')
    <a href="{{ route('philanthropy.kriteria.index') }}" class="btn btn-link">&larr; Kriteria Asesmen</a>
  @endcan
@endsection

@php use App\Modules\Philanthropy\Models\Disbursement; @endphp

@section('content')

@if ($kategoriKosong !== [])
  <div class="alert alert-warning">
    <b>{{ count($kategoriKosong) }} dari 16 kategori asesmen belum punya kosakata</b> &mdash; pertanyaannya tidak akan muncul di formulir survei sampai kriterianya disusun amil.
    @can('zis_kategori_asnaf_penerima_dankes')
      <a href="{{ route('philanthropy.kriteria.index') }}">Susun sekarang &rarr;</a>
    @endcan
  </div>
@endif

<div class="row row-cards mb-3">
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Daftarkan Calon Penerima</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('philanthropy.bantuan.penerima.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12 col-md-6"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" value="{{ old('name') }}" required></div>
          <div class="col-6 col-md-3"><label class="form-label">NIK</label><input type="text" name="id_number" class="form-control" value="{{ old('id_number') }}"></div>
          <div class="col-6 col-md-3">
            <label class="form-label">Jenis Kelamin</label>
            <select name="sex" class="form-select"><option value="">—</option><option value="L">L</option><option value="P">P</option></select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label">Tanggal Lahir</label><input type="date" name="birth_date" class="form-control" value="{{ old('birth_date') }}"></div>
          <div class="col-6 col-md-3"><label class="form-label">No. Telepon</label><input type="text" name="phone" class="form-control" value="{{ old('phone') }}"></div>
          <div class="col-12 col-md-6"><label class="form-label">Alamat</label><input type="text" name="address" class="form-control" value="{{ old('address') }}"></div>
          <div class="col-12 col-md-4">
            <label class="form-label">No. Rekam Medis</label>
            <input type="text" name="patient_mrn" class="form-control" value="{{ old('patient_mrn') }}">
            <div class="form-hint">Boleh kosong &mdash; yang meminta bantuan sering belum jadi pasien.</div>
          </div>
          <div class="col-12"><button class="btn btn-primary">Daftarkan</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Rekap Penyaluran</h3>
        <div class="card-actions">
          <form method="GET" class="d-flex gap-1">
            <input type="date" name="dari" value="{{ $periode['dari'] }}" class="form-control form-control-sm">
            <input type="date" name="sampai" value="{{ $periode['sampai'] }}" class="form-control form-control-sm">
            <button class="btn btn-sm">Lihat</button>
          </form>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sumber</th><th class="text-end">Jumlah</th><th class="text-end">Penerima</th><th class="text-end">Kali</th></tr></thead>
          <tbody>
            @forelse ($rekap as $sumber => $r)
              <tr>
                <td>{{ ucfirst($sumber) }}</td>
                <td class="text-end">{{ number_format((float) $r['jumlah'], 2, ',', '.') }}</td>
                <td class="text-end">{{ $r['penerima'] }}</td>
                <td class="text-end">{{ $r['penyaluran'] }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-secondary">Belum ada penyaluran pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if ($berulang !== [])
        <div class="card-body border-top">
          <div class="form-label">Penerima lebih dari sekali</div>
          <div class="form-hint mb-2">Bukan tuduhan &mdash; bantuan berulang sering memang wajar. Tapi inilah satu-satunya tempat penerimaan ganda bisa terlihat, dan pada Khanza ia tidak bisa terlihat sama sekali karena penerimanya tidak dicatat.</div>
          <ul class="mb-0">
            @foreach ($berulang as $b)
              <li>{{ $b['nama'] }} ({{ $b['nomor'] }}) &mdash; {{ $b['kali'] }}&times;, total {{ number_format((float) $b['jumlah'], 2, ',', '.') }}</li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Penerima Terdaftar</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Nomor</th><th>Nama</th><th>Alamat</th><th>Golongan Asnaf</th><th>Total Diterima</th><th></th></tr></thead>
      <tbody>
        @forelse ($penerima as $p)
          <tr>
            <td><code>{{ $p->recipient_number }}</code></td>
            <td>{{ $p->name }}</td>
            <td class="text-secondary">{{ $p->address ?: '—' }}</td>
            <td>
              @if ($p->asnaf)
                {{ $p->asnaf->name }}
              @else
                <form method="POST" action="{{ route('philanthropy.bantuan.penerima.asnaf', $p) }}" class="d-flex gap-1">
                  @csrf
                  <select name="asnaf_criteria_id" class="form-select form-select-sm" required>
                    <option value="">— belum ditetapkan —</option>
                    @foreach ($asnaf as $a)
                      <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                  </select>
                  <button class="btn btn-sm">Set</button>
                </form>
              @endif
            </td>
            <td class="text-end">{{ number_format((float) $p->totalDiterima(), 2, ',', '.') }}</td>
            <td class="text-end">
              <form method="POST" action="{{ route('philanthropy.bantuan.asesmen.buka', $p) }}" class="d-flex gap-1 justify-content-end">
                @csrf
                <input type="date" name="assessed_on" value="{{ now()->toDateString() }}" class="form-control form-control-sm" style="max-width:150px" required>
                <input type="text" name="surveyor_name" placeholder="nama surveyor" class="form-control form-control-sm" style="max-width:170px" required>
                <button class="btn btn-sm btn-primary">Asesmen</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-secondary">Belum ada penerima terdaftar.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer text-secondary">
    Golongan asnaf wajib ditetapkan sebelum seseorang bisa menerima <b>zakat</b> &mdash; zakat di luar delapan golongan tidak sah sebagai zakat, dan itu syarat dari luar rumah sakit, bukan kebijakan yang boleh dilonggarkan panitia. Infak, sedekah, dan CSR tidak terikat syarat itu.
  </div>
</div>

<div class="row row-cards">
  <div class="col-12 col-xl-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Asesmen Terakhir</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Nomor</th><th>Penerima</th><th>Tanggal</th><th>Putusan</th><th></th></tr></thead>
          <tbody>
            @forelse ($asesmen as $a)
              <tr>
                <td><code>{{ $a->assessment_number }}</code></td>
                <td>{{ $a->recipient->name }}</td>
                <td>{{ $a->assessed_on->format('d/m/Y') }}</td>
                <td>
                  @if ($a->decision === 'layak')<span class="badge bg-green-lt">layak</span>
                  @elseif ($a->decision === 'tidak-layak')<span class="badge bg-red-lt">tidak layak</span>
                  @else<span class="badge bg-secondary-lt">belum diputuskan</span>@endif
                </td>
                <td class="text-end"><a href="{{ route('philanthropy.bantuan.asesmen', $a) }}" class="btn btn-sm">Buka</a></td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-secondary">Belum ada asesmen.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Penyaluran Terakhir</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Nomor</th><th>Penerima</th><th>Tanggal</th><th>Sumber</th><th class="text-end">Jumlah</th><th>Peruntukan</th></tr></thead>
          <tbody>
            @forelse ($penyaluran as $s)
              <tr>
                <td><code>{{ $s->disbursement_number }}</code></td>
                <td>{{ $s->recipient->name }}</td>
                <td>{{ $s->disbursed_on->format('d/m/Y') }}</td>
                <td>{{ ucfirst($s->fund_source) }}</td>
                <td class="text-end">{{ number_format((float) $s->amount, 2, ',', '.') }}</td>
                <td class="text-secondary">{{ $s->purpose }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-secondary">Belum ada penyaluran.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer text-secondary">
        Setiap penyaluran menyebut <b>penerima</b> dan <b>asesmen</b> yang mendasarinya. <code>ambil_dankes</code> Khanza hanya berisi tanggal, kategori, dan jumlah &mdash; bantuan yang tercatat tanpa penerima tidak bisa diaudit dan tidak bisa menjawab pertanyaan yang paling wajar dari seorang amil: apakah keluarga ini pernah dibantu.
      </div>
    </div>
  </div>
</div>

@endsection
