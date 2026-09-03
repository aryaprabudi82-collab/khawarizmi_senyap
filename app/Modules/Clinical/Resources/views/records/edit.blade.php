@extends('layouts.app')

@section('title', 'Pemeriksaan Pasien')
@section('breadcrumb', 'Konteks clinical &middot; ' . $assessment->registration_number)
@section('heading', $assessment->patient_name)

@section('actions')
  <a href="{{ route('rme.index') }}" class="btn btn-link">Kembali ke daftar</a>
@endsection

@section('content')

{{-- Penanda yang harus terlihat sebelum apa pun dicatat --}}
@if ($alergi->isNotEmpty())
  <div class="alert alert-danger d-flex align-items-start">
    <div>
      <h4 class="alert-title mb-1">Pasien memiliki alergi</h4>
      <div>
        @foreach ($alergi as $a)
          <span class="badge bg-red me-1 mb-1">
            {{ $a->substance }} ({{ $a->severity }})@if ($a->reaction) &middot; {{ $a->reaction }} @endif
          </span>
        @endforeach
      </div>
    </div>
  </div>
@endif

<div class="row g-3">

  {{-- Kolom kiri: identitas, tanda vital, riwayat --}}
  <div class="col-12 col-lg-4">

    <div class="card mb-3">
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item">
            <div class="datagrid-title">No. Rekam Medis</div>
            <div class="datagrid-content font-monospace">{{ $assessment->patient_mrn }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">No. Registrasi</div>
            <div class="datagrid-content font-monospace">{{ $assessment->registration_number }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Unit</div>
            <div class="datagrid-content">{{ $assessment->unit_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Dokter</div>
            <div class="datagrid-content">{{ $assessment->practitioner_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Status catatan</div>
            <div class="datagrid-content">
              @if ($assessment->status === 'draft')
                <span class="badge bg-yellow-lt">Draf &middot; masih bisa disunting</span>
              @elseif ($assessment->status === 'amended')
                <span class="badge bg-orange-lt">Diralat &middot; versi {{ $assessment->version }}</span>
              @else
                <span class="badge bg-green-lt">Final &middot; terkunci</span>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>

    {{-- Alergi --}}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Alergi</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('rme.alergi.simpan', $assessment) }}" class="row g-2">
          @csrf
          <div class="col-12">
            <input type="text" name="substance" class="form-control form-control-sm"
                   placeholder="Zat penyebab, mis. Amoksisilin" required>
          </div>
          <div class="col-6">
            <select name="category" class="form-select form-select-sm">
              <option value="obat">Obat</option>
              <option value="makanan">Makanan</option>
              <option value="lingkungan">Lingkungan</option>
              <option value="lainnya">Lainnya</option>
            </select>
          </div>
          <div class="col-6">
            <select name="severity" class="form-select form-select-sm">
              <option value="ringan">Ringan</option>
              <option value="sedang" selected>Sedang</option>
              <option value="berat">Berat</option>
            </select>
          </div>
          <div class="col-12">
            <input type="text" name="reaction" class="form-control form-control-sm" placeholder="Reaksi, mis. ruam">
          </div>
          <div class="col-12">
            <button class="btn btn-sm btn-outline-danger w-100">Catat Alergi</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Riwayat kunjungan --}}
    <div class="card">
      <div class="card-header"><h3 class="card-title">Riwayat kunjungan</h3></div>
      <div class="list-group list-group-flush">
        @forelse ($riwayat as $r)
          <div class="list-group-item py-2">
            <div class="d-flex justify-content-between">
              <span>{{ \Carbon\Carbon::parse($r->service_date)->format('d-m-Y') }}</span>
              <span class="text-secondary small">{{ $r->unit_name }}</span>
            </div>
          </div>
        @empty
          <div class="list-group-item text-secondary small">Belum ada riwayat kunjungan lain.</div>
        @endforelse
      </div>
    </div>
  </div>

  {{-- Kolom kanan: tanda vital + SOAP + diagnosis --}}
  <div class="col-12 col-lg-8">

    {{-- Skrining awal — dicatat sekali per kunjungan, sebelum asesmen penuh --}}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Skrining Awal</h3></div>
      @if ($skrining)
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Risiko Jatuh</div>
              @php $warnaJatuh = ['rendah' => 'green', 'sedang' => 'yellow', 'tinggi' => 'red'][$skrining->fall_risk_level]; @endphp
              <span class="badge bg-{{ $warnaJatuh }}-lt text-uppercase">{{ $skrining->fall_risk_level }}</span>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Skala Nyeri</div>
              <span class="badge {{ $skrining->pain_score >= 4 ? 'bg-red-lt' : 'bg-secondary-lt' }}">{{ $skrining->pain_score }}/10</span>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Risiko Gizi</div>
              <span class="badge {{ $skrining->nutrition_at_risk ? 'bg-red-lt' : 'bg-green-lt' }}">{{ $skrining->nutrition_at_risk ? 'Berisiko' : 'Tidak berisiko' }}</span>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-secondary small">Gejala Menular</div>
              <span class="badge {{ $skrining->infectious_symptom ? 'bg-red-lt' : 'bg-green-lt' }}">{{ $skrining->infectious_symptom ? 'Ada' : 'Tidak ada' }}</span>
            </div>
            @if ($skrining->special_needs)
              <div class="col-12">
                <div class="text-secondary small">Kebutuhan Khusus</div>
                <div>{{ $skrining->special_needs }}</div>
              </div>
            @endif
          </div>
          <div class="form-hint mt-2">Dicatat {{ $skrining->screened_by_name ?? 'petugas' }}, {{ $skrining->screened_at->format('d-m-Y H:i') }}.</div>
        </div>
      @else
        <div class="card-body">
          <form method="POST" action="{{ route('rme.skrining.simpan', $kunjungan->id) }}" class="row g-2">
            @csrf
            <div class="col-6 col-md-3">
              <label class="form-label">Risiko Jatuh</label>
              <select name="fall_risk_level" class="form-select form-select-sm" required>
                <option value="rendah">Rendah</option>
                <option value="sedang">Sedang</option>
                <option value="tinggi">Tinggi</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Skala Nyeri (0-10)</label>
              <input type="number" name="pain_score" class="form-control form-control-sm" min="0" max="10" value="0" required>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end">
              <label class="form-check">
                <input type="checkbox" name="nutrition_at_risk" value="1" class="form-check-input">
                <span class="form-check-label">Risiko gizi</span>
              </label>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end">
              <label class="form-check">
                <input type="checkbox" name="infectious_symptom" value="1" class="form-check-input">
                <span class="form-check-label">Gejala menular</span>
              </label>
            </div>
            <div class="col-12">
              <input type="text" name="special_needs" class="form-control form-control-sm" placeholder="Kebutuhan khusus (opsional): penerjemah, disabilitas, dsb.">
            </div>
            <div class="col-12">
              <button class="btn btn-sm btn-outline-primary w-100">Catat Skrining</button>
            </div>
          </form>
        </div>
      @endif
    </div>

    {{-- Tindakan rawat jalan — setiap baris otomatis tertagih lewat sinkronisasi billing --}}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Tindakan</h3></div>
      @if ($tindakan->isNotEmpty())
        <div class="table-responsive">
          <table class="table table-sm table-vcenter mb-0">
            <thead><tr><th>Tindakan</th><th class="text-end">Jml</th><th class="text-end">Tarif</th><th class="text-end">Total</th><th>Waktu</th></tr></thead>
            <tbody>
              @foreach ($tindakan as $t)
                <tr>
                  <td>{{ $t->service_name }}@if ($t->note)<div class="text-secondary small">{{ $t->note }}</div>@endif</td>
                  <td class="text-end">{{ rtrim(rtrim($t->quantity, '0'), '.') }}</td>
                  <td class="text-end">{{ number_format($t->unit_price, 0, ',', '.') }}</td>
                  <td class="text-end">{{ number_format($t->amount, 0, ',', '.') }}</td>
                  <td class="text-secondary small">{{ $t->performed_at->format('d-m H:i') }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
      <div class="card-body {{ $tindakan->isNotEmpty() ? 'border-top' : '' }}">
        <form method="POST" action="{{ route('rme.tindakan.simpan', $kunjungan->id) }}" class="row g-2">
          @csrf
          <div class="col-6">
            <select name="service_code" class="form-select form-select-sm" required>
              <option value="">— pilih tindakan —</option>
              @foreach ($katalogTindakan as $layanan)
                <option value="{{ $layanan->code }}">{{ $layanan->name }}</option>
              @endforeach
            </select>
            @if ($katalogTindakan->isEmpty())
              <div class="form-hint text-danger">Belum ada layanan berkategori tindakan di Data Master.</div>
            @endif
          </div>
          <div class="col-3"><input type="number" name="quantity" class="form-control form-control-sm" value="1" min="0.01" step="0.01" required></div>
          <div class="col-3"><button class="btn btn-sm btn-outline-primary w-100">Catat</button></div>
          <div class="col-12"><input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan (opsional)"></div>
        </form>
      </div>
    </div>

    {{-- Operasi — gerbang sendiri (operasi), bukan umbrella, lihat catatan rute --}}
    @can('operasi')
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Operasi</h3></div>
        @if ($operasi->isNotEmpty())
          <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0">
              <thead><tr><th>Tindakan</th><th>Operator</th><th>Anestesi</th><th class="text-end">Tarif</th><th>Waktu</th></tr></thead>
              <tbody>
                @foreach ($operasi as $o)
                  <tr>
                    <td>{{ $o->service_name }}@if ($o->note)<div class="text-secondary small">{{ $o->note }}</div>@endif</td>
                    <td>{{ $o->surgeon_name }} {{ $o->operating_room ? '· ' . $o->operating_room : '' }}</td>
                    <td>{{ $o->anesthesia_type ?? '—' }}</td>
                    <td class="text-end">{{ number_format($o->amount, 0, ',', '.') }}</td>
                    <td class="text-secondary small">{{ $o->performed_at->format('d-m H:i') }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
        <div class="card-body {{ $operasi->isNotEmpty() ? 'border-top' : '' }}">
          <form method="POST" action="{{ route('rme.operasi.simpan', $kunjungan->id) }}" class="row g-2">
            @csrf
            <div class="col-6">
              <select name="service_code" class="form-select form-select-sm" required>
                <option value="">— pilih tindakan operasi —</option>
                @foreach ($katalogOperasi as $layanan)
                  <option value="{{ $layanan->code }}">{{ $layanan->name }}</option>
                @endforeach
              </select>
              @if ($katalogOperasi->isEmpty())
                <div class="form-hint text-danger">Belum ada layanan berkategori operasi di Data Master.</div>
              @endif
            </div>
            <div class="col-6"><input type="text" name="surgeon_name" class="form-control form-control-sm" placeholder="Nama operator" required></div>
            <div class="col-4">
              <select name="anesthesia_type" class="form-select form-select-sm">
                <option value="">— Anestesi —</option>
                <option value="umum">Umum</option>
                <option value="lokal">Lokal</option>
                <option value="regional">Regional</option>
                <option value="tanpa">Tanpa</option>
              </select>
            </div>
            <div class="col-4"><input type="text" name="operating_room" class="form-control form-control-sm" placeholder="Ruang Operasi"></div>
            <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Catat</button></div>
            <div class="col-12"><input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan (opsional)"></div>
          </form>
        </div>
      </div>
    @endcan

    <form method="POST" action="{{ route('rme.update', $assessment) }}">
      @csrf

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Tanda vital</h3></div>
        <div class="card-body">
          <div class="row g-3">
            @foreach ($katalogObservasi as $kode => [$label, $satuan, $min, $max])
              <div class="col-6 col-md-4">
                <label class="form-label" for="vital-{{ $kode }}">{{ $label }}</label>
                <div class="input-group input-group-flat">
                  <input type="number" step="0.01" id="vital-{{ $kode }}" name="vital[{{ $kode }}]"
                         class="form-control" placeholder="—">
                  <span class="input-group-text">{{ $satuan }}</span>
                </div>
                @if (isset($observasi[$kode]))
                  <div class="form-hint {{ $observasi[$kode]->is_abnormal ? 'text-danger' : '' }}">
                    Terakhir: {{ rtrim(rtrim($observasi[$kode]->value_numeric, '0'), '.') }} {{ $satuan }}
                    @if ($observasi[$kode]->is_abnormal) &middot; di luar rentang @endif
                  </div>
                @elseif ($min !== null)
                  <div class="form-hint">Rujukan {{ $min }}–{{ $max }}</div>
                @endif
              </div>
            @endforeach
          </div>
          <div class="form-hint mt-3">
            Pengukuran bersifat menambah, bukan menimpa. Nilai sebelumnya tetap tersimpan sebagai riwayat tren.
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">{{ \App\Modules\Clinical\Models\Assessment::kindLabel($assessment->kind) }}</h3>
          @if ($assessment->isLocked())
            <span class="text-secondary small">Terkunci &middot; perubahan tercatat sebagai ralat</span>
          @endif
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="chief_complaint">Keluhan utama</label>
            <input type="text" id="chief_complaint" name="chief_complaint" class="form-control"
                   value="{{ old('chief_complaint', $assessment->chief_complaint) }}">
          </div>

          @php
            $bagian = [
              'subjective' => ['S — Subjektif', 'Keluhan, riwayat penyakit, riwayat pengobatan'],
              'objective'  => ['O — Objektif', 'Hasil pemeriksaan fisik dan penunjang'],
              'assessment' => ['A — Asesmen', 'Penilaian dan pertimbangan klinis'],
              'plan'       => ['P — Rencana', 'Tata laksana, edukasi, rencana tindak lanjut'],
            ];
          @endphp

          @foreach ($bagian as $nama => [$label, $petunjuk])
            <div class="mb-3">
              <label class="form-label" for="{{ $nama }}">{{ $label }}</label>
              <textarea id="{{ $nama }}" name="{{ $nama }}" class="form-control" rows="3"
                        placeholder="{{ $petunjuk }}">{{ old($nama, $assessment->$nama) }}</textarea>
            </div>
          @endforeach

          @if ($assessment->isLocked())
            <div class="mb-0">
              <label class="form-label required" for="alasan_ralat">Alasan ralat</label>
              <input type="text" id="alasan_ralat" name="alasan_ralat" class="form-control"
                     placeholder="Wajib diisi untuk mengubah catatan yang sudah difinalkan">
              <div class="form-hint">
                Versi sebelumnya disimpan utuh sebagai riwayat, sesuai syarat jejak audit Permenkes 24/2022.
              </div>
            </div>
          @endif
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
          <button type="submit" class="btn btn-primary">Simpan Catatan</button>
        </div>
      </div>
    </form>

    {{-- Diagnosis --}}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Diagnosis</h3></div>

      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr><th>Kode</th><th>Diagnosis</th><th>Peringkat</th><th>Kepastian</th><th class="w-1"></th></tr>
          </thead>
          <tbody>
            @forelse ($diagnosis as $d)
              <tr>
                <td class="font-monospace">{{ $d->code }}</td>
                <td>{{ $d->display }}</td>
                <td>
                  <span class="badge bg-{{ $d->rank === 'utama' ? 'blue' : 'secondary' }}-lt">{{ $d->rank }}</span>
                </td>
                <td class="text-secondary">{{ $d->certainty }}</td>
                <td>
                  <form method="POST" action="{{ route('rme.diagnosis.hapus', $d) }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-ghost-danger">Hapus</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-secondary text-center py-3">Belum ada diagnosis dicatat.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="card-body border-top">
        <form method="POST" action="{{ route('rme.diagnosis.simpan', $assessment) }}" class="row g-2 align-items-end">
          @csrf
          <div class="col-12 col-md-5">
            <label class="form-label" for="kode-diagnosis">Kode ICD-10</label>
            <input type="text" id="kode-diagnosis" name="code" class="form-control" list="daftar-icd"
                   placeholder="Ketik kode atau nama diagnosis" autocomplete="off" required>
            <datalist id="daftar-icd"></datalist>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="rank">Peringkat</label>
            <select id="rank" name="rank" class="form-select">
              <option value="utama">Utama</option>
              <option value="sekunder" selected>Sekunder</option>
              <option value="komplikasi">Komplikasi</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label" for="certainty">Kepastian</label>
            <select id="certainty" name="certainty" class="form-select">
              <option value="suspek">Suspek</option>
              <option value="kerja" selected>Kerja</option>
              <option value="definitif">Definitif</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <button class="btn btn-outline-primary w-100">Tambah</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Riwayat ralat --}}
    @if ($revisi->isNotEmpty())
      <div class="card">
        <div class="card-header"><h3 class="card-title">Riwayat ralat catatan</h3></div>
        <div class="list-group list-group-flush">
          @foreach ($revisi as $r)
            <div class="list-group-item">
              <div class="d-flex justify-content-between">
                <strong>Versi {{ $r->version }}</strong>
                <span class="text-secondary small">
                  {{ $r->revised_at->format('d-m-Y H:i') }} &middot; {{ $r->revised_by_name ?? 'sistem' }}
                </span>
              </div>
              <div class="text-secondary small mt-1">Alasan: {{ $r->reason }}</div>
            </div>
          @endforeach
        </div>
      </div>
    @endif
  </div>
</div>

{{-- Resep --}}
@can('resep_obat')
  <div class="card mt-3">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <strong>Resep obat</strong>
        <div class="text-secondary small">
          Membuka resep untuk kunjungan ini, atau melanjutkan resep yang sudah ditulis. Resep pulang
          (resep_pulang) terpisah dari resep rawat jalan — dipakai menjelang pasien pulang, mis. dari ranap.
        </div>
      </div>
      <div class="d-flex gap-2">
        <form method="POST" action="{{ route('resep.buat', $assessment->registration_id) }}">
          @csrf
          <button class="btn btn-outline-primary">Tulis Resep</button>
        </form>
        <form method="POST" action="{{ route('resep.buat', $assessment->registration_id) }}">
          @csrf
          <input type="hidden" name="kind" value="pulang">
          <button class="btn btn-outline-secondary">Resep Pulang</button>
        </form>
      </div>
    </div>
  </div>
@endcan

{{-- Order penunjang --}}
<div class="row g-3 mt-0">
  @can('periksa_lab')
    <div class="col-12 col-md-4">
      <div class="card h-100">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <strong>Laboratorium</strong>
            <div class="text-secondary small">Buka atau lanjutkan order lab kunjungan ini.</div>
          </div>
          <form method="POST" action="{{ route('order.buat', ['lab', $assessment->registration_id]) }}">
            @csrf
            <button class="btn btn-outline-primary btn-sm">Order Lab</button>
          </form>
        </div>
      </div>
    </div>
  @endcan
  @can('periksa_radiologi')
    <div class="col-12 col-md-4">
      <div class="card h-100">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <strong>Radiologi</strong>
            <div class="text-secondary small">Buka atau lanjutkan order radiologi kunjungan ini.</div>
          </div>
          <form method="POST" action="{{ route('order.buat', ['radiologi', $assessment->registration_id]) }}">
            @csrf
            <button class="btn btn-outline-primary btn-sm">Order Radiologi</button>
          </form>
        </div>
      </div>
    </div>
  @endcan
  @can('pemeriksaan_lab_pa')
    <div class="col-12 col-md-4">
      <div class="card h-100">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <strong>Patologi Anatomi</strong>
            <div class="text-secondary small">Buka atau lanjutkan order PA kunjungan ini.</div>
          </div>
          <form method="POST" action="{{ route('order.buat', ['pa', $assessment->registration_id]) }}">
            @csrf
            <button class="btn btn-outline-primary btn-sm">Order PA</button>
          </form>
        </div>
      </div>
    </div>
  @endcan
</div>

{{-- Finalkan --}}
@unless ($assessment->isLocked())
  <form method="POST" action="{{ route('rme.finalkan', $assessment) }}" class="mt-3">
    @csrf
    <div class="card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <strong>Finalkan asesmen</strong>
          <div class="text-secondary small">
            Setelah difinalkan, catatan terkunci. Perubahan berikutnya wajib menyertakan alasan
            dan tersimpan sebagai versi baru.
          </div>
        </div>
        <button class="btn btn-success">Finalkan</button>
      </div>
    </div>
  </form>
@endunless

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
