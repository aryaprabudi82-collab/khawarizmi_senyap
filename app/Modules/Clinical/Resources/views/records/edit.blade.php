@extends('layouts.app')

@section('title', 'Pemeriksaan Pasien')
@section('breadcrumb', 'Konteks clinical · ' . $assessment->registration_number)
@section('heading', $assessment->patient_name)

@push('styles')
<style>
  /*
    Banner identitas pasien. Gradient BERBEDA dari navbar dengan sengaja:
    navbar adalah kerangka aplikasi, banner ini adalah konteks pasien yang
    sedang dibuka. Warna yang sama persis membuat keduanya membaur, dan
    pemeriksa kehilangan penanda "saya sedang di rekam medis siapa".
  */
  .rme-banner { background: linear-gradient(135deg,#1a5ba8,#2d7dd2); color:#fff; border-radius:.5rem; }
  .rme-banner .datagrid-title { color: rgba(255,255,255,.72); }
  .rme-banner a, .rme-banner .btn-ghost-light { color:#fff; }

  /* Panel kanan: garis tepi berwarna membedakan jenis data sekali lihat. */
  .rme-panel-lab   { border:1px solid #fca5a5; }
  .rme-panel-rad   { border:1px solid #86efac; }
  .rme-panel-resep { border:1px solid #a7f3d0; }
  .rme-panel-lab   .card-header { background:#fef2f2; color:#dc2626; }
  .rme-panel-rad   .card-header { background:#f0fdf4; color:#15803d; }
  .rme-panel-resep .card-header { background:#ecfdf5; color:#047857; }
  .rme-panel-pa    { border:1px solid #c7d2fe; }
  .rme-panel-pa    .card-header { background:#eef2ff; color:#4338ca; }

  .rme-invoice { background:#1e293b; color:#fff; }
  .rme-scroll  { max-height: 420px; overflow-y: auto; }
</style>
@endpush

@section('content')

@php
  use App\Modules\Clinical\Models\Assessment;

  $rp = fn ($n) => $n === null ? null : 'Rp ' . number_format((float) $n, 0, ',', '.');

  /*
    Tiga sub-tab, dan pemetaannya ke `kind` yang SUDAH ada di CHECK
    constraint basis data. Tidak ada kind baru yang dikarang di sini:
    menambah nilai berarti migrasi pada tabel rekam medis, dan itu
    keputusan RSP UI (tercatat sebagai Q15).
  */
  $subTab = [
    Assessment::KIND_SOAP => ['label' => 'DPJP', 'hak' => 'penilaian_awal_medis_ralan'],
    Assessment::KIND_KEPERAWATAN => ['label' => 'PPA', 'hak' => 'soap_perawatan'],
    Assessment::KIND_LANJUTAN => ['label' => 'Student', 'hak' => 'penilaian_awal_medis_ralan'],
  ];
@endphp

{{-- ============================ BANNER PASIEN ============================ --}}
<div class="card rme-banner mb-3">
  <div class="card-body py-3">
    <div class="d-flex flex-wrap align-items-center gap-3">

      <span class="avatar avatar-md bg-white text-primary fw-bold">
        {{ mb_strtoupper(mb_substr($assessment->patient_name, 0, 1)) }}
      </span>

      <div>
        <div class="h3 mb-0 text-white">{{ $assessment->patient_name }}</div>
        <div class="small" style="color:rgba(255,255,255,.8)">
          <span class="font-monospace">{{ $assessment->patient_mrn }}</span>
          @if ($pasien)
            {{-- Tiga keadaan: L, P, dan BELUM DIKETAHUI. Menampilkan "P" untuk
                 pasien yang jenis kelaminnya kosong (data warisan HSN) membuat
                 pemeriksa membaca fakta klinis yang tidak pernah tercatat. --}}
            &middot; {{ $pasien->sex ?? '—' }}
            &middot; {{ $umurPasien }}
          @endif
        </div>
      </div>

      <div class="vr d-none d-lg-block" style="opacity:.35"></div>

      <div class="small" style="color:rgba(255,255,255,.88)">
        <div>
          🗓 {{ \Carbon\Carbon::parse($kunjungan->service_date ?? now())->format('Y-m-d') }}
          &nbsp; 📍 {{ $assessment->unit_name ?? '—' }}
        </div>
        <div>
          🩺 {{ $assessment->practitioner_name ?? 'DPJP belum ditetapkan' }}
          @if ($pasien?->phone) &nbsp; 📞 {{ $pasien->phone }} @endif
        </div>
        <div>🏠 {{ $alamatPasien }}</div>
      </div>

      <div class="ms-auto d-flex flex-wrap align-items-center gap-2">

        {{--
          ALERGI DITARUH PALING KIRI dari kelompok tombol, dan warnanya
          merah saat ada isinya. Ia harus terbaca SEBELUM pemeriksa
          menuliskan resep — bukan ditemukan setelahnya.
        --}}
        <button class="btn btn-sm {{ $alergi->isNotEmpty() ? 'btn-danger' : 'btn-outline-light' }}"
                data-bs-toggle="modal" data-bs-target="#modal-alergi">
          ⚠ Alergi ({{ $alergi->count() }})
        </button>

        @if ($pasien?->special_precautions)
          <span class="badge bg-yellow text-dark">{{ $pasien->special_precautions }}</span>
        @endif

        <span class="badge bg-white text-primary">{{ $kunjungan->payer_name ?? 'Umum' }}</span>

        <a href="{{ route('rme.index') }}" class="btn btn-sm btn-outline-light">← Kembali</a>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">

  {{-- ======================== KOLOM KIRI — PENCATATAN ======================== --}}
  <div class="col-12 col-xl-5">

    <div class="card mb-3">
      <div class="card-header p-0">
        <ul class="nav nav-pills p-2 gap-1">
          @foreach ($subTab as $kind => $tab)
            @can($tab['hak'])
              @php $terisi = $ringkasanJenis[$kind] ?? null; @endphp
              <li class="nav-item">
                <a class="nav-link py-1 px-3 {{ $jenisAktif === $kind ? 'active' : '' }}"
                   href="{{ route('rme.edit', ['registrasi' => $kunjungan->id, 'jenis' => $kind]) }}">
                  {{ $tab['label'] }}
                  @if ($terisi && $terisi->status !== 'draft')
                    <span class="badge bg-green ms-1">✓</span>
                  @endif
                </a>
              </li>
            @endcan
          @endforeach
        </ul>
      </div>

      <form method="POST" action="{{ route('rme.update', $assessment) }}">
        @csrf
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label text-uppercase small text-secondary" for="chief_complaint">
              Subjective (Keluhan)
            </label>
            <input type="text" id="chief_complaint" name="chief_complaint" class="form-control mb-2"
                   placeholder="Keluhan utama..." value="{{ old('chief_complaint', $assessment->chief_complaint) }}">
            <textarea name="subjective" class="form-control" rows="2"
                      placeholder="Riwayat penyakit, riwayat pengobatan">{{ old('subjective', $assessment->subjective) }}</textarea>
          </div>

          {{-- Tanda vital dijadikan satu baris seperti acuan: tensi, nadi, suhu --}}
          <div class="row g-2 mb-3">
            @foreach ($katalogObservasi as $kode => $ukuran)
              <div class="col-6 col-md-4">
                <label class="form-label small text-uppercase text-secondary" for="vital-{{ $kode }}">
                  {{ $ukuran->display }}
                </label>
                <div class="input-group input-group-flat input-group-sm">
                  <input type="number" step="0.01" id="vital-{{ $kode }}" name="vital[{{ $kode }}]"
                         class="form-control" placeholder="{{ $ukuran->unit }}">
                  <span class="input-group-text">{{ $ukuran->unit }}</span>
                </div>
                @if (isset($observasi[$kode]))
                  <div class="form-hint {{ $observasi[$kode]->is_abnormal ? 'text-danger' : '' }}">
                    Terakhir {{ rtrim(rtrim($observasi[$kode]->value_numeric, '0'), '.') }}
                    @if ($observasi[$kode]->is_abnormal) · di luar rentang @endif
                  </div>
                @endif
              </div>
            @endforeach
          </div>

          <div class="mb-3">
            <label class="form-label text-uppercase small text-secondary" for="objective">Objective</label>
            <textarea id="objective" name="objective" class="form-control" rows="2"
                      placeholder="Pemeriksaan fisik...">{{ old('objective', $assessment->objective) }}</textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-12 col-md-6">
              <label class="form-label text-uppercase small text-secondary" for="assessment">Assessment</label>
              <textarea id="assessment" name="assessment" class="form-control" rows="3">{{ old('assessment', $assessment->assessment) }}</textarea>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label text-uppercase small text-secondary" for="plan">Plan / RTL</label>
              <textarea id="plan" name="plan" class="form-control" rows="3">{{ old('plan', $assessment->plan) }}</textarea>
            </div>
          </div>

          @if ($assessment->isLocked())
            <div class="mb-3">
              <label class="form-label required" for="alasan_ralat">Alasan ralat</label>
              <input type="text" id="alasan_ralat" name="alasan_ralat" class="form-control"
                     placeholder="Wajib diisi untuk mengubah catatan yang sudah difinalkan">
              <div class="form-hint">
                Versi sebelumnya disimpan utuh sebagai riwayat, sesuai syarat jejak audit Permenkes 24/2022.
              </div>
            </div>
          @endif
        </div>

        <div class="card-footer d-flex justify-content-end">
          <button type="submit" class="btn btn-primary">
            💾 Simpan SOAP {{ $subTab[$jenisAktif]['label'] ?? '' }}
          </button>
        </div>
      </form>

      {{-- ICD-10 + Order penunjang: form TERPISAH dari SOAP di atas, karena
           form bersarang tidak sah di HTML dan tombolnya akan diam saja. --}}
      <div class="card-body border-top">
        <form method="POST" action="{{ route('rme.diagnosis.simpan', $assessment) }}" class="row g-2 align-items-end mb-2">
          @csrf
          <div class="col-12 col-md-6">
            <label class="form-label small text-uppercase text-secondary" for="kode-diagnosis">ICD-10</label>
            <input type="text" id="kode-diagnosis" name="code" class="form-control form-control-sm" list="daftar-icd"
                   placeholder="Cari kode diagnosa..." autocomplete="off" required>
            <datalist id="daftar-icd"></datalist>
          </div>
          <div class="col-6 col-md-3">
            <select name="rank" class="form-select form-select-sm" aria-label="Peringkat diagnosis">
              <option value="utama">Utama</option>
              <option value="sekunder" selected>Sekunder</option>
              <option value="komplikasi">Komplikasi</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <button class="btn btn-sm btn-outline-primary w-100">+ Diagnosa</button>
          </div>
        </form>

        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="small text-uppercase text-secondary">Order penunjang:</span>

          @include('clinical::records._tombol-order', [
            'hak' => 'resep_obat',
            'label' => '💊 Resep',
            'warna' => 'danger',
            'aksi' => route('resep.buat', $assessment->registration_id),
            'alasanTidakBisa' => null,
          ])

          @include('clinical::records._tombol-order', [
            'hak' => 'periksa_lab',
            'label' => '🔬 Lab',
            'warna' => 'primary',
            'aksi' => route('order.buat', ['lab', $assessment->registration_id]),
            'alasanTidakBisa' => null,
          ])

          @include('clinical::records._tombol-order', [
            'hak' => 'periksa_radiologi',
            'label' => '📡 Radiologi',
            'warna' => 'warning',
            'aksi' => route('order.buat', ['radiologi', $assessment->registration_id]),
            'alasanTidakBisa' => null,
          ])

          @include('clinical::records._tombol-order', [
            'hak' => 'pemeriksaan_lab_pa',
            'label' => '🧫 PA',
            'warna' => 'indigo',
            'aksi' => route('order.buat', ['pa', $assessment->registration_id]),
            'alasanTidakBisa' => null,
          ])
        </div>
        <div class="form-hint mt-2">
          Membuka order kosong untuk kunjungan ini lalu membawa Anda ke layar order,
          tempat pemeriksaan dipilih. Order yang sudah terbuka akan dilanjutkan, bukan digandakan.
        </div>
      </div>
    </div>

    {{-- ===================== TAB RIWAYAT ===================== --}}
    <div class="card">
      <div class="card-header p-0">
        <ul class="nav nav-tabs" data-bs-toggle="tabs">
          <li class="nav-item"><a href="#tab-soap" class="nav-link active" data-bs-toggle="tab">📋 Riwayat SOAP</a></li>
          <li class="nav-item"><a href="#tab-lab" class="nav-link" data-bs-toggle="tab">🔬 Laboratorium</a></li>
          <li class="nav-item"><a href="#tab-rad" class="nav-link" data-bs-toggle="tab">📡 Radiologi</a></li>
          <li class="nav-item"><a href="#tab-resep" class="nav-link" data-bs-toggle="tab">💊 History Resep</a></li>
          <li class="nav-item"><a href="#tab-tindakan" class="nav-link" data-bs-toggle="tab">🩹 Tindakan</a></li>
        </ul>
      </div>

      <div class="card-body rme-scroll">
        <div class="tab-content">

          {{-- Riwayat SOAP --}}
          <div class="tab-pane active show" id="tab-soap">
            @forelse ($ringkasanJenis as $kind => $r)
              <div class="border-bottom pb-2 mb-2">
                <div class="d-flex justify-content-between">
                  <strong class="text-primary">{{ Assessment::kindLabel($kind) }}</strong>
                  <span class="badge bg-{{ $r->status === 'draft' ? 'yellow' : 'green' }}-lt">{{ $r->status }}</span>
                </div>
                <div class="text-secondary small">
                  {{ $r->practitioner_name ?? '—' }} ·
                  {{ $r->recorded_at ? \Carbon\Carbon::parse($r->recorded_at)->format('d-m-Y H:i') : '—' }}
                </div>
              </div>
            @empty
              <div class="text-secondary small">Belum ada catatan pada kunjungan ini.</div>
            @endforelse

            @if ($revisi->isNotEmpty())
              <div class="mt-3">
                <div class="small text-uppercase text-secondary mb-1">Riwayat ralat</div>
                @foreach ($revisi as $r)
                  <div class="small border-start border-2 ps-2 mb-1">
                    Versi {{ $r->version }} · {{ $r->revised_at->format('d-m-Y H:i') }} ·
                    {{ $r->revised_by_name ?? 'sistem' }}
                    <div class="text-secondary">Alasan: {{ $r->reason }}</div>
                  </div>
                @endforeach
              </div>
            @endif

            <div class="mt-3">
              <div class="small text-uppercase text-secondary mb-1">Kunjungan sebelumnya</div>
              @forelse ($riwayat as $r)
                <div class="d-flex justify-content-between small border-bottom py-1">
                  <a href="{{ route('rme.edit', $r->id) }}">{{ \Carbon\Carbon::parse($r->service_date)->format('d-m-Y') }}</a>
                  <span class="text-secondary">{{ $r->unit_name }}</span>
                </div>
              @empty
                <div class="text-secondary small">Belum ada riwayat kunjungan lain.</div>
              @endforelse
            </div>
          </div>

          {{-- Laboratorium --}}
          <div class="tab-pane" id="tab-lab">
            @include('clinical::records._daftar-hasil', ['pesanan' => $pesananLab, 'hasil' => $hasilLab, 'kosong' => 'Belum ada permintaan atau hasil lab.'])
          </div>

          {{-- Radiologi --}}
          <div class="tab-pane" id="tab-rad">
            @include('clinical::records._daftar-hasil', ['pesanan' => $pesananRadiologi, 'hasil' => $hasilRadiologi, 'kosong' => 'Belum ada data radiologi.'])
          </div>

          {{-- History Resep --}}
          <div class="tab-pane" id="tab-resep">
            @forelse ($resepBaris as $b)
              <div class="border-bottom py-2">
                <div class="d-flex justify-content-between">
                  <strong>{{ $b->drug_name }}</strong>
                  <span class="text-secondary small">{{ $b->prescribed_quantity }} {{ $b->drug_unit }}</span>
                </div>
                <div class="small text-secondary">
                  {{ $b->dosage_instruction ?? '—' }} ·
                  <a href="{{ route('resep.show', $b->prescription_id) }}">{{ $b->prescription_number }}</a>
                  · {{ $b->status }}
                </div>
                @if ($b->is_narcotic || $b->is_psychotropic || $b->is_high_alert)
                  <div class="mt-1">
                    @if ($b->is_narcotic)<span class="badge bg-red-lt">Narkotika</span>@endif
                    @if ($b->is_psychotropic)<span class="badge bg-orange-lt">Psikotropika</span>@endif
                    @if ($b->is_high_alert)<span class="badge bg-yellow-lt">High alert</span>@endif
                  </div>
                @endif
              </div>
            @empty
              <div class="text-secondary small">Belum ada resep obat.</div>
            @endforelse
          </div>

          {{-- Tindakan & operasi --}}
          <div class="tab-pane" id="tab-tindakan">
            @include('clinical::records._tindakan')
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- ======================== KOLOM KANAN — PANEL BACA ======================== --}}
  <div class="col-12 col-xl-7">

    {{-- Skrining awal: ditaruh paling atas kolom kanan karena ia PRASYARAT
         pemeriksaan, bukan hasilnya. --}}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Skrining Awal</h3></div>
      @if ($skrining)
        <div class="card-body py-2">
          <div class="row g-2">
            @php $warnaJatuh = ['rendah' => 'green', 'sedang' => 'yellow', 'tinggi' => 'red'][$skrining->fall_risk_level]; @endphp
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Risiko Jatuh</div>
              <span class="badge bg-{{ $warnaJatuh }}-lt text-uppercase">{{ $skrining->fall_risk_level }}</span>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Skala Nyeri</div>
              <span class="badge {{ $skrining->pain_score >= 4 ? 'bg-red-lt' : 'bg-secondary-lt' }}">{{ $skrining->pain_score }}/10</span>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Risiko Gizi</div>
              <span class="badge {{ $skrining->nutrition_at_risk ? 'bg-red-lt' : 'bg-green-lt' }}">{{ $skrining->nutrition_at_risk ? 'Berisiko' : 'Tidak' }}</span>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Gejala Menular</div>
              <span class="badge {{ $skrining->infectious_symptom ? 'bg-red-lt' : 'bg-green-lt' }}">{{ $skrining->infectious_symptom ? 'Ada' : 'Tidak' }}</span>
            </div>

            {{--
              KEBUTUHAN KHUSUS HARUS IKUT TERBACA, bukan hanya keempat
              skor di atas. "Membutuhkan penerjemah bahasa isyarat" atau
              "pendamping disabilitas" menentukan cara pemeriksaan
              dijalankan — dan skrining yang mencatatnya lalu tidak
              menampilkannya sama saja dengan tidak mencatatnya.
            --}}
            @if ($skrining->special_needs)
              <div class="col-12">
                <div class="text-secondary small">Kebutuhan Khusus</div>
                <div class="fw-semibold">{{ $skrining->special_needs }}</div>
              </div>
            @endif
          </div>
          <div class="form-hint mt-2">
            Dicatat {{ $skrining->screened_by_name ?? 'petugas' }}, {{ $skrining->screened_at->format('d-m-Y H:i') }}.
          </div>
        </div>
      @else
        <div class="card-body py-2">
          <form method="POST" action="{{ route('rme.skrining.simpan', $kunjungan->id) }}" class="row g-2">
            @csrf
            <div class="col-6 col-md-3">
              <label class="form-label small">Risiko Jatuh</label>
              <select name="fall_risk_level" class="form-select form-select-sm" required>
                <option value="rendah">Rendah</option>
                <option value="sedang">Sedang</option>
                <option value="tinggi">Tinggi</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small">Nyeri (0-10)</label>
              <input type="number" name="pain_score" class="form-control form-control-sm" min="0" max="10" value="0" required>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end">
              <label class="form-check">
                <input type="checkbox" name="nutrition_at_risk" value="1" class="form-check-input">
                <span class="form-check-label small">Risiko gizi</span>
              </label>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end">
              <label class="form-check">
                <input type="checkbox" name="infectious_symptom" value="1" class="form-check-input">
                <span class="form-check-label small">Gejala menular</span>
              </label>
            </div>
            <div class="col-12">
              <input type="text" name="special_needs" class="form-control form-control-sm"
                     placeholder="Kebutuhan khusus (opsional): penerjemah, disabilitas, dsb.">
            </div>
            <div class="col-12">
              <button class="btn btn-sm btn-outline-primary w-100">Catat Skrining</button>
            </div>
          </form>
        </div>
      @endif
    </div>

    {{-- TOTAL INVOICE --}}
    <div class="card rme-invoice mb-3">
      <div class="card-body py-3">
        <div class="small text-uppercase" style="color:rgba(255,255,255,.6)">Total Invoice</div>
        @if ($totalTagihan === null)
          <div class="h2 mb-0">Belum ada tagihan</div>
          <div class="small" style="color:rgba(255,255,255,.6)">
            Belum ada satu pun biaya tercatat pada kunjungan ini — berbeda dari Rp 0,
            yang berarti pelayanannya memang gratis.
          </div>
        @else
          <div class="h1 mb-0 text-warning">{{ $rp($totalTagihan) }}</div>
          <div class="small" style="color:rgba(255,255,255,.6)">
            {{ $rincianTagihan->count() }} baris biaya · tersinkron dari tindakan, obat, dan penunjang
          </div>
        @endif
      </div>
    </div>

    {{-- LABORATORY --}}
    <div class="card rme-panel-lab mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">🔬 LABORATORY</h3>
        <span class="badge bg-secondary">{{ $jumlahPesanan['lab'] }}</span>
      </div>
      <div class="card-body">
        @include('clinical::records._daftar-hasil', ['pesanan' => $pesananLab, 'hasil' => $hasilLab, 'kosong' => 'Belum ada permintaan atau hasil lab.'])
      </div>
    </div>

    {{-- RADIOLOGY --}}
    <div class="card rme-panel-rad mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">📡 RADIOLOGY</h3>
        <span class="badge bg-secondary">{{ $jumlahPesanan['radiologi'] }}</span>
      </div>
      <div class="card-body">
        @include('clinical::records._daftar-hasil', ['pesanan' => $pesananRadiologi, 'hasil' => $hasilRadiologi, 'kosong' => 'Belum ada data radiologi.'])
      </div>
    </div>

    {{-- PATOLOGI ANATOMI --}}
    @if ($jumlahPesanan['pa'] > 0)
      <div class="card rme-panel-pa mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">🧫 PATOLOGI ANATOMI</h3>
          <span class="badge bg-secondary">{{ $jumlahPesanan['pa'] }}</span>
        </div>
        <div class="card-body">
          @include('clinical::records._daftar-hasil', ['pesanan' => $pesananPa, 'hasil' => $hasilPa, 'kosong' => 'Belum ada data PA.'])
        </div>
      </div>
    @endif

    {{-- PRESCRIPTION --}}
    <div class="card rme-panel-resep mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">💊 PRESCRIPTION (E-RESEP)</h3>
        <span class="badge bg-secondary">{{ $resepBaris->count() }}</span>
      </div>
      <div class="card-body">
        @forelse ($resepBaris as $b)
          <div class="d-flex justify-content-between border-bottom py-2">
            <div>
              <strong>{{ $b->drug_name }}</strong>
              <div class="text-secondary small">{{ $b->dosage_instruction ?? '—' }}</div>
            </div>
            <div class="text-end">
              <div>{{ rtrim(rtrim((string) $b->prescribed_quantity, '0'), '.') }} {{ $b->drug_unit }}</div>
              <a class="small" href="{{ route('resep.show', $b->prescription_id) }}">{{ $b->prescription_number }}</a>
            </div>
          </div>
        @empty
          <div class="text-secondary text-center py-3"><em>Belum ada resep obat.</em></div>
        @endforelse
      </div>
    </div>

    {{-- DIAGNOSIS --}}
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Diagnosis</h3>
        <span class="badge bg-secondary">{{ $diagnosis->count() }}</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-vcenter card-table mb-0">
          <thead><tr><th>Kode</th><th>Diagnosis</th><th>Peringkat</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($diagnosis as $d)
              <tr>
                <td class="font-monospace">{{ $d->code }}</td>
                <td>{{ $d->display }}</td>
                <td><span class="badge bg-{{ $d->rank === 'utama' ? 'blue' : 'secondary' }}-lt">{{ $d->rank }}</span></td>
                <td>
                  <form method="POST" action="{{ route('rme.diagnosis.hapus', $d) }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-ghost-danger">×</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-secondary text-center py-3">Belum ada diagnosis dicatat.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- FINALKAN --}}
    @unless ($assessment->isLocked())
      <form method="POST" action="{{ route('rme.finalkan', $assessment) }}">
        @csrf
        <div class="card">
          <div class="card-body d-flex justify-content-between align-items-center">
            <div>
              <strong>Finalkan asesmen</strong>
              <div class="text-secondary small">
                Setelah difinalkan, catatan terkunci. Perubahan berikutnya wajib menyertakan
                alasan dan tersimpan sebagai versi baru.
              </div>
            </div>
            <button class="btn btn-success">Finalkan</button>
          </div>
        </div>
      </form>
    @endunless
  </div>
</div>

{{-- ============================== MODAL ALERGI ============================== --}}
<div class="modal fade" id="modal-alergi" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Alergi pasien</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        @forelse ($alergi as $a)
          <div class="border-bottom py-2">
            <span class="badge bg-red">{{ $a->substance }}</span>
            <span class="text-secondary small">
              {{ $a->severity }}@if ($a->reaction) · {{ $a->reaction }}@endif
            </span>
          </div>
        @empty
          <div class="text-secondary">Belum ada alergi tercatat untuk pasien ini.</div>
        @endforelse

        <form method="POST" action="{{ route('rme.alergi.simpan', $assessment) }}" class="row g-2 mt-3">
          @csrf
          <div class="col-12">
            <input type="text" name="substance" class="form-control form-control-sm"
                   placeholder="Zat penyebab, mis. Amoksisilin" required>
          </div>
          <div class="col-6">
            <select name="category" class="form-select form-select-sm" aria-label="Kategori alergi">
              <option value="obat">Obat</option>
              <option value="makanan">Makanan</option>
              <option value="lingkungan">Lingkungan</option>
              <option value="lainnya">Lainnya</option>
            </select>
          </div>
          <div class="col-6">
            <select name="severity" class="form-select form-select-sm" aria-label="Derajat alergi">
              <option value="ringan">Ringan</option>
              <option value="sedang" selected>Sedang</option>
              <option value="berat">Berat</option>
            </select>
          </div>
          <div class="col-12">
            <input type="text" name="reaction" class="form-control form-control-sm" placeholder="Reaksi, mis. ruam">
          </div>
          <div class="col-12">
            <button class="btn btn-sm btn-danger w-100">Catat Alergi</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
  // Melengkapi kode ICD-10 sambil mengetik.
  document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('kode-diagnosis');
    var daftar = document.getElementById('daftar-icd');
    if (!input || !daftar) return;

    var tunda;

    input.addEventListener('input', function () {
      clearTimeout(tunda);
      var q = input.value.trim();
      if (q.length < 2) return;

      tunda = setTimeout(function () {
        fetch('{{ route('rme.kode-diagnosis') }}?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (hasil) {
            daftar.innerHTML = '';
            hasil.forEach(function (k) {
              var opt = document.createElement('option');
              opt.value = k.code;
              opt.label = k.label;
              opt.textContent = k.label;
              daftar.appendChild(opt);
            });
          })
          .catch(function () { /* pencarian gagal: input tetap bisa diisi manual */ });
      }, 250);
    });
  });
</script>
@endpush

@endsection
