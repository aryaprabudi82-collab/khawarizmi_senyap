@extends('layouts.app')

@section('title', 'Kepegawaian — Presensi Bulanan')
@section('breadcrumb', 'Konteks hr')
@section('heading', 'Rekap Presensi Bulanan')

@section('actions')
  <a href="{{ route('hr.presensi.index') }}" class="btn btn-link">&larr; Presensi Harian</a>
@endsection

@section('content')

<div class="card">
  <div class="card-header">
    <h3 class="card-title">{{ $bulan->translatedFormat('F Y') }}</h3>
    <div class="card-actions">
      <form method="GET" action="{{ route('hr.presensi.bulanan') }}" class="d-flex gap-2">
        <input type="month" name="bulan" class="form-control form-control-sm" value="{{ $bulan->format('Y-m') }}" onchange="this.form.submit()">
      </form>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead>
        <tr>
          <th>Pegawai</th>
          <th class="text-end">Hadir</th>
          <th class="text-end">Izin</th>
          <th class="text-end">Sakit</th>
          <th class="text-end">Alpha</th>
          <th class="text-end">Cuti</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rekap as $baris)
          <tr>
            <td>{{ $baris['pegawai']->name }}</td>
            <td class="text-end">{{ $baris['hadir'] }}</td>
            <td class="text-end">{{ $baris['izin'] }}</td>
            <td class="text-end">{{ $baris['sakit'] }}</td>
            <td class="text-end {{ $baris['alpha'] > 0 ? 'text-danger fw-bold' : '' }}">{{ $baris['alpha'] }}</td>
            <td class="text-end">{{ $baris['cuti'] }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada pegawai aktif.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
