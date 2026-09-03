@extends('layouts.app')

@section('title', 'Permintaan Layanan Program KFR')
@section('breadcrumb', 'Konteks encounter')
@section('heading', 'Permintaan Layanan Program KFR')

@section('actions')
  <a href="{{ route('registrasi.index') }}" class="btn btn-link">&larr; Papan Antrean</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Ajukan Permintaan</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('program-kfr.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Kunjungan Hari Ini</label>
        <select name="registration_id" class="form-select" required>
          <option value="">— pilih pasien —</option>
          @foreach ($kunjunganHariIni as $k)
            <option value="{{ $k->id }}">{{ $k->patient_name }} ({{ $k->patient_mrn }}) — {{ $k->registration_number }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label">Program KFR</label>
        <input type="text" name="program_name" class="form-control" placeholder="mis. Fisioterapi Pasca-Stroke, Terapi Wicara" required>
      </div>
      <div class="col-12">
        <label class="form-label">Alasan Permintaan</label>
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </div>
      <div class="col-12"><button class="btn btn-primary">Ajukan Permintaan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Riwayat</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No.</th><th>Pasien</th><th>Program</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($permintaan as $p)
          <tr>
            <td class="font-monospace small">{{ $p->request_number }}</td>
            <td>{{ $p->patient_name }}</td>
            <td>{{ $p->program_name }}</td>
            <td>
              @if ($p->status === 'diminta')
                <span class="badge bg-green-lt">Diminta</span>
              @else
                <span class="badge bg-secondary-lt">Dibatalkan</span>
              @endif
            </td>
            <td>
              @if ($p->status === 'diminta')
                <form method="POST" action="{{ route('program-kfr.batal', $p) }}">
                  @csrf
                  <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada permintaan program KFR tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
