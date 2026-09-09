@extends('layouts.app')

@section('title', 'ZIS — Asesmen ' . $asesmen->assessment_number)
@section('breadcrumb', 'Konteks philanthropy')
@section('heading', 'Asesmen Kelayakan ' . $asesmen->assessment_number)

@section('actions')
  <a href="{{ route('philanthropy.bantuan.index') }}" class="btn btn-link">&larr; Daftar Bantuan</a>
@endsection

@php
  use App\Modules\Philanthropy\Models\AssessmentCriterion;
  use App\Modules\Philanthropy\Models\Disbursement;
@endphp

@section('content')

<div class="row row-cards mb-3">
  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-body">
        <div class="row">
          <div class="col-6 col-md-3"><div class="form-label">Penerima</div><div>{{ $asesmen->recipient->name }}</div><div class="text-secondary">{{ $asesmen->recipient->recipient_number }}</div></div>
          <div class="col-6 col-md-3"><div class="form-label">Tanggal Survei</div><div>{{ $asesmen->assessed_on->format('d/m/Y') }}</div></div>
          <div class="col-6 col-md-3"><div class="form-label">Surveyor</div><div>{{ $asesmen->surveyor_name }}</div></div>
          <div class="col-6 col-md-3">
            <div class="form-label">Golongan Asnaf</div>
            <div>{{ $asesmen->recipient->asnaf?->name ?? '— belum ditetapkan —' }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-body">
        <div class="form-label">Total Bobot</div>
        <div class="h1 mb-1">{{ $totalBobot ?? '—' }}</div>
        <div class="form-hint">
          @if ($totalBobot === null)
            Tidak ada jawaban berbobot. Kosong dan nol adalah dua keadaan berbeda &mdash; asesmen ini memang tidak diskor.
          @else
            <b>Angka ini bukan putusan.</b> Tidak ada ambang yang mengubah total jadi layak/tidak layak: rumus seperti itu belum pernah ditetapkan RSP UI, dan menebaknya berarti menolak keluarga sungguhan dengan angka karangan.
          @endif
        </div>
      </div>
    </div>
  </div>
</div>

@if ($belumDijawab !== [])
  <div class="alert alert-warning">
    <b>{{ count($belumDijawab) }} kategori belum dijawab.</b> Ini <b>tidak</b> memblokir putusan &mdash; survei rumah sering tidak lengkap karena alasan yang sah (penghuni tidak ada, ruangan tidak boleh dilihat), dan menahan bantuan atas nama kerapian formulir bukan tujuan instrumen ini. Yang penting: pemutusnya tahu apa saja yang tidak ditanyakan.
    <div class="mt-1">{{ implode(', ', array_map(fn ($k) => AssessmentCriterion::LABEL_KATEGORI[$k], $belumDijawab)) }}</div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Enam Belas Kategori Survei</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th style="width:22%">Kategori</th><th>Jawaban</th><th class="text-end" style="width:8%">Bobot</th><th style="width:34%">Catatan</th></tr></thead>
      <tbody>
        @foreach (AssessmentCriterion::KATEGORI as $kat)
          @php $j = $jawaban[$kat] ?? null; $pilihan = $kriteria[$kat] ?? collect(); @endphp
          <tr>
            <td>
              {{ AssessmentCriterion::LABEL_KATEGORI[$kat] }}
              @if (in_array($kat, $kategoriKosong, true))
                <div class="text-secondary small">kosakatanya belum disusun</div>
              @endif
            </td>
            @if ($asesmen->sudahDiputuskan())
              <td>{{ $j?->label ?? ($j ? 'ditanyakan, tidak terjawab' : '—') }}</td>
              <td class="text-end">{{ $j?->weight ?? '—' }}</td>
              <td class="text-secondary">{{ $j?->note ?: '—' }}</td>
            @else
              <td colspan="3">
                <form method="POST" action="{{ route('philanthropy.bantuan.asesmen.jawab', $asesmen) }}" class="row g-1">
                  @csrf
                  <input type="hidden" name="category" value="{{ $kat }}">
                  <div class="col-12 col-md-5">
                    <select name="criteria_id" class="form-select form-select-sm">
                      {{-- Kosong berarti "ditanyakan tapi tidak terjawab", bukan
                           "tidak ada" — dua keadaan yang harus terbedakan. --}}
                      <option value="">— ditanyakan, tidak terjawab —</option>
                      @foreach ($pilihan as $p)
                        <option value="{{ $p->id }}" @selected($j?->criteria_id === $p->id)>{{ $p->name }}@if ($p->weight !== null) ({{ $p->weight }})@endif</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-8 col-md-5"><input type="text" name="note" class="form-control form-control-sm" placeholder="catatan" value="{{ $j?->note }}"></div>
                  <div class="col-4 col-md-2"><button class="btn btn-sm w-100">Simpan</button></div>
                </form>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @if ($asesmen->sudahDiputuskan())
    <div class="card-footer text-secondary">Asesmen sudah diputuskan, jawabannya dikunci. Label dan bobot di atas adalah <b>salinan yang dibekukan saat menjawab</b>: kriteria yang besok diubah kalimatnya tidak mengubah bunyi asesmen ini &mdash; putusan atas nasib orang harus tetap terbaca sebagaimana ia diambil. Kalau keadaannya berubah, buka asesmen baru.</div>
  @endif
</div>

@if (! $asesmen->sudahDiputuskan())
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Putusan Kelayakan</h3></div>
    <div class="card-body">
      <form method="POST" action="{{ route('philanthropy.bantuan.asesmen.putuskan', $asesmen) }}" class="row g-2">
        @csrf
        <div class="col-12 col-md-3">
          <label class="form-label">Putusan</label>
          <select name="decision" class="form-select" required>
            <option value="layak">Layak</option>
            <option value="tidak-layak">Tidak layak</option>
          </select>
        </div>
        <div class="col-12 col-md-3"><label class="form-label">Nama Pemutus</label><input type="text" name="decided_by_name" class="form-control" value="{{ old('decided_by_name') }}" required></div>
        <div class="col-12 col-md-3">
          <label class="form-label">Usulan Nominal</label>
          <input type="number" step="0.01" min="0" name="recommended_amount" class="form-control" value="{{ old('recommended_amount') }}">
          <div class="form-hint">Hanya dipakai bila layak.</div>
        </div>
        <div class="col-12"><label class="form-label">Alasan</label><textarea name="decision_reason" class="form-control" rows="2" required>{{ old('decision_reason') }}</textarea></div>
        <div class="col-12"><button class="btn btn-primary">Putuskan</button></div>
      </form>
      <div class="form-hint mt-2"><b>Alasan wajib pada kedua arah.</b> Di layar lain sistem ini penolakan yang dituntut beralasan sementara persetujuan tidak; di sini keduanya sama-sama menentukan nasib orang dan sama-sama memakai uang titipan &mdash; bantuan yang diberikan tanpa alasan tercatat sama sulitnya dipertanggungjawabkan dengan penolakan tanpa alasan.</div>
    </div>
  </div>
@else
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Putusan</h3></div>
    <div class="card-body">
      <p class="mb-1">
        @if ($asesmen->decision === 'layak')<span class="badge bg-green-lt">layak</span>@else<span class="badge bg-red-lt">tidak layak</span>@endif
        oleh <b>{{ $asesmen->decided_by_name }}</b>, {{ $asesmen->decided_at?->format('d/m/Y H:i') }}
        @if ($asesmen->recommended_amount !== null)
          &mdash; usulan nominal {{ number_format((float) $asesmen->recommended_amount, 2, ',', '.') }}
        @endif
      </p>
      <p class="mb-0 text-secondary">{{ $asesmen->decision_reason }}</p>
    </div>
  </div>
@endif

@if ($asesmen->decision === 'layak')
  <div class="card">
    <div class="card-header"><h3 class="card-title">Penyaluran atas Asesmen Ini</h3></div>
    <div class="card-body">
      <form method="POST" action="{{ route('philanthropy.bantuan.asesmen.salur', $asesmen) }}" class="row g-2">
        @csrf
        <div class="col-6 col-md-2"><label class="form-label">Tanggal</label><input type="date" name="disbursed_on" value="{{ now()->toDateString() }}" class="form-control" required></div>
        <div class="col-6 col-md-2">
          <label class="form-label">Sumber Dana</label>
          <select name="fund_source" class="form-select" required>
            @foreach (Disbursement::SUMBER as $s)
              <option value="{{ $s }}">{{ ucfirst($s) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-2"><label class="form-label">Jumlah</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required></div>
        <div class="col-6 col-md-3"><label class="form-label">Peruntukan</label><input type="text" name="purpose" class="form-control" placeholder="mis. biaya obat rawat jalan" required></div>
        <div class="col-12 col-md-3"><label class="form-label">Penyalur</label><input type="text" name="disbursed_by_name" class="form-control"></div>
        <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control"></div>
        <div class="col-12"><button class="btn btn-primary">Salurkan</button></div>
      </form>
      <div class="form-hint mt-2">Penyaluran <b>zakat</b> menuntut golongan asnaf penerima sudah ditetapkan. Yang belum ditetapkan golongannya bukan berarti tidak berhak &mdash; ia berarti belum ada yang menetapkan, dan itu diselesaikan dulu, bukan dilewati.</div>
    </div>

    @if ($asesmen->disbursements->isNotEmpty())
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Nomor</th><th>Tanggal</th><th>Sumber</th><th class="text-end">Jumlah</th><th>Peruntukan</th></tr></thead>
          <tbody>
            @foreach ($asesmen->disbursements as $s)
              <tr>
                <td><code>{{ $s->disbursement_number }}</code></td>
                <td>{{ $s->disbursed_on->format('d/m/Y') }}</td>
                <td>{{ ucfirst($s->fund_source) }}</td>
                <td class="text-end">{{ number_format((float) $s->amount, 2, ',', '.') }}</td>
                <td class="text-secondary">{{ $s->purpose }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
@endif

@endsection
