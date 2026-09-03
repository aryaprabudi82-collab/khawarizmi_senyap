@extends('layouts.app')

@section('title', 'Mutu — Insiden K3')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'Insiden Keselamatan & Kesehatan Kerja (K3)')

@section('actions')
  <a href="{{ route('quality.ppi.index') }}" class="btn btn-link">&larr; Audit PPI</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Laporkan Insiden K3</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('quality.k3.simpan') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-4">
        <label class="form-label">Pegawai Terdampak (opsional)</label>
        <select name="employee_id" class="form-select">
          <option value="">— bukan pegawai tercatat —</option>
          @foreach ($pegawai as $p)
            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->employee_number }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3"><label class="form-label">Waktu Kejadian</label><input type="datetime-local" name="occurred_at" class="form-control" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
      <div class="col-6 col-md-5"><label class="form-label">Lokasi</label><input type="text" name="location" class="form-control" required></div>

      <div class="col-6 col-md-3"><label class="form-label">Bagian Tubuh</label><input type="text" name="body_part" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Dampak Cidera</label><input type="text" name="injury_impact" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Jenis Cidera</label><input type="text" name="injury_type" class="form-control" required></div>
      <div class="col-6 col-md-3"><label class="form-label">Jenis Pekerjaan</label><input type="text" name="job_type" class="form-control"></div>
      <div class="col-12"><label class="form-label">Penyebab</label><input type="text" name="cause" class="form-control" required></div>
      <div class="col-12"><label class="form-label">Kronologi</label><textarea name="description" class="form-control" rows="2" required></textarea></div>
      <div class="col-12"><button class="btn btn-primary">Laporkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Insiden Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Insiden</th><th>Waktu</th><th>Lokasi</th><th>Cidera</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($insiden as $i)
          <tr>
            <td class="font-monospace small">{{ $i->incident_number }}</td>
            <td class="text-secondary small">{{ $i->occurred_at->format('d-m-Y H:i') }}</td>
            <td>{{ $i->location }}</td>
            <td class="text-secondary small">{{ $i->body_part }} — {{ $i->injury_type }}</td>
            <td>
              @php $warna = ['dilaporkan' => 'yellow', 'ditinjau' => 'blue', 'ditutup' => 'green'][$i->status]; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $i->status }}</span>
            </td>
            <td>
              @if ($i->status === 'dilaporkan')
                <form method="POST" action="{{ route('quality.k3.tinjau', $i) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-primary">Tinjau</button>
                </form>
              @elseif ($i->status === 'ditinjau')
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#tutup-{{ $i->id }}">Tutup</button>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada insiden K3 tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($insiden as $i)
  @if ($i->status === 'ditinjau')
    <div class="modal fade" id="tutup-{{ $i->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('quality.k3.tutup', $i) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Tutup Insiden {{ $i->incident_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Tindakan Korektif</label>
            <textarea name="corrective_action" class="form-control" required></textarea>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-success">Tutup</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
