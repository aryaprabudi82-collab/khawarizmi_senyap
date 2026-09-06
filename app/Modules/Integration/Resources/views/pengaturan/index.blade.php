@extends('layouts.app')

@section('title', 'Pengaturan Integrasi')
@section('breadcrumb', 'Konteks integration')
@section('heading', 'Pengaturan Integrasi')

@section('actions')
  <a href="{{ route('integrasi.bpjs.index') }}" class="btn btn-link">BPJS &rarr;</a>
  <a href="{{ route('integrasi.satusehat.index') }}" class="btn btn-link">SATUSEHAT &rarr;</a>
@endsection

@section('content')

@if (session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if (session('peringatan'))
  <div class="alert alert-warning">{{ session('peringatan') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row g-3 mb-3">
  <div class="col-12 col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-secondary small">Sistem siap dipakai</div>
      <div class="h1 mb-0">{{ $siap }}<span class="h4 text-secondary"> / {{ $total }}</span></div>
    </div></div>
  </div>
  <div class="col-12 col-md-8">
    <div class="alert alert-info h-100 mb-0">
      <b>Begitu seluruh kolom wajib satu sistem terisi, sistem itu langsung memakai sambungan aslinya</b> &mdash;
      tanpa penerapan ulang aplikasi. Selama masih ada kolom wajib yang kosong, sistem tetap memakai
      adapter simulasi, supaya alur kerja di layar lain tidak berhenti.
    </div>
  </div>
</div>

<div class="alert alert-warning">
  <b>Kredensial disimpan terenkripsi dengan APP_KEY aplikasi.</b>
  Artinya isinya tidak terbaca dari basis data maupun salinan cadangan &mdash; tapi juga berarti
  <b>kehilangan APP_KEY sama dengan kehilangan seluruh kredensial di halaman ini</b>, dan semuanya harus
  dimasukkan ulang. Simpan APP_KEY bersama dokumen pemulihan, bukan hanya di server.
</div>

<div class="alert alert-secondary">
  <b>Nilai rahasia tidak pernah ditampilkan kembali.</b> Yang terlihat cuma empat huruf terakhirnya, cukup
  untuk memastikan yang terpasang benar. Mengosongkan kotak isian berarti <b>jangan diubah</b>; untuk
  menghapus, pakai tombol hapus di sebelah kolomnya.
</div>

@foreach ($sistem as $s)
  @php $uji = $ujiTerakhir[$s->system] ?? null; @endphp

  <div class="card mb-3">
    <div class="card-header">
      <div>
        <h3 class="card-title">
          {{ $s->label }}
          @if ($s->ready)
            <span class="badge bg-success">siap dipakai</span>
          @else
            <span class="badge bg-secondary">simulasi</span>
          @endif
        </h3>
        <div class="card-subtitle">{{ $s->description }}</div>
      </div>
    </div>

    <div class="card-body border-bottom">
      <div class="row g-3 align-items-center">
        <div class="col-12 col-lg-8">
          <div class="text-secondary small">{{ $s->doc }}</div>
          @unless ($s->ready)
            <div class="text-danger small mt-1">
              Kolom wajib yang masih kosong: <b>{{ implode(', ', $s->missing) }}</b>
            </div>
          @endunless
        </div>
        <div class="col-12 col-lg-4 text-lg-end">
          @if ($uji)
            <div class="small {{ $uji->success ? 'text-success' : 'text-danger' }}">
              Uji terakhir: {{ $uji->success ? 'tersambung' : 'gagal' }}
              <span class="text-secondary">({{ $uji->checked_at->diffForHumans() }}{{ $uji->duration_ms ? ', ' . $uji->duration_ms . ' ms' : '' }})</span>
            </div>
            <div class="text-secondary small">{{ $uji->message }}</div>
          @else
            <div class="text-secondary small">Belum pernah diuji koneksinya.</div>
          @endif

          <form method="POST" action="{{ route('integrasi.pengaturan.uji', $s->system) }}" class="mt-2">
            @csrf
            <button class="btn btn-sm btn-outline-primary" @disabled(! $s->ready)>Uji Koneksi</button>
          </form>
        </div>
      </div>
    </div>

    <form method="POST" action="{{ route('integrasi.pengaturan.simpan', $s->system) }}" class="card-body">
      @csrf
      <div class="row g-3">
        @foreach ($s->fields as $f)
          <div class="col-12 col-md-6">
            <label class="form-label">
              {{ $f->label }}
              {!! $f->required ? '<span class="text-danger">*</span>' : '' !!}
              @if ($f->filled)
                <span class="badge bg-success-lt">terisi</span>
              @endif
              @if ($f->from_env)
                <span class="badge bg-azure-lt" title="Masih dibaca dari berkas .env">dari .env</span>
              @endif
            </label>

            <div class="d-flex gap-1">
              <input
                type="{{ $f->secret ? 'password' : 'text' }}"
                name="fields[{{ $f->field }}]"
                class="form-control"
                autocomplete="new-password"
                value="{{ $f->secret ? '' : ($f->display ?? '') }}"
                placeholder="{{ $f->secret && $f->filled ? $f->display . ' — kosongkan bila tidak diubah' : '' }}">

              @if ($f->filled && ! $f->from_env)
                <button
                  form="hapus-{{ $s->system }}-{{ $f->field }}"
                  class="btn btn-outline-danger"
                  title="Hapus nilai ini">&times;</button>
              @endif
            </div>

            @if ($f->hint)
              <div class="form-hint">{{ $f->hint }}</div>
            @endif
            @if ($f->updated_by_name)
              <div class="form-hint text-secondary">
                Terakhir diubah {{ $f->updated_by_name }}, {{ $f->updated_at?->diffForHumans() }}
              </div>
            @endif
          </div>
        @endforeach
      </div>

      <button class="btn btn-primary mt-3">Simpan {{ $s->label }}</button>
    </form>

    {{-- Formulir hapus diletakkan di luar formulir simpan: form bersarang tidak sah di HTML. --}}
    @foreach ($s->fields as $f)
      @if ($f->filled && ! $f->from_env)
        <form method="POST" id="hapus-{{ $s->system }}-{{ $f->field }}"
              action="{{ route('integrasi.pengaturan.hapus', [$s->system, $f->field]) }}" class="d-none">
          @csrf
        </form>
      @endif
    @endforeach
  </div>
@endforeach

<div class="card">
  <div class="card-header"><h3 class="card-title">Yang masih perlu dilakukan di luar halaman ini</h3></div>
  <div class="card-body">
    <p class="mb-2">Mengisi kredensial membuat sambungannya hidup, tapi tiga hal berikut tetap perlu
       dikerjakan sebelum integrasinya benar-benar bisa dipakai untuk pasien:</p>
    <ul class="mb-0">
      <li><b>Verifikasi terhadap sandbox resmi.</b> Bentuk permintaan tiap adapter mengikuti dokumentasi
          yang beredar dan belum pernah diadu dengan endpoint sungguhan. Uji koneksi di halaman ini
          memastikan kredensialnya diterima &mdash; bukan bahwa setiap bentuk permintaan sudah benar.</li>
      <li><b>Pemetaan kode.</b> SATUSEHAT menuntut kode SNOMED/LOINC/KFA, dan BPJS menuntut kode poli serta
          dokter versi mereka. Yang belum dipetakan tidak akan dikirim &mdash; jumlahnya terlihat di layar
          pemetaan masing-masing.</li>
      <li><b>Kode faskes yang benar.</b> Kode PPK BPJS dan Organization ID SATUSEHAT menentukan data ini
          tercatat atas nama rumah sakit yang mana. Salah di sini berarti data pasien RSP UI tercatat
          sebagai milik faskes lain.</li>
    </ul>
  </div>
</div>

@endsection
