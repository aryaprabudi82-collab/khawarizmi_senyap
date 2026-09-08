@extends('layouts.app')

@section('title', 'Tata Usaha — Surat Keterangan')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Surat Keterangan Medis')

@section('actions')
  <a href="{{ route('correspondence.persetujuan.index') }}" class="btn btn-link">&larr; Persetujuan Tindakan</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Terbitkan Surat</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('correspondence.keterangan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis</label>
        <select name="certificate_type" class="form-select" required>
          <option value="sehat">Keterangan Sehat</option>
          <option value="sakit">Keterangan Sakit</option>
          <option value="sakit_pihak_kedua">Keterangan Sakit (Pihak Kedua)</option>
          <option value="berobat">Keterangan Berobat</option>
          <option value="rawat_inap">Keterangan Rawat Inap</option>
          <option value="bebas_narkoba">Pemeriksaan Narkoba</option>
          <option value="bebas_tbc">Pemeriksaan TBC</option>
          <option value="buta_warna">Pemeriksaan Buta Warna</option>
          <option value="bebas_tato">Pemeriksaan Tato</option>
          <option value="tidak_hamil">Pemeriksaan Kehamilan</option>
          <option value="covid">Pemeriksaan COVID-19</option>
          <option value="layak_terbang">Layak Terbang</option>
          <option value="kewaspadaan_kesehatan">Kewaspadaan Kesehatan</option>
          <option value="cuti_hamil">Cuti Hamil</option>
        </select>
      </div>
      <div class="col-12 col-md-6"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
      <div class="col-6 col-md-3">
        <label class="form-label">ID Kunjungan</label>
        <input type="number" name="registration_id" class="form-control">
        <div class="form-hint">Wajib untuk keterangan rawat inap &mdash; periodenya disalin dari admisi.</div>
      </div>
      <div class="col-12"><label class="form-label">Keperluan</label><input type="text" name="purpose" class="form-control" placeholder="mis. untuk keperluan kerja" required></div>
      <div class="col-12"><label class="form-label">Isi Keterangan</label><textarea name="content" class="form-control" rows="2" required></textarea></div>
      <div class="col-12 col-md-6">
        <label class="form-label">Diagnosis</label>
        <input type="text" name="diagnosis" class="form-control">
        <div class="form-hint">Tidak boleh diisi pada surat sakit pihak kedua &mdash; suratnya diserahkan kepada atasan orang lain.</div>
      </div>
      <div class="col-3"><label class="form-label">Berlaku Sejak</label><input type="date" name="valid_from" class="form-control" value="{{ now()->toDateString() }}" required></div>
      <div class="col-3"><label class="form-label">Berlaku Sampai</label><input type="date" name="valid_until" class="form-control"></div>

      <div class="col-12"><hr class="my-1"><div class="form-label mb-0">Hasil Pemeriksaan</div>
        <div class="form-hint">
          Wajib untuk jenis yang menyatakan ketiadaan sesuatu ({{ implode(', ', $jenisBertemuan) }}).
          Surat yang tidak bisa memuat temuan yang tidak diinginkan bukan surat keterangan.
        </div>
      </div>
      <div class="col-12 col-md-8"><label class="form-label">Temuan</label><input type="text" name="examination_result" class="form-control" placeholder="Dengan kata-kata pemeriksa"></div>
      <div class="col-12 col-md-4">
        <label class="form-label">Kesimpulan</label>
        <select name="kesimpulan" class="form-select">
          <option value="">&mdash; belum dipilih &mdash;</option>
          <option value="ya">Sesuai keterangan yang dimintakan</option>
          <option value="tidak">TIDAK sesuai (temuan ditemukan)</option>
        </select>
      </div>

      <div class="col-12"><hr class="my-1"><div class="form-label mb-0">Pihak Kedua <span class="text-secondary fw-normal">(untuk surat sakit pihak kedua)</span></div></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama</label><input type="text" name="third_party_name" class="form-control"></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Hubungan</label>
        <select name="third_party_relationship" class="form-select">
          <option value="">&mdash;</option>
          @foreach (['diri-sendiri','suami','istri','ayah','ibu','anak','saudara-kandung','pengampu','lainnya'] as $h)
            <option value="{{ $h }}">{{ ucwords(str_replace('-', ' ', $h)) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Pekerjaan</label><input type="text" name="third_party_occupation" class="form-control"></div>
      <div class="col-12 col-md-3"><label class="form-label">Instansi</label><input type="text" name="third_party_institution" class="form-control"></div>

      <div class="col-12"><button class="btn btn-primary">Terbitkan</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Riwayat Surat Keterangan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Temuan</th><th>Berlaku</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($surat as $s)
          <tr>
            <td class="font-monospace small">{{ $s->certificate_number }}</td>
            <td>{{ $s->patient_name }}</td>
            <td><span class="badge bg-blue-lt text-uppercase">{{ $s->certificate_type }}</span></td>
            <td class="small">
              @if ($s->memeriksaSesuatu())
                <span class="badge bg-{{ $s->is_clear ? 'green' : 'red' }}-lt">{{ $s->is_clear ? 'sesuai' : 'tidak sesuai' }}</span>
                <div class="text-secondary">{{ $s->examination_result }}</div>
              @else
                <span class="text-secondary">&mdash;</span>
              @endif
            </td>
            <td class="text-secondary small">
              {{ $s->valid_from->format('d-m-Y') }}@if ($s->valid_until) &ndash; {{ $s->valid_until->format('d-m-Y') }}@elseif ($s->certificate_type === 'rawat_inap') &ndash; masih dirawat @endif
            </td>
            <td>
              @if ($s->status === 'diterbitkan')
                <span class="badge bg-green-lt">Diterbitkan</span>
              @else
                <span class="badge bg-secondary-lt">Dibatalkan</span>
              @endif
            </td>
            <td>
              <div class="btn-group">
                <a href="{{ route('correspondence.keterangan.cetak', $s) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Cetak</a>
                @if ($s->status === 'diterbitkan')
                  <form method="POST" action="{{ route('correspondence.keterangan.batal', $s) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada surat diterbitkan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

{{--
  Surat kontrol yang tanggalnya lewat tapi pasiennya tidak datang tampil
  TERPISAH dan di atas riwayat: daftar terbaru justru menyembunyikannya,
  padahal pasien yang tidak kembali kontrol itulah yang perlu dikejar.
--}}
<div class="card mb-3 {{ $kontrolTerlewat->isNotEmpty() ? 'border-warning' : '' }}">
  <div class="card-header">
    <h3 class="card-title">Kontrol Terlewat</h3>
    @if ($kontrolTerlewat->isNotEmpty())
      <span class="badge bg-yellow-lt ms-2">{{ $kontrolTerlewat->count() }}</span>
    @endif
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Dokter</th><th>Tanggal Kontrol</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($kontrolTerlewat as $k)
          <tr>
            <td class="font-monospace small">{{ $k->letter_number }}</td>
            <td>{{ $k->patient_name }}</td>
            <td>{{ $k->practitioner_name }}</td>
            <td class="text-danger">{{ $k->control_date->format('d-m-Y') }}</td>
            <td>
              <form method="POST" action="{{ route('correspondence.keterangan.kontrol.perbarui', $k) }}" class="d-flex gap-1">
                @csrf
                <select name="keputusan" class="form-select form-select-sm" style="width:9rem">
                  <option value="sudah-periksa">Sudah periksa</option>
                  <option value="batal">Batal</option>
                </select>
                <input type="text" name="status_note" class="form-control form-control-sm" placeholder="Alasan (wajib bila batal)">
                <button class="btn btn-sm btn-primary">Simpan</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Tidak ada kontrol yang terlewat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Surat Kontrol (SKDP)</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Dokumen rumah sakit. <b>Tidak dikirim ke Vclaim/BPJS</b> (kredensial bridging ditangguhkan)
      dan <b>tidak membuat booking kunjungan</b> &mdash; pasien tetap didaftarkan seperti biasa saat datang.
    </div>
    <form method="POST" action="{{ route('correspondence.keterangan.kontrol.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-5"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">ID Kunjungan</label><input type="number" name="registration_id" class="form-control"></div>
      <div class="col-6 col-md-4"><label class="form-label">Dokter</label><input type="text" name="practitioner_name" class="form-control" required></div>
      <div class="col-12 col-md-6"><label class="form-label">Diagnosis</label><input type="text" name="diagnosis" class="form-control" required></div>
      <div class="col-12 col-md-6"><label class="form-label">Terapi</label><input type="text" name="therapy" class="form-control" required></div>
      <div class="col-12 col-md-6"><label class="form-label">Alasan Masih Perlu Kontrol</label><input type="text" name="control_reason" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Rencana Tindak Lanjut</label><input type="text" name="follow_up_plan" class="form-control" required></div>
      <div class="col-12 col-md-2"><label class="form-label">Tanggal Kontrol</label><input type="date" name="control_date" class="form-control" required></div>
      <div class="col-12"><button class="btn btn-primary">Terbitkan Surat Kontrol</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Diagnosis</th><th>Kontrol</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($kontrol as $k)
          <tr>
            <td class="font-monospace small">{{ $k->letter_number }}</td>
            <td>{{ $k->patient_name }}</td>
            <td class="small">{{ $k->diagnosis }}</td>
            <td class="small">{{ $k->control_date->format('d-m-Y') }}</td>
            <td>
              @php $warna = ['menunggu' => 'yellow', 'sudah-periksa' => 'green', 'batal' => 'secondary'][$k->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $k->status }}</span>
              @if ($k->status_note)
                <div class="small text-secondary">{{ $k->status_note }}</div>
              @endif
            </td>
            <td>
              @if ($k->status === 'menunggu')
                <form method="POST" action="{{ route('correspondence.keterangan.kontrol.perbarui', $k) }}" class="d-flex gap-1">
                  @csrf
                  <select name="keputusan" class="form-select form-select-sm" style="width:9rem">
                    <option value="sudah-periksa">Sudah periksa</option>
                    <option value="batal">Batal</option>
                  </select>
                  <input type="text" name="status_note" class="form-control form-control-sm" placeholder="Alasan bila batal">
                  <button class="btn btn-sm btn-outline-primary">Simpan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada surat kontrol.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
