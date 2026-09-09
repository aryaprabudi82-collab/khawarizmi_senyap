@extends('layouts.app')

@section('title', 'Mutu — Master ICRA')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'Master ICRA — Matriks, Area &amp; Pengendalian')

@section('actions')
  <a href="{{ route('quality.icra.index') }}" class="btn btn-link">&larr; Kajian Pra-Konstruksi</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Matriks ICRA</h3></div>
  <div class="card-body">
    <div class="alert alert-warning mb-0">
      <b>Isian awal matriks ini mengikuti matriks ICRA yang lazim diterbitkan, dan PERLU DIPERIKSA
      IPCN RSP UI sebelum dipakai sungguhan.</b> Matriks yang keliru menghasilkan kelas pencegahan
      resmi yang keliru untuk setiap proyek sesudahnya &mdash; dan kelas menentukan pengendalian
      yang wajib dipasang, jadi kelas terlalu rendah berarti konstruksi berjalan tanpa barrier di
      sebelah pasien yang paling rentan.
    </div>
  </div>
  <div class="card-body pt-0">
    <div class="form-hint mb-2">
      Sel bernilai <b>rentang</b> (mis. III/IV) bukan sel yang belum diputuskan: pedomannya memang
      menyerahkan pilihan kepada komite pengendalian infeksi. Memaksanya jadi satu kelas akan
      menyembunyikan keputusan yang pedomannya justru mensyaratkan ada. Kosongkan
      <i>kelas maksimum</i> untuk sel bernilai tunggal.
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Tipe Aktivitas</th><th>Kelompok Risiko Area</th><th>Kelas</th><th class="w-1"></th></tr></thead>
      <tbody>
        @foreach ($matriks->sortBy([['activity_type_id', 'asc'], ['risk_group_id', 'asc']]) as $sel)
          <tr>
            <td class="small">{{ $sel->activityType->name }}</td>
            <td class="small">{{ $sel->riskGroup->name }}</td>
            <td>
              <span class="badge bg-{{ $sel->butuhKeputusanKomite() ? 'yellow' : 'blue' }}-lt">
                {{ $sel->minClass->code }}{{ $sel->max_class_id ? '/'.$sel->maxClass->code : '' }}
              </span>
              @if ($sel->butuhKeputusanKomite())
                <div class="small text-secondary">butuh keputusan komite</div>
              @endif
            </td>
            <td>
              <form method="POST" action="{{ route('quality.icra.master.matriks.perbarui', $sel) }}" class="d-flex gap-1">
                @csrf
                <select name="min_class_id" class="form-select form-select-sm" style="width:8rem">
                  @foreach ($kelas as $k)
                    <option value="{{ $k->id }}" @selected($sel->min_class_id === $k->id)>{{ $k->code }}</option>
                  @endforeach
                </select>
                <select name="max_class_id" class="form-select form-select-sm" style="width:9rem">
                  <option value="">&mdash; tunggal &mdash;</option>
                  @foreach ($kelas as $k)
                    <option value="{{ $k->id }}" @selected($sel->max_class_id === $k->id)>s.d. {{ $k->code }}</option>
                  @endforeach
                </select>
                <button class="btn btn-sm btn-outline-primary">Simpan</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Area &amp; Kelompok Risikonya</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      <b>Sengaja lahir kosong.</b> Nama kelompok risikonya dari pedoman, tapi ruang mana masuk
      kelompok mana adalah keputusan RSP UI &mdash; ruang endoskopi bisa masuk Tinggi di satu rumah
      sakit dan Sangat Tinggi di rumah sakit lain, tergantung layanan apa yang ada di sebelahnya.
      Menebaknya berarti menentukan pengendalian konstruksi untuk ruang yang belum pernah ditinjau
      siapa pun.
    </div>
    <form method="POST" action="{{ route('quality.icra.master.area.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-12 col-md-4"><label class="form-label">Nama Area</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-12 col-md-3">
        <label class="form-label">Kelompok Risiko</label>
        <select name="risk_group_id" class="form-select" required>
          @foreach ($kelompok as $g)
            <option value="{{ $g->id }}">{{ $g->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Unit</label>
        <select name="unit_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($unit as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
      <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control"></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Area</th><th>Kelompok Risiko</th><th>Catatan</th></tr></thead>
      <tbody>
        @forelse ($area as $a)
          <tr>
            <td class="font-monospace small">{{ $a->code }}</td>
            <td>{{ $a->name }}</td>
            <td><span class="badge bg-blue-lt">{{ $a->riskGroup->name }}</span></td>
            <td class="small text-secondary">{{ $a->note }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary py-3">Belum ditetapkan RSP UI.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Tindakan Pengendalian</h3></div>
      <div class="card-body">
        <div class="form-hint mb-2">
          Lahir kosong: kalimatnya adalah SPO RSP UI, dan mengarangnya berarti menerbitkan perintah
          kerja konstruksi yang tidak pernah disahkan siapa pun.
        </div>
        <form method="POST" action="{{ route('quality.icra.master.tindakan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
          <div class="col-8"><label class="form-label">Tindakan</label><input type="text" name="name" class="form-control" required></div>
          <div class="col-8">
            <label class="form-label">Kelas Terendah yang Mewajibkan</label>
            <select name="precaution_class_id" class="form-select">
              <option value="">&mdash; berlaku umum &mdash;</option>
              @foreach ($kelas as $k)
                <option value="{{ $k->id }}">{{ $k->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-4 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Tindakan</th><th>Kelas</th></tr></thead>
          <tbody>
            @forelse ($tindakan as $t)
              <tr>
                <td class="font-monospace small">{{ $t->code }}</td>
                <td class="small">{{ $t->name }}</td>
                <td class="small">{{ $t->precautionClass?->code ?: 'umum' }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ditetapkan RSP UI.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Persyaratan per Kelas</h3></div>
      <div class="card-body">
        <div class="form-hint mb-2">Juga lahir kosong, dengan alasan yang sama.</div>
        <form method="POST" action="{{ route('quality.icra.master.persyaratan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-4">
            <label class="form-label">Kelas</label>
            <select name="precaution_class_id" class="form-select" required>
              @foreach ($kelas as $k)
                <option value="{{ $k->id }}">{{ $k->code }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-8"><label class="form-label">Persyaratan</label><input type="text" name="requirement" class="form-control" required></div>
          <div class="col-12"><button class="btn btn-primary">Tambah</button></div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kelas</th><th>#</th><th>Persyaratan</th></tr></thead>
          <tbody>
            @forelse ($persyaratan as $p)
              <tr>
                <td class="small">{{ $p->precautionClass->code }}</td>
                <td class="small">{{ $p->position }}</td>
                <td class="small">{{ $p->requirement }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ditetapkan RSP UI.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@endsection
