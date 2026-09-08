@extends('layouts.app')

@section('title', 'Tata Usaha — Surat')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Surat Masuk & Surat Keluar')

@section('actions')
  <a href="{{ route('correspondence.master.index') }}" class="btn btn-link">Master Arsip &rarr;</a>
  <a href="{{ route('correspondence.pengumuman.index') }}" class="btn btn-link">Pengumuman &rarr;</a>
@endsection

@section('content')

@php
  $labelKode = fn (string $k) => ucwords(str_replace('-', ' ', $k));
@endphp

{{--
  Tunggakan tampil TERPISAH dan di atas. Daftar surat mengurut dari yang
  terbaru, dan itu justru menyembunyikan surat lama yang belum dibalas
  serta disposisi yang lewat tenggat — padahal itulah yang perlu dikejar.
--}}
@if ($balasTerlambat->isNotEmpty() || $disposisiTerlambat->isNotEmpty())
  <div class="card mb-3 border-warning">
    <div class="card-header"><h3 class="card-title">Tunggakan</h3></div>
    <div class="card-body">
      @if ($balasTerlambat->isNotEmpty())
        <div class="mb-2">
          <b class="small">Lewat tenggat balas ({{ $balasTerlambat->count() }})</b>
          <ul class="mb-0 small">
            @foreach ($balasTerlambat as $s)
              <li>{{ $s->letter_number }} &middot; {{ $s->sender }} &mdash; {{ $s->subject }}
                <span class="text-danger">(tenggat {{ $s->reply_due_date->format('d-m-Y') }})</span></li>
            @endforeach
          </ul>
        </div>
      @endif
      @if ($disposisiTerlambat->isNotEmpty())
        <div>
          <b class="small">Disposisi lewat tenggat ({{ $disposisiTerlambat->count() }})</b>
          <ul class="mb-0 small">
            @foreach ($disposisiTerlambat as $d)
              <li>{{ $d->to_name }} &mdash; {{ $d->instruction }}
                <span class="text-danger">(tenggat {{ $d->due_date->format('d-m-Y') }})</span>
                <form method="POST" action="{{ route('correspondence.disposisi.selesai', $d) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-link p-0">tandai selesai</button>
                </form>
              </li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
  </div>
@endif

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Surat Masuk</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('correspondence.masuk.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12"><label class="form-label">Pengirim</label><input type="text" name="sender" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Perihal</label><input type="text" name="subject" class="form-control" required></div>
          <div class="col-6"><label class="form-label">No. Surat Asli</label><input type="text" name="reference_number" class="form-control"></div>
          <div class="col-6"><label class="form-label">Tanggal Diterima</label><input type="date" name="received_at" class="form-control" value="{{ now()->toDateString() }}" required></div>

          {{--
            Dua sumbu, bukan satu. Sifat menjawab seberapa terbatas surat
            boleh dibaca; derajat menjawab seberapa cepat ia ditangani.
            Surat rahasia yang juga mendesak adalah keadaan yang lazim.
          --}}
          <div class="col-6">
            <label class="form-label">Sifat</label>
            <select name="security" class="form-select" required>
              @foreach ($sifat as $s)
                <option value="{{ $s }}">{{ $labelKode($s) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Derajat</label>
            <select name="urgency" class="form-select" required>
              @foreach ($derajat as $d)
                <option value="{{ $d }}">{{ $labelKode($d) }}</option>
              @endforeach
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Klasifikasi Arsip</label>
            <select name="classification_id" class="form-select">
              <option value="">&mdash; belum ditetapkan &mdash;</option>
              @foreach ($klasifikasi as $k)
                <option value="{{ $k->id }}">{{ $k->code }} &middot; {{ $k->name }}</option>
              @endforeach
            </select>
            @if ($klasifikasi->isEmpty())
              <div class="form-hint">Pola klasifikasi arsip belum ditetapkan RSP UI &mdash; isi di Master Arsip.</div>
            @endif
          </div>

          <div class="col-6">
            <label class="form-label">Perlu Dibalas?</label>
            <select name="reply_status" class="form-select">
              <option value="tidak-perlu">Tidak perlu</option>
              <option value="menunggu">Menunggu balasan</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label">Tenggat Balas</label><input type="date" name="reply_due_date" class="form-control"></div>

          <div class="col-6"><label class="form-label">Lampiran</label><input type="text" name="attachment_note" class="form-control"></div>
          <div class="col-6"><label class="form-label">Tembusan</label><input type="text" name="copy_to" class="form-control"></div>

          <div class="col-12"><button class="btn btn-primary">Catat</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Surat Masuk</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Agenda</th><th>Pengirim</th><th>Sifat/Derajat</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($masuk as $s)
              <tr>
                <td class="font-monospace small">{{ $s->letter_number }}</td>
                <td>{{ $s->sender }}<div class="text-secondary small">{{ $s->subject }}</div></td>
                <td class="small">
                  <span class="badge bg-{{ $s->security === 'biasa' ? 'secondary' : 'red' }}-lt">{{ $labelKode((string) $s->security) }}</span>
                  <span class="badge bg-{{ $s->urgency === 'biasa' ? 'secondary' : 'orange' }}-lt">{{ $labelKode((string) $s->urgency) }}</span>
                  @if ($s->reply_status === 'menunggu')
                    <div class="{{ $s->terlambatDibalas() ? 'text-danger' : 'text-secondary' }}">
                      balas s.d. {{ $s->reply_due_date?->format('d-m-Y') ?? '—' }}
                    </div>
                  @elseif ($s->reply_status === 'sudah-dibalas')
                    <div class="text-secondary">sudah dibalas</div>
                  @endif
                </td>
                <td>
                  @php $warna = ['diterima' => 'yellow', 'didisposisikan' => 'blue', 'diarsipkan' => 'secondary'][$s->status]; @endphp
                  <span class="badge bg-{{ $warna }}-lt">{{ $s->status }}</span>
                  @foreach ($s->dispositions as $d)
                    <div class="text-secondary small">
                      {{ $d->sequence }}. &rarr; {{ $d->to_name }}
                      @if ($d->completed_at) <span class="text-success">selesai</span>
                      @elseif ($d->terlambat()) <span class="text-danger">terlambat</span>
                      @endif
                    </div>
                  @endforeach
                </td>
                <td>
                  @if ($s->status !== 'diarsipkan')
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#disposisi-{{ $s->id }}">Disposisi</button>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#arsip-{{ $s->id }}">Arsipkan</button>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada surat masuk.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Buat Surat Keluar</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('correspondence.keluar.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12"><label class="form-label">Tujuan</label><input type="text" name="recipient" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Perihal</label><input type="text" name="subject" class="form-control" required></div>
          <div class="col-6">
            <label class="form-label">Sifat</label>
            <select name="security" class="form-select" required>
              @foreach ($sifat as $s)
                <option value="{{ $s }}">{{ $labelKode($s) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Derajat</label>
            <select name="urgency" class="form-select" required>
              @foreach ($derajat as $d)
                <option value="{{ $d }}">{{ $labelKode($d) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Klasifikasi Arsip</label>
            <select name="classification_id" class="form-select">
              <option value="">&mdash; nomor urut lama &mdash;</option>
              @foreach ($klasifikasi as $k)
                <option value="{{ $k->id }}">{{ $k->code }} &middot; {{ $k->name }}</option>
              @endforeach
            </select>
            <div class="form-hint">
              Dengan klasifikasi, nomornya berurut per klasifikasi per tahun
              (mis. <code>001/KP.01/RSPUI/IX/2026</code>). Tanpa klasifikasi, nomor urut lama dipakai
              &mdash; kode klasifikasi tidak dikarang supaya nomornya kelihatan lengkap.
            </div>
          </div>
          <div class="col-6"><label class="form-label">Lampiran</label><input type="text" name="attachment_note" class="form-control"></div>
          <div class="col-6"><label class="form-label">Tembusan</label><input type="text" name="copy_to" class="form-control"></div>
          <div class="col-12"><button class="btn btn-primary">Simpan sebagai Draf</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Surat Keluar</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Surat</th><th>Tujuan</th><th>Perihal</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($keluar as $s)
              <tr>
                <td class="font-monospace small">{{ $s->letter_number }}</td>
                <td>{{ $s->recipient }}</td>
                <td class="text-secondary small">{{ $s->subject }}</td>
                <td>
                  @php $warna = ['draft' => 'yellow', 'terkirim' => 'green'][$s->status]; @endphp
                  <span class="badge bg-{{ $warna }}-lt">{{ $s->status }}</span>
                </td>
                <td>
                  @if ($s->isDraft())
                    <form method="POST" action="{{ route('correspondence.keluar.kirim', $s) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-success">Kirim</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada surat keluar.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@foreach ($masuk as $s)
  @if ($s->status !== 'diarsipkan')
    <div class="modal fade" id="disposisi-{{ $s->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('correspondence.masuk.disposisi', $s) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Disposisi {{ $s->letter_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label class="form-label">Diteruskan Ke</label><input type="text" name="to_name" class="form-control" required></div>
            <div class="mb-2">
              <label class="form-label">Isi Disposisi</label>
              <textarea name="instruction" class="form-control" rows="2" required></textarea>
              <div class="form-hint">Disposisi tanpa isi hanya memindahkan kertas.</div>
            </div>
            <div class="mb-2">
              <label class="form-label">Tenggat</label>
              <input type="date" name="due_date" class="form-control">
              <div class="form-hint">Tanpa tenggat, disposisi ini tidak akan pernah muncul sebagai tunggakan.</div>
            </div>
            <div>
              <label class="form-label">Indeks</label>
              <select name="index_term_id" class="form-select">
                <option value="">&mdash;</option>
                @foreach ($indeks as $i)
                  <option value="{{ $i->id }}">{{ $i->code }} &middot; {{ $i->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Disposisikan</button></div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="arsip-{{ $s->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('correspondence.masuk.arsip', $s) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Arsipkan {{ $s->letter_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Disimpan di Map</label>
            <select name="location_id" class="form-select">
              <option value="">&mdash; belum ditentukan &mdash;</option>
              @foreach ($penyimpanan as $id => $jalur)
                <option value="{{ $id }}">{{ $jalur }}</option>
              @endforeach
            </select>
            <div class="form-hint">
              Hanya map yang bisa dipilih: surat disimpan DI DALAM map, dan mencatat
              &ldquo;ruang arsip&rdquo; saja tidak menuntun siapa pun ke berkasnya.
            </div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Arsipkan</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
