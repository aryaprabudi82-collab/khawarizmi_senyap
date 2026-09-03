@extends('layouts.app')

@section('title', 'Kepegawaian — SKP')
@section('breadcrumb', 'Konteks hr')
@section('heading', 'Penilaian SKP')

@section('actions')
  <a href="{{ route('hr.index') }}" class="btn btn-link">&larr; Pegawai</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Penilaian</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Periode</th><th>Pegawai</th><th class="text-end">Skor</th><th>Predikat</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($penilaian as $p)
              <tr>
                <td class="font-monospace small">{{ $p->period }}</td>
                <td>{{ $p->employee->name }}</td>
                <td class="text-end">{{ number_format($p->score, 2) }}</td>
                <td>{{ $p->predicate() }}</td>
                <td>
                  @if ($p->status === 'final')
                    <span class="badge bg-green-lt">Final</span>
                  @else
                    <span class="badge bg-secondary-lt">Draf</span>
                  @endif
                </td>
                <td>
                  @if ($p->status !== 'final')
                    <form method="POST" action="{{ route('hr.skp.finalisasi', $p) }}" onsubmit="return confirm('Finalisasi penilaian {{ $p->employee->name }} periode {{ $p->period }}? Tidak bisa diubah lagi setelah ini.')">
                      @csrf
                      <button class="btn btn-sm btn-outline-success">Finalisasi</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada penilaian SKP.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Catat Penilaian</h3></div>
      <div class="card-body">
        <p class="text-secondary small">Skor 0&ndash;100. Predikat dihitung otomatis: &ge;91 Sangat Baik, &ge;76 Baik, &ge;61 Cukup, &ge;51 Kurang, di bawahnya Sangat Kurang. Menyimpan ulang periode yang sama (masih draf) akan menimpa skornya.</p>
        <form method="POST" action="{{ route('hr.skp.simpan') }}">
          @csrf
          <div class="mb-2">
            <label class="form-label">Pegawai</label>
            <select name="employee_id" class="form-select" required>
              @foreach ($pegawai as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Periode</label><input type="text" name="period" class="form-control" placeholder="mis. 2026 atau 2026-S1" required></div>
          <div class="mb-2"><label class="form-label">Skor</label><input type="number" name="score" class="form-control" min="0" max="100" step="0.01" required></div>
          <div class="mb-2"><label class="form-label">Catatan Penilai</label><textarea name="note" class="form-control" rows="3"></textarea></div>
          <button class="btn btn-primary w-100">Simpan</button>
        </form>
      </div>
    </div>
  </div>
</div>

@endsection
