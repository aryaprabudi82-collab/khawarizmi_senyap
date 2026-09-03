@extends('layouts.app')

@section('title', 'Rawat Inap — Kamar & Bed')
@section('breadcrumb', 'Konteks inpatient')
@section('heading', 'Kelola Kamar & Bed')

@section('actions')
  <a href="{{ route('inpatient.index') }}" class="btn btn-link">&larr; Admisi</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Tambah Kamar</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('inpatient.kamar.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-2"><input type="text" name="room_number" class="form-control form-control-sm" placeholder="Nomor kamar" required></div>
      <div class="col-6 col-md-2">
        <select name="room_class" class="form-select form-select-sm" required>
          @foreach (\App\Modules\Inpatient\Models\Room::CLASSES as $c)
            <option value="{{ $c }}">{{ strtoupper($c) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-3">
        <select name="unit_id" class="form-select form-select-sm">
          <option value="">— unit/bangsal —</option>
          @foreach ($unit as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2"><input type="number" name="daily_rate" class="form-control form-control-sm" placeholder="Tarif/hari" min="0" step="1000"></div>
      <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Tambah Kamar</button></div>
    </form>
  </div>
</div>

<div class="row g-3">
  @forelse ($kamar as $k)
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">{{ $k->room_number }} <span class="badge bg-blue-lt text-uppercase ms-1">{{ $k->room_class }}</span></h3>
          <div class="card-actions text-secondary small">{{ $k->unit_name ?? '—' }}</div>
        </div>
        <div class="card-body">
          <div class="text-secondary small mb-2">Tarif/hari: Rp {{ number_format($k->daily_rate, 0, ',', '.') }}</div>
          <div class="list-group list-group-flush">
            @forelse ($k->beds as $bed)
              <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                <div>
                  <span class="font-monospace">{{ $bed->bed_number }}</span>
                  @php $warna = ['tersedia' => 'green', 'terisi' => 'red', 'dibersihkan' => 'yellow', 'tidak-aktif' => 'secondary'][$bed->status]; @endphp
                  <span class="badge bg-{{ $warna }}-lt ms-1">{{ $bed->status }}</span>
                </div>
                <div class="btn-group">
                  @if ($bed->status === 'dibersihkan')
                    <form method="POST" action="{{ route('inpatient.kamar.bed.bersih', $bed) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-success">Sudah Bersih</button>
                    </form>
                  @endif
                  @if (in_array($bed->status, ['tersedia', 'dibersihkan']))
                    <form method="POST" action="{{ route('inpatient.kamar.bed.nonaktifkan', $bed) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-secondary">Nonaktifkan</button>
                    </form>
                  @endif
                  @if ($bed->status === 'tidak-aktif')
                    <form method="POST" action="{{ route('inpatient.kamar.bed.aktifkan', $bed) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-success">Aktifkan</button>
                    </form>
                  @endif
                </div>
              </div>
            @empty
              <div class="text-secondary small py-2">Belum ada bed.</div>
            @endforelse
          </div>
        </div>
        <div class="card-footer">
          <form method="POST" action="{{ route('inpatient.kamar.bed.simpan', $k) }}" class="d-flex gap-2">
            @csrf
            <input type="text" name="bed_number" class="form-control form-control-sm" placeholder="Nomor bed baru" required>
            <button class="btn btn-sm btn-outline-primary">+</button>
          </form>
        </div>
      </div>
    </div>
  @empty
    <div class="col-12"><div class="text-center text-secondary py-5">Belum ada kamar terdaftar.</div></div>
  @endforelse
</div>

@endsection
