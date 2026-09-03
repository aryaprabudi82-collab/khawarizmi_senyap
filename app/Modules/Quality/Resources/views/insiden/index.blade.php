@extends('layouts.app')

@section('title', 'Mutu — Insiden Keselamatan Pasien')
@section('breadcrumb', 'Konteks quality')
@section('heading', 'Insiden Keselamatan Pasien (IKP)')

@section('actions')
  <a href="{{ route('quality.icra.index') }}" class="btn btn-link">ICRA &rarr;</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Laporkan Insiden</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('quality.insiden.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis</label>
        <select name="incident_type" class="form-select" required>
          <option value="kpc">KPC — Kondisi Potensial Cedera</option>
          <option value="knc">KNC — Nyaris Cedera</option>
          <option value="ktc">KTC — Tidak Cedera</option>
          <option value="ktd">KTD — Tidak Diharapkan</option>
          <option value="sentinel">Sentinel</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Dampak</label>
        <select name="severity_band" class="form-select" required>
          <option value="biru">Biru</option>
          <option value="hijau">Hijau</option>
          <option value="kuning">Kuning</option>
          <option value="merah">Merah</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Waktu Kejadian</label>
        <input type="datetime-local" name="occurred_at" class="form-control" value="{{ now()->format('Y-m-d\TH:i') }}" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Unit</label>
        <select name="unit_id" class="form-select">
          <option value="">— unit —</option>
          @foreach ($unit as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Lokasi Rinci</label>
        <input type="text" name="location_detail" class="form-control" placeholder="mis. Kamar 3, dekat pintu">
      </div>
      <div class="col-12">
        <label class="form-label">Kronologi</label>
        <textarea name="description" class="form-control" rows="2" required></textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Tindakan Segera</label>
        <textarea name="immediate_action" class="form-control" rows="2"></textarea>
      </div>
      <div class="col-12"><button class="btn btn-primary">Laporkan</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Laporan Terbaru</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>No. Laporan</th><th>Jenis</th><th>Dampak</th><th>Waktu</th><th>Status</th><th class="w-1"></th></tr></thead>
      <tbody>
        @forelse ($insiden as $i)
          <tr>
            <td class="font-monospace small">{{ $i->report_number }}</td>
            <td class="text-uppercase">{{ $i->incident_type }}</td>
            <td>
              @php $warnaDampak = ['biru' => 'blue', 'hijau' => 'green', 'kuning' => 'yellow', 'merah' => 'red'][$i->severity_band]; @endphp
              <span class="badge bg-{{ $warnaDampak }}-lt text-uppercase">{{ $i->severity_band }}</span>
            </td>
            <td class="text-secondary small">{{ $i->occurred_at->format('d-m-Y H:i') }}</td>
            <td>
              @php $warnaStatus = ['dilaporkan' => 'yellow', 'ditinjau' => 'blue', 'ditutup' => 'green'][$i->status]; @endphp
              <span class="badge bg-{{ $warnaStatus }}-lt">{{ $i->status }}</span>
            </td>
            <td>
              @if ($i->status === 'dilaporkan')
                <form method="POST" action="{{ route('quality.insiden.tinjau', $i) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-primary">Tinjau</button>
                </form>
              @elseif ($i->status === 'ditinjau')
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#tutup-{{ $i->id }}">Tutup</button>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada insiden tercatat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@foreach ($insiden as $i)
  @if ($i->status === 'ditinjau')
    <div class="modal fade" id="tutup-{{ $i->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('quality.insiden.tutup', $i) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Tutup Insiden {{ $i->report_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label class="form-label">Akar Masalah</label><textarea name="root_cause" class="form-control" required></textarea></div>
            <div class="mb-2"><label class="form-label">Tindakan Korektif</label><textarea name="corrective_action" class="form-control" required></textarea></div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-success">Tutup</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
