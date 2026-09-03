@extends('layouts.app')

@section('title', 'Daftarkan Pasien')
@section('breadcrumb', 'Modul A &middot; Registrasi dan Pelayanan')
@section('heading', 'Daftarkan Pasien')

@section('actions')
  <a href="{{ route('registrasi.index', ['tanggal' => $tanggal->toDateString()]) }}" class="btn btn-link">
    Kembali ke papan antrean
  </a>
@endsection

@section('content')

<div class="row g-3">

  {{-- Langkah 1: temukan pasien --}}
  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">1. Temukan pasien</h3>
      </div>
      <div class="card-body">
        <form method="GET" action="{{ route('registrasi.create') }}">
          <input type="hidden" name="tanggal" value="{{ $tanggal->toDateString() }}">
          <label class="form-label" for="cari">Nomor RM, NIK, nomor telepon, atau nama</label>
          <div class="input-group">
            <input type="search" id="cari" name="cari" class="form-control"
                   value="{{ $cari }}" placeholder="mis. 000123 atau Budi" autofocus>
            <button class="btn btn-primary" type="submit">Cari</button>
          </div>
          <div class="form-hint">
            Pencarian nama memakai index trigram, jadi tetap cepat walau data sudah jutaan baris.
          </div>
        </form>

        @if ($cari !== '')
          <hr>
          @if ($hasilCari->isEmpty())
            <div class="text-center py-3">
              <p class="text-secondary mb-3">Tidak ada pasien yang cocok dengan &ldquo;{{ $cari }}&rdquo;.</p>
              <a href="{{ route('pasien.create', ['nama' => $cari]) }}" class="btn btn-outline-primary">
                Daftarkan sebagai pasien baru
              </a>
            </div>
          @else
            <div class="list-group list-group-flush">
              @foreach ($hasilCari as $kandidat)
                <a class="list-group-item list-group-item-action {{ $pasien?->id === $kandidat->id ? 'active' : '' }}"
                   href="{{ route('registrasi.create', [
                       'tanggal' => $tanggal->toDateString(),
                       'cari' => $cari,
                       'pasien_id' => $kandidat->id,
                   ]) }}">
                  <div class="fw-semibold">{{ $kandidat->name }}</div>
                  <div class="small">
                    <span class="font-monospace">{{ $kandidat->medical_record_number }}</span>
                    &middot; {{ $kandidat->sex === 'L' ? 'Laki-laki' : 'Perempuan' }}
                    @if ($kandidat->birth_date)
                      &middot; {{ $kandidat->birth_date->format('d-m-Y') }}
                    @endif
                  </div>
                </a>
              @endforeach
            </div>
            <div class="mt-3 text-center">
              <a href="{{ route('pasien.create', ['nama' => $cari]) }}" class="btn btn-link btn-sm">
                Tidak ada yang cocok? Daftarkan pasien baru
              </a>
            </div>
          @endif
        @endif
      </div>
    </div>
  </div>

  {{-- Langkah 2: rincian kunjungan --}}
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">2. Rincian kunjungan</h3>
      </div>

      @if ($pasien === null)
        <div class="card-body">
          <div class="empty">
            <p class="empty-title">Belum ada pasien dipilih</p>
            <p class="empty-subtitle text-secondary">
              Cari pasien di panel kiri, lalu pilih salah satu hasilnya untuk melanjutkan.
            </p>
          </div>
        </div>
      @else
        <div class="card-body border-bottom">
          <div class="row">
            <div class="col-md-7">
              <div class="h3 mb-1">{{ $pasien->name }}</div>
              <div class="text-secondary">
                <span class="font-monospace">{{ $pasien->medical_record_number }}</span>
                @if ($pasien->nik) &middot; NIK {{ $pasien->nik }} @endif
              </div>
            </div>
            <div class="col-md-5 text-md-end text-secondary">
              {{ $pasien->sex === 'L' ? 'Laki-laki' : 'Perempuan' }}
              @if ($pasien->birth_date)
                <br>{{ $pasien->birth_date->format('d-m-Y') }}
                ({{ $pasien->ageOn($tanggal)['years'] }} tahun)
              @endif
            </div>
          </div>

          @if ($pasien->special_precautions)
            <div class="alert alert-warning mt-3 mb-0 py-2">
              <strong>Perhatian khusus:</strong> {{ $pasien->special_precautions }}
            </div>
          @endif
        </div>

        <form method="POST" action="{{ route('registrasi.store') }}">
          @csrf
          <input type="hidden" name="pasien_id" value="{{ $pasien->id }}">

          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label required" for="tanggal_layanan">Tanggal pelayanan</label>
                <input type="date" id="tanggal_layanan" name="tanggal" class="form-control"
                       value="{{ old('tanggal', $tanggal->toDateString()) }}" required>
              </div>

              @can('permintaan_ranap')
                <div class="col-md-6">
                  <label class="form-label" for="jenis_rawat">Jenis rawat</label>
                  <select id="jenis_rawat" name="jenis_rawat" class="form-select">
                    <option value="ralan" @selected(old('jenis_rawat', 'ralan') === 'ralan')>Rawat Jalan</option>
                    <option value="ranap" @selected(old('jenis_rawat') === 'ranap')>Rawat Inap</option>
                  </select>
                  <div class="form-hint">Rawat inap: kamar/bed dialokasikan berikutnya di layar Rawat Inap.</div>
                </div>
              @endcan

              <div class="col-md-6">
                <label class="form-label required" for="unit_id">Unit layanan</label>
                <select id="unit_id" name="unit_id" class="form-select" required>
                  <option value="">— pilih unit —</option>
                  @foreach ($units as $unit)
                    <option value="{{ $unit->id }}" @selected(old('unit_id') == $unit->id)>
                      {{ $unit->name }}@if ($unit->daily_quota) (kuota {{ $unit->daily_quota }})@endif
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="praktisi_id">Dokter penanggung jawab</label>
                <select id="praktisi_id" name="praktisi_id" class="form-select">
                  <option value="">— belum ditentukan —</option>
                  @foreach ($praktisi as $dokter)
                    <option value="{{ $dokter->id }}"
                            data-unit="{{ $dokter->units->pluck('id')->join(',') }}"
                            @selected(old('praktisi_id') == $dokter->id)>
                      {{ $dokter->displayName() }} — {{ $dokter->specialty }}
                    </option>
                  @endforeach
                </select>
                <div class="form-hint">Hanya dokter yang aktif melayani pada tanggal tersebut.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label required" for="penjamin_id">Penjamin</label>
                <select id="penjamin_id" name="penjamin_id" class="form-select" required>
                  <option value="">— pilih penjamin —</option>
                  @foreach ($penjamin as $p)
                    <option value="{{ $p->id }}" data-kind="{{ $p->kind }}"
                            @selected(old('penjamin_id') == $p->id)>{{ $p->name }}</option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-6" id="grup-kartu" hidden>
                <label class="form-label" for="nomor_kartu">Nomor kartu peserta</label>
                <input type="text" id="nomor_kartu" name="nomor_kartu" class="form-control"
                       value="{{ old('nomor_kartu') }}">
              </div>

              <div class="col-md-6" id="grup-rujukan" hidden>
                <label class="form-label" for="nomor_rujukan">Nomor rujukan</label>
                <input type="text" id="nomor_rujukan" name="nomor_rujukan" class="form-control"
                       value="{{ old('nomor_rujukan') }}">
                <div class="form-hint">Dari FKTP atau faskes perujuk.</div>
              </div>
            </div>
          </div>

          <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="text-secondary small">
              Nomor antrean dan nomor registrasi dialokasikan otomatis saat disimpan.
            </span>
            <button type="submit" class="btn btn-primary">Simpan Registrasi</button>
          </div>
        </form>
      @endif
    </div>
  </div>
</div>

@push('scripts')
<script>
  // Menyaring pilihan dokter mengikuti unit, dan memunculkan isian penjamin
  // hanya bila memang relevan.
  document.addEventListener('DOMContentLoaded', function () {
    var unit = document.getElementById('unit_id');
    var dokter = document.getElementById('praktisi_id');
    var penjamin = document.getElementById('penjamin_id');
    var grupKartu = document.getElementById('grup-kartu');
    var grupRujukan = document.getElementById('grup-rujukan');

    function saringDokter() {
      if (!unit || !dokter) return;
      var terpilih = unit.value;

      Array.prototype.forEach.call(dokter.options, function (opsi) {
        if (!opsi.value) return;
        var unitDokter = (opsi.dataset.unit || '').split(',');
        var cocok = !terpilih || unitDokter.indexOf(terpilih) !== -1;
        opsi.hidden = !cocok;
        if (!cocok && opsi.selected) dokter.value = '';
      });
    }

    function aturPenjamin() {
      if (!penjamin) return;
      var opsi = penjamin.options[penjamin.selectedIndex];
      var jenis = opsi ? opsi.dataset.kind : '';
      var perluKartu = jenis && jenis !== 'umum';

      if (grupKartu) grupKartu.hidden = !perluKartu;
      if (grupRujukan) grupRujukan.hidden = jenis !== 'bpjs';
    }

    if (unit) unit.addEventListener('change', saringDokter);
    if (penjamin) penjamin.addEventListener('change', aturPenjamin);

    saringDokter();
    aturPenjamin();
  });
</script>
@endpush

@endsection
