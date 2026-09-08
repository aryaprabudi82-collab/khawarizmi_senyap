@extends('layouts.app')

@section('title', 'Tata Usaha — Persetujuan Tindakan')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Persetujuan & Penolakan Tindakan')

@section('actions')
  @can('template_persetujuan_penolakan_tindakan')
    <a href="{{ route('correspondence.persetujuan.master.index') }}" class="btn btn-link">Master Persetujuan &rarr;</a>
  @endcan
  <a href="{{ route('correspondence.keterangan.index') }}" class="btn btn-link">Surat Keterangan &rarr;</a>
@endsection

@section('content')

@php
  $labelHubungan = fn (string $h) => ucwords(str_replace('-', ' ', $h));
@endphp

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Persetujuan Tindakan dari Template</h3>
  </div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Butir penjelasan disalin dari template dan dibekukan di sini. Persetujuan lahir dalam
      keadaan <b>belum dikonfirmasi</b> &mdash; keputusan pasien direkam setelah butirnya dijelaskan.
    </div>

    @if ($template->isEmpty())
      <div class="alert alert-warning mb-0">
        Belum ada template aktif. Buat dulu di <b>Master Persetujuan</b> &mdash; template
        kosong berarti tidak ada butir penjelasan yang bisa dibuktikan sudah disampaikan.
      </div>
    @else
      <form method="POST" action="{{ route('correspondence.persetujuan.dari-template') }}" class="row g-2">
        @csrf
        <div class="col-12 col-md-6">
          <label class="form-label">Template</label>
          <select name="template_id" class="form-select" required>
            @foreach ($template as $t)
              <option value="{{ $t->id }}">{{ $t->name }} (v{{ $t->version }})</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-6"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
        <div class="col-6 col-md-4"><label class="form-label">ID Kunjungan (opsional)</label><input type="number" name="registration_id" class="form-control"></div>
        <div class="col-6 col-md-8"><label class="form-label">Dokter yang Menjelaskan</label><input type="text" name="explained_by_name" class="form-control" placeholder="Nama dokter pemberi penjelasan"></div>
        <div class="col-12"><label class="form-label">Uraian Tindakan</label><textarea name="procedure_description" class="form-control" rows="2" required></textarea></div>

        <div class="col-12"><hr class="my-1"><div class="form-label mb-0">Penanda Tangan</div></div>
        <div class="col-12 col-md-4"><label class="form-label">Nama</label><input type="text" name="signer_name" class="form-control"></div>
        <div class="col-6 col-md-4">
          <label class="form-label">Hubungan dengan Pasien</label>
          <select name="signer_relationship" class="form-select">
            <option value="">&mdash;</option>
            @foreach ($hubungan as $h)
              <option value="{{ $h }}">{{ $labelHubungan($h) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-4"><label class="form-label">NIK/KTP</label><input type="text" name="signer_id_number" class="form-control"></div>
        <div class="col-12">
          <label class="form-label">Alasan Perwakilan</label>
          <input type="text" name="delegation_reason" class="form-control" placeholder="Wajib bila bukan pasien sendiri, mis. pasien tidak sadar">
          <div class="form-hint">Tanpa alasan ini, tidak ada yang bisa menilai belakangan apakah perwakilannya sah.</div>
        </div>

        <div class="col-12"><button class="btn btn-primary">Buat Persetujuan</button></div>
      </form>
    @endif
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Catat Persetujuan Ringkas</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Untuk jenis yang memang tidak berbentuk penjelasan tindakan &mdash; persetujuan umum saat
      masuk, pemeriksaan HIV, penundaan pelayanan. Catatan terstruktur, bukan tanda tangan
      elektronik; dicetak dan ditandatangani di atas kertas.
    </div>
    <form method="POST" action="{{ route('correspondence.persetujuan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3">
        <label class="form-label">Jenis</label>
        <select name="consent_type" class="form-select" required>
          <option value="umum">Persetujuan Umum</option>
          <option value="tindakan">Persetujuan Tindakan</option>
          <option value="penolakan-anjuran-medis">Penolakan Anjuran Medis</option>
          <option value="resusitasi">Penolakan Resusitasi (DNR)</option>
          <option value="rawat-inap">Persetujuan Rawat Inap</option>
          <option value="penundaan-pelayanan">Persetujuan Penundaan Pelayanan</option>
          <option value="pemeriksaan-hiv">Persetujuan Pemeriksaan HIV</option>
          <option value="pulang-permintaan-sendiri">Pulang Atas Permintaan Sendiri (APS)</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Keputusan</label>
        <select name="decision" class="form-select" required>
          <option value="setuju">Setuju</option>
          <option value="menolak">Menolak</option>
        </select>
      </div>
      <div class="col-12 col-md-6"><label class="form-label">Nama Pasien</label><input type="text" name="patient_name" class="form-control" required></div>
      <div class="col-6"><label class="form-label">ID Kunjungan (opsional)</label><input type="number" name="registration_id" class="form-control"></div>
      <div class="col-6"><label class="form-label">Nama Saksi</label><input type="text" name="witness_name" class="form-control"></div>

      <div class="col-12 col-md-4"><label class="form-label">Penanda Tangan</label><input type="text" name="signer_name" class="form-control"></div>
      <div class="col-6 col-md-4">
        <label class="form-label">Hubungan</label>
        <select name="signer_relationship" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($hubungan as $h)
            <option value="{{ $h }}">{{ $labelHubungan($h) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-4"><label class="form-label">Alasan Perwakilan</label><input type="text" name="delegation_reason" class="form-control"></div>

      <div class="col-12 col-md-4">
        <label class="form-label">Alasan Penolakan Anjuran</label>
        <select name="refusal_reason_id" class="form-select">
          <option value="">&mdash;</option>
          @foreach ($alasan as $a)
            <option value="{{ $a->id }}">{{ $a->code }} &middot; {{ $a->name }}</option>
          @endforeach
        </select>
        @if ($alasan->isEmpty())
          <div class="form-hint">Daftarnya sengaja kosong sampai RSP UI menetapkannya sendiri.</div>
        @endif
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label">Akibat Penolakan yang Dijelaskan</label>
        <input type="text" name="refusal_risk_explained" class="form-control" placeholder="Wajib untuk penolakan anjuran medis">
      </div>

      <div class="col-12"><label class="form-label">Uraian Tindakan/Keputusan</label><textarea name="procedure_description" class="form-control" rows="3" required></textarea></div>
      <div class="col-12"><button class="btn btn-primary">Simpan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Jenis</th><th>Keputusan</th><th>Penjelasan</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($persetujuan as $p)
          @php
            $belum = $p->butirBelumDijelaskan();
            $warnaKeputusan = match ($p->decision) {
              'setuju' => 'green',
              'menolak' => 'red',
              default => 'yellow',
            };
          @endphp
          <tr>
            <td class="font-monospace small">{{ $p->consent_number }}</td>
            <td>
              {{ $p->patient_name }}
              @if ($p->signer_relationship && $p->signer_relationship !== 'diri-sendiri')
                <div class="small text-secondary">diwakili {{ $labelHubungan($p->signer_relationship) }}</div>
              @endif
            </td>
            <td><span class="badge bg-blue-lt">{{ $p->consent_type }}</span></td>
            <td><span class="badge bg-{{ $warnaKeputusan }}-lt">{{ $p->decision }}</span></td>
            <td>
              @if ($p->items->isEmpty())
                <span class="text-secondary small">&mdash;</span>
              @else
                <span class="small">{{ $p->items->count() - count($belum) }}/{{ $p->items->count() }} butir</span>
                @if ($belum !== [])
                  <div class="small text-secondary">belum: {{ implode(', ', $belum) }}</div>
                @endif
              @endif
            </td>
            <td>
              @if ($p->status === 'aktif')
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-secondary-lt">Dibatalkan</span>
              @endif
            </td>
            <td>
              <div class="btn-group">
                <a href="{{ route('correspondence.persetujuan.cetak', $p) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Cetak</a>
                @if ($p->status === 'aktif')
                  <form method="POST" action="{{ route('correspondence.persetujuan.batal', $p) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>

          @if ($p->items->isNotEmpty() && $p->status === 'aktif' && $p->decision === 'belum-dikonfirmasi')
            <tr>
              <td colspan="7" class="bg-light">
                <div class="small text-secondary mb-2">
                  Butir penjelasan &mdash; tandai satu per satu. <b>Belum dijelaskan</b> berbeda dari
                  <b>belum dipahami</b>: yang pertama berarti pertanyaannya belum diajukan.
                </div>
                @foreach ($p->items as $butir)
                  <form method="POST" action="{{ route('correspondence.persetujuan.butir.konfirmasi', $butir) }}" class="row g-1 align-items-end mb-1">
                    @csrf
                    <div class="col-12 col-md-4">
                      <b class="small">{{ $butir->label }}</b>
                      <div class="small text-secondary">{{ $butir->body }}</div>
                    </div>
                    <div class="col-4 col-md-2">
                      <select name="confirmed" class="form-select form-select-sm">
                        <option value="belum" @selected($butir->confirmed === null)>Belum dijelaskan</option>
                        <option value="ya" @selected($butir->confirmed === true)>Dijelaskan &amp; paham</option>
                        <option value="tidak" @selected($butir->confirmed === false)>Dijelaskan, belum paham</option>
                      </select>
                    </div>
                    <div class="col-6 col-md-5">
                      <input type="text" name="confirmation_note" class="form-control form-control-sm"
                             value="{{ $butir->confirmation_note }}" placeholder="Keterangan (wajib bila belum paham)">
                    </div>
                    <div class="col-2 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">Simpan</button></div>
                  </form>
                @endforeach

                <form method="POST" action="{{ route('correspondence.persetujuan.putuskan', $p) }}" class="row g-1 align-items-end mt-2">
                  @csrf
                  <div class="col-6 col-md-2">
                    <label class="form-label small">Keputusan</label>
                    <select name="decision" class="form-select form-select-sm">
                      <option value="setuju">Setuju</option>
                      <option value="menolak">Menolak</option>
                    </select>
                  </div>
                  <div class="col-6 col-md-3"><label class="form-label small">Penanda Tangan</label><input type="text" name="signer_name" class="form-control form-control-sm" value="{{ $p->signer_name }}"></div>
                  <div class="col-6 col-md-3"><label class="form-label small">Saksi Keluarga</label><input type="text" name="witness_name" class="form-control form-control-sm" value="{{ $p->witness_name }}"></div>
                  <div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100">Rekam Keputusan</button></div>
                </form>
              </td>
            </tr>
          @endif
        @empty
          <tr><td colspan="7" class="text-center text-secondary py-3">Belum ada persetujuan tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
