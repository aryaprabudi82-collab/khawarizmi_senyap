@extends('layouts.app')

@section('title', 'Pendaftaran Rawat Jalan')
@section('breadcrumb', 'Modul A &middot; Registrasi dan Pelayanan')
@section('heading', 'Papan Antrean ' . $tanggal->translatedFormat('l, d F Y'))

@section('actions')
  @can('registrasi')
    <a href="{{ route('registrasi.create', ['tanggal' => $tanggal->toDateString()]) }}" class="btn btn-primary">
      Daftarkan Pasien
    </a>
  @endcan
@endsection

@section('content')

  <div class="row row-deck row-cards mb-3">
    @php
      $kartu = [
        'terdaftar' => ['Menunggu', 'bg-blue-lt'],
        'dilayani'  => ['Sedang dilayani', 'bg-yellow-lt'],
        'selesai'   => ['Selesai', 'bg-green-lt'],
        'batal'     => ['Batal', 'bg-red-lt'],
      ];
    @endphp
    @foreach ($kartu as $status => [$label, $warna])
      <div class="col-6 col-sm-3">
        <div class="card {{ $warna }}">
          <div class="card-body py-3">
            <div class="text-secondary small">{{ $label }}</div>
            <div class="h1 mb-0">{{ $ringkasan[$status] ?? 0 }}</div>
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <div class="card">
    <div class="card-body border-bottom py-3">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label" for="tanggal">Tanggal pelayanan</label>
          <input type="date" id="tanggal" name="tanggal" class="form-control"
                 value="{{ $tanggal->toDateString() }}">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="unit_id">Unit layanan</label>
          <select id="unit_id" name="unit_id" class="form-select">
            <option value="">Semua unit</option>
            @foreach ($units as $unit)
              <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label" for="status">Status</label>
          <select id="status" name="status" class="form-select">
            <option value="">Semua status</option>
            @foreach (['terdaftar','dipanggil','dilayani','selesai','batal','tidak-hadir'] as $s)
              <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('-', ' ', $s)) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-2">
          <button type="submit" class="btn btn-outline-primary w-100">Tampilkan</button>
        </div>
      </form>
    </div>

    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th style="width:70px" class="text-center">Antrean</th>
            <th>No. Registrasi</th>
            <th>Pasien</th>
            <th>Unit</th>
            <th>Dokter</th>
            <th>Penjamin</th>
            <th class="text-end">Biaya</th>
            <th>Jam</th>
            <th>Status</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          @forelse ($antrean as $baris)
            <tr class="{{ $baris->status === 'batal' ? 'opacity-50' : '' }}">
              <td class="text-center queue-number">{{ $baris->queue_number }}</td>
              <td><span class="text-muted font-monospace small">{{ $baris->registration_number }}</span></td>
              <td>
                <div class="fw-semibold">{{ $baris->patient_name }}</div>
                <div class="text-secondary small font-monospace">{{ $baris->patient_mrn }}</div>
              </td>
              <td>{{ $baris->unit_name }}</td>
              <td>{{ $baris->practitioner_name ?? '—' }}</td>
              <td>
                {{ $baris->payer_name }}
                <span class="badge bg-secondary-lt ms-1">{{ $baris->visit_type }}</span>
              </td>
              <td class="text-end">
                {{ $baris->registration_fee > 0
                    ? 'Rp ' . number_format((float) $baris->registration_fee, 0, ',', '.')
                    : '—' }}
              </td>
              <td class="text-secondary">{{ $baris->registered_at->format('H:i') }}</td>
              <td>
                @php
                  $rona = match ($baris->status) {
                    'terdaftar' => 'blue', 'dipanggil' => 'azure', 'dilayani' => 'yellow',
                    'selesai' => 'green', 'batal' => 'red', default => 'secondary',
                  };
                @endphp
                <span class="badge bg-{{ $rona }}-lt">{{ str_replace('-', ' ', $baris->status) }}</span>
              </td>
              <td>
                @can($baris->care_type === 'ranap' ? 'barcoderanap' : 'barcoderalan')
                  <a href="{{ route('registrasi.barcode', $baris->id) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Barcode</a>
                @endcan
                @can('pembayaran_ralan')
                  <form method="POST" action="{{ route('tagihan.buka', $baris->id) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary">Tagihan</button>
                  </form>
                @endcan
                @if (! $baris->isCancelled() && $baris->status !== 'selesai')
                  @can('registrasi')
                    <button class="btn btn-sm btn-ghost-danger" data-bs-toggle="modal"
                            data-bs-target="#batal-{{ $baris->id }}">Batalkan</button>
                  @endcan
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="10" class="text-center text-secondary py-4">
                Belum ada pendaftaran pada tanggal ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if ($antrean->hasPages())
      <div class="card-footer">{{ $antrean->links() }}</div>
    @endif
  </div>

  @foreach ($antrean as $baris)
    @if (! $baris->isCancelled() && $baris->status !== 'selesai')
      <div class="modal fade" id="batal-{{ $baris->id }}" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
          <form class="modal-content" method="POST" action="{{ route('registrasi.batal', $baris) }}">
            @csrf
            <div class="modal-header">
              <h5 class="modal-title">Batalkan registrasi</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <p class="text-secondary small">
                {{ $baris->patient_name }} &middot; antrean {{ $baris->queue_number }} di {{ $baris->unit_name }}.
              </p>
              <label class="form-label" for="alasan-{{ $baris->id }}">Alasan pembatalan</label>
              <textarea id="alasan-{{ $baris->id }}" name="alasan" class="form-control" rows="3"
                        required minlength="5" placeholder="Mis. pasien pulang sebelum dilayani"></textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
              <button type="submit" class="btn btn-danger">Batalkan</button>
            </div>
          </form>
        </div>
      </div>
    @endif
  @endforeach

@endsection
