@extends('layouts.app')

@section('title', 'Penunjang, Gizi & Sasaran')
@section('breadcrumb', 'Konteks reporting')
@section('heading', 'Penunjang, Gizi & Sasaran')

@section('actions')
  <a href="{{ route('reporting.mutu') }}" class="btn btn-link">&larr; Indikator Mutu</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label">Tahun</label><input type="number" name="tahun" class="form-control" value="{{ $tahun }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-3">
        <label class="form-label">Kelompok klasifikasi</label>
        <select name="kelompok" class="form-select">
          @foreach (['bulanan' => 'Per bulan', 'harian' => 'Per hari', 'bangsal' => 'Per bangsal'] as $k => $label)
            <option value="{{ $k }}" @selected($kelompok === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>

<div class="row">
  @foreach ([['Rekap Laboratorium ' . $tahun, $lab], ['Rekap Radiologi ' . $tahun, $radiologi]] as [$judul, $baris])
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">{{ $judul }}</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Bulan</th><th class="text-end">Permintaan</th><th class="text-end">Pasien</th><th class="text-end">Terverifikasi</th></tr></thead>
            <tbody>
              @forelse ($baris as $b)
                <tr><td>{{ $b->bulan }}</td><td class="text-end">{{ $b->permintaan }}</td><td class="text-end">{{ $b->pasien }}</td><td class="text-end">{{ $b->terverifikasi }}</td></tr>
              @empty
                <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada permintaan pada tahun ini.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

<div class="row">
  @foreach ([['Perujuk Laboratorium ' . $tahun, $perujukLab], ['Perujuk Radiologi ' . $tahun, $perujukRadiologi]] as [$judul, $baris])
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">{{ $judul }}</h3><div class="card-subtitle">Permintaan tanpa perujuk tercatat tetap dihitung, tidak dibuang</div></div>
        <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Perujuk</th><th class="text-end">Permintaan</th><th class="text-end">Pasien</th></tr></thead>
            <tbody>
              @forelse ($baris as $b)
                <tr><td>{{ $b->perujuk }}</td><td class="text-end">{{ $b->permintaan }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
              @empty
                <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada permintaan pada tahun ini.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endforeach
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Operasi per Bulan {{ $tahun }}</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Bulan</th><th class="text-end">Tindakan</th><th class="text-end">Pasien</th></tr></thead>
          <tbody>
            @forelse ($operasi as $b)
              <tr><td>{{ $b->bulan }}</td><td class="text-end">{{ $b->tindakan }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada operasi pada tahun ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Rekap Gizi</h3>
        <div class="card-subtitle">Porsi dihitung sebagai <b>hari-diet</b>, bukan jumlah permintaan &mdash; diet lima hari adalah lima hari pemberian</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Macam Diet</th><th class="text-end">Permintaan</th><th class="text-end">Hari-diet</th></tr></thead>
          <tbody>
            @forelse ($diet as $b)
              <tr><td>{{ $b->diet_type }}</td><td class="text-end">{{ $b->permintaan }}</td><td class="text-end">{{ $b->hari_diet }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada permintaan diet pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Skrining Pernapasan Ralan {{ $tahun }}</h3><div class="card-subtitle">Bergejala ditampilkan berdampingan dengan total skriningnya</div></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Bulan</th><th class="text-end">Skrining</th><th class="text-end">Bergejala</th></tr></thead>
          <tbody>
            @forelse ($skrining as $b)
              <tr><td>{{ $b->bulan }}</td><td class="text-end">{{ $b->skrining }}</td><td class="text-end">{{ $b->bergejala }}</td></tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada skrining pada tahun ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Sasaran Usia Produktif &amp; Lansia</h3><div class="card-subtitle">Umur dihitung pada tanggal kunjungan, bukan hari ini</div></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sasaran</th><th>JK</th><th class="text-end">Kunjungan</th><th class="text-end">Pasien</th></tr></thead>
          <tbody>
            @forelse ($sasaran as $b)
              <tr><td>{{ $b->sasaran }}</td><td>{{ $b->sex }}</td><td class="text-end">{{ $b->kunjungan }}</td><td class="text-end">{{ $b->pasien }}</td></tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada kunjungan pada rentang ini.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Klasifikasi Pasien Rawat Inap</h3>
    <div class="card-subtitle">Pasien yang belum diklasifikasi tetap dihitung dan diberi label sendiri, bukan dibuang</div>
  </div>
  <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
    <table class="table table-vcenter card-table">
      <thead><tr><th>{{ ['harian' => 'Tanggal', 'bulanan' => 'Bulan', 'bangsal' => 'Bangsal'][$kelompok] }}</th><th>Klasifikasi</th><th class="text-end">Pasien</th></tr></thead>
      <tbody>
        @forelse ($klasifikasi as $b)
          <tr><td>{{ $b->kelompok }}</td><td>{{ $b->klasifikasi }}</td><td class="text-end">{{ $b->jumlah }}</td></tr>
        @empty
          <tr><td colspan="3" class="text-center text-secondary py-3">Tidak ada admisi pada rentang ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Kepatuhan Daftar Tilik Keselamatan Bedah</h3>
    <div class="card-subtitle">{{ $dari }} s.d. {{ $sampai }} &mdash; KARS Sasaran Keselamatan Pasien IV</div>
  </div>
  <div class="card-body">
    @if ($keselamatanBedah->operasi === 0)
      <p class="text-secondary mb-0">Tidak ada operasi pada rentang ini, jadi kepatuhannya belum terukur.
         Ini <b>bukan</b> 0% dan bukan 100%.</p>
    @else
      <div class="row g-3">
        <div class="col-6 col-md-3">
          <div class="text-secondary small">Operasi</div>
          <div class="h2 mb-0">{{ $keselamatanBedah->operasi }}</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-secondary small">Ketiga fase lengkap</div>
          <div class="h2 mb-0">{{ $keselamatanBedah->lengkap }}</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-secondary small">Kepatuhan</div>
          <div class="h2 mb-0">{{ $keselamatanBedah->persen }}%</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-secondary small">Tanpa daftar tilik sama sekali</div>
          <div class="h2 mb-0 {{ $keselamatanBedah->tanpa_daftar_tilik > 0 ? 'text-danger' : '' }}">
            {{ $keselamatanBedah->tanpa_daftar_tilik }}
          </div>
        </div>
      </div>

      <table class="table table-sm mt-3 mb-2">
        <tbody>
          <tr><td>Tanpa Sign In (sebelum induksi anestesi)</td>
              <td class="text-end">{{ $keselamatanBedah->tanpa_sign_in }}</td></tr>
          <tr><td>Tanpa Time Out (sebelum insisi kulit)</td>
              <td class="text-end">{{ $keselamatanBedah->tanpa_time_out }}</td></tr>
          <tr><td>Tanpa Sign Out (sebelum menutup luka)</td>
              <td class="text-end">{{ $keselamatanBedah->tanpa_sign_out }}</td></tr>
          <tr><td>Daftar tilik yang menyimpan temuan</td>
              <td class="text-end">{{ $keselamatanBedah->bertemuan }}</td></tr>
        </tbody>
      </table>

      <p class="text-secondary small mb-0">
        Penyebutnya <b>seluruh</b> operasi pada rentang ini, termasuk yang daftar tiliknya tidak pernah diisi.
        Menghitung hanya dari operasi yang sudah punya daftar tilik akan membuat angka ini mendekati 100% justru
        saat pengisiannya paling jarang. Daftar tilik yang lengkap sepanjang tahun tapi tidak pernah menemukan
        apa pun juga perlu ditanyakan &mdash; itu tanda pengisiannya formalitas.
      </p>
    @endif
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Penolakan Anjuran Medis {{ $tahun }}</h3>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Bulan</th><th>Jenis surat</th><th class="text-end">Penolakan</th><th class="text-end">Pasien</th></tr></thead>
      <tbody>
        @forelse ($penolakan as $p)
          <tr>
            <td>{{ $p->bulan }}</td>
            <td>{{ str_replace('-', ' ', $p->consent_type) }}</td>
            <td class="text-end">{{ $p->penolakan }}</td>
            <td class="text-end">{{ $p->pasien }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada penolakan tercatat pada {{ $tahun }}.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer text-secondary small">
    Yang dihitung adalah <b>keputusannya</b> &mdash; setiap surat persetujuan yang berakhir "menolak" &mdash; bukan
    hanya surat berjenis penolakan anjuran medis. Pasien yang menolak tindakan yang ditawarkan dan yang pulang atas
    permintaan sendiri sama-sama menolak anjuran medis. Surat yang dibatalkan tidak ikut.
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Yang belum bisa dilaporkan di sini</h3></div>
  <div class="card-body">
    <p class="mb-2">Empat kode domain J sengaja tidak dibuatkan angka karena pencatatannya memang belum ada. Kedua
       yang pertama adalah kewajiban regulasi, jadi layak dibangun lebih dulu daripada dikarang:</p>
    <ul class="mb-0">
      <li><b>Dosis Radiologi</b> &mdash; dosis paparan radiasi per pemeriksaan tidak tercatat. Ini kewajiban proteksi radiasi
          (BAPETEN); butuh kolom dosis pada permintaan radiologi.</li>
      <li><b>Sisa Diet Pasien</b> &mdash; sisa makanan yang tidak dihabiskan tidak dicatat; yang ada baru permintaan dietnya.
          Ini indikator mutu gizi (SPM Kemenkes).</li>
      <li><b>Pemeriksaan Fisik Ralan Per Penyakit</b> &mdash; pemeriksaan fisik dicatat sebagai narasi pada bagian
          Objektif SOAP, bukan butir terstruktur. Menabulasikannya per penyakit akan menghasilkan kumpulan
          paragraf, bukan laporan. Butuh butir pemeriksaan fisik terstruktur lebih dulu.</li>
      <li><b>Rekap Mutasi Berkas</b> dan <b>Status Data RM</b> &mdash; keduanya melacak perpindahan berkas rekam medis
          <b>kertas</b> antar unit. Rekam medis di sini elektronik sejak awal mengikuti Permenkes 24/2022, jadi tidak ada
          berkas yang berpindah. Alasan yang sama seperti Lama Penyiapan RM pada layar indikator mutu.</li>
    </ul>
    <p class="text-secondary small mt-3 mb-0">
      <b>Kepatuhan Kelengkapan Keselamatan Bedah</b> dulu ada di daftar ini dengan alasan "belum dicatat sama
      sekali". Alasan itu benar saat ditulis dan berhenti benar ketika daftar tilik keselamatan bedah dibangun;
      angkanya sekarang ada di atas. Hal yang sama berlaku untuk <b>Penolakan Anjuran Medis</b>.
    </p>
  </div>
</div>

@endsection
