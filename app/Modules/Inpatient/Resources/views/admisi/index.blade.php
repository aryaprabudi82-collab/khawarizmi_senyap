@extends('layouts.app')

@section('title', 'Rawat Inap — Admisi')
@section('breadcrumb', 'Konteks inpatient')
@section('heading', 'Admisi Rawat Inap')

@section('actions')
  @can('tindakan_ranap')
    <a href="{{ route('inpatient.kamar.index') }}" class="btn btn-link">Kelola Kamar &amp; Bed &rarr;</a>
  @endcan
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Menunggu Kamar</h3>
        <div class="card-actions text-secondary small">{{ $menunggu->count() }} registrasi</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Registrasi</th><th>Pasien</th><th>Dokter</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($menunggu as $r)
              <tr>
                <td class="font-monospace small">{{ $r->registration_number }}</td>
                <td>{{ $r->patient_name }}<div class="text-secondary small font-monospace">{{ $r->patient_mrn }}</div></td>
                <td class="text-secondary">{{ $r->practitioner_name ?? '—' }}</td>
                <td>
                  @can('tindakan_ranap')
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#admisi-{{ $r->id }}">Admisi</button>
                  @endcan
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada registrasi rawat inap yang menunggu kamar.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">
        <h3 class="card-title">Sedang Dirawat</h3>
        <div class="card-actions text-secondary small">{{ $dirawat->count() }} pasien</div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Admisi</th><th>Pasien</th><th>Kamar/Bed</th><th>Lama Rawat</th>@can('diet_pasien')<th>Diet</th>@endcan<th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($dirawat as $a)
              <tr>
                <td class="font-monospace small">{{ $a->admission_number }}</td>
                <td>
                  {{ $a->patient_name }}
                  <div class="text-secondary small">
                    {{ $a->dpjp_name ?? '— belum ada DPJP —' }}
                    @can('tindakan_ranap')
                      <button class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="modal" data-bs-target="#dpjp-{{ $a->id }}">Ganti</button>
                    @endcan
                  </div>
                </td>
                <td>
                  {{ $a->bed->room->room_number }} / {{ $a->bed->bed_number }}
                  <div class="text-secondary small text-uppercase">{{ $a->bed->room->room_class }}</div>
                  @can('tindakan_ranap')
                    <button class="btn btn-sm btn-link p-0" data-bs-toggle="modal" data-bs-target="#pindah-{{ $a->id }}">Pindah</button>
                  @endcan
                </td>
                <td>{{ $a->lengthOfStayDays() }} hari</td>
                @can('diet_pasien')
                  <td>
                    @if ($a->activeDietOrder)
                      <span class="badge bg-blue-lt text-uppercase">{{ $a->activeDietOrder->diet_type }}</span>
                    @else
                      <span class="text-secondary small">— belum ada —</span>
                    @endif
                    <button class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="modal" data-bs-target="#diet-{{ $a->id }}">Atur</button>
                  </td>
                @endcan
                <td>
                  @can('tindakan_ranap')
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#pulang-{{ $a->id }}">Pulangkan</button>
                  @endcan
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Tidak ada pasien yang sedang dirawat.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@can('tindakan_ranap')
@foreach ($menunggu as $r)
  <div class="modal fade" id="admisi-{{ $r->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inpatient.admisi.simpan') }}">
        @csrf
        <input type="hidden" name="registration_id" value="{{ $r->id }}">
        <div class="modal-header"><h5 class="modal-title">Admisi {{ $r->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Pilih Bed Tersedia</label>
          <select name="bed_id" class="form-select" required>
            <option value="">— pilih bed —</option>
            @foreach ($bedTersedia as $bed)
              <option value="{{ $bed->id }}">{{ $bed->room->room_number }} / {{ $bed->bed_number }} — {{ strtoupper($bed->room->room_class) }}</option>
            @endforeach
          </select>
          @if ($bedTersedia->isEmpty())
            <div class="form-hint text-danger">Tidak ada bed tersedia saat ini.</div>
          @endif
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary" @disabled($bedTersedia->isEmpty())>Admisi</button></div>
      </form>
    </div>
  </div>
@endforeach
@endcan

@can('diet_pasien')
  @foreach ($dirawat as $a)
    <div class="modal fade" id="diet-{{ $a->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Order Diet — {{ $a->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            @if ($a->dietOrders->isNotEmpty())
              <div class="mb-3">
                <div class="fw-bold small text-secondary mb-1">Riwayat</div>
                <ul class="list-unstyled small mb-0">
                  @foreach ($a->dietOrders as $d)
                    <li class="mb-1">
                      <span class="badge bg-{{ $d->status === 'aktif' ? 'blue' : 'secondary' }}-lt text-uppercase">{{ $d->diet_type }}</span>
                      {{ $d->start_date->format('d M') }}{{ $d->end_date ? ' – ' . $d->end_date->format('d M') : ' – sekarang' }}
                      @if ($d->status === 'aktif')
                        <form method="POST" action="{{ route('inpatient.admisi.diet.hentikan', [$a, $d]) }}" class="d-inline">
                          @csrf
                          <button class="btn btn-sm btn-link text-danger p-0">Hentikan</button>
                        </form>
                      @endif
                    </li>
                  @endforeach
                </ul>
              </div>
              <hr>
            @endif
            <form method="POST" action="{{ route('inpatient.admisi.diet.simpan', $a) }}" class="row g-2">
              @csrf
              <div class="col-6">
                <select name="diet_type" class="form-select form-select-sm" required>
                  @foreach (\App\Modules\Inpatient\Models\DietOrder::TYPES as $t)
                    <option value="{{ $t }}">{{ strtoupper($t) }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-6"><input type="date" name="start_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
              <div class="col-12"><input type="text" name="note" class="form-control form-control-sm" placeholder="Tekstur/pantangan/alergi (opsional)"></div>
              <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Catat Order Diet</button></div>
            </form>
          </div>
        </div>
      </div>
    </div>
  @endforeach
@endcan

@can('tindakan_ranap')
@foreach ($dirawat as $a)
  @can('tindakan_ranap')
    <div class="modal fade" id="pindah-{{ $a->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('inpatient.admisi.pindah-bed', $a) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Pindah Bed &mdash; {{ $a->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="form-hint mb-2">
              Sekarang di {{ $a->bed->room->room_number }} / {{ $a->bed->bed_number }} ({{ $a->bed->room->room_class }}).
              Hari-hari sebelum pindah tetap ditagih dengan tarif kamar lama.
            </div>
            <label class="form-label">Bed Tujuan</label>
            <select name="bed_id" class="form-select mb-2" required>
              <option value="">&mdash; pilih bed tersedia &mdash;</option>
              @foreach ($bedTersedia as $b)
                <option value="{{ $b->id }}">{{ $b->room->room_number }} / {{ $b->bed_number }} &mdash; {{ $b->room->room_class }} (Rp {{ number_format((float) $b->room->daily_rate, 0, ',', '.') }}/hari)</option>
              @endforeach
            </select>
            <label class="form-label">Alasan (opsional)</label>
            <input type="text" name="reason" class="form-control" placeholder="mis. naik kelas, butuh isolasi">
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Pindahkan</button></div>
        </form>
      </div>
    </div>
  @endcan
  <div class="modal fade" id="dpjp-{{ $a->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inpatient.admisi.dpjp.simpan', $a) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Ganti DPJP — {{ $a->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="form-hint mb-2">DPJP saat ini: {{ $a->dpjp_name ?? '— belum ada —' }}</div>
          <label class="form-label">DPJP Baru</label>
          <select name="practitioner_id" class="form-select mb-2" required>
            <option value="">— pilih dokter —</option>
            @foreach ($praktisi as $p)
              <option value="{{ $p->id }}">{{ $p->title ? $p->title . ' ' : '' }}{{ $p->name }} — {{ $p->specialty }}</option>
            @endforeach
          </select>
          <label class="form-label">Alasan (opsional)</label>
          <input type="text" name="reason" class="form-control" placeholder="mis. alih rawat, konsul spesialis">
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
@endforeach
@endcan

@can('tindakan_ranap')
@foreach ($dirawat as $a)
  <div class="modal fade" id="pulang-{{ $a->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="POST" action="{{ route('inpatient.admisi.pulang', $a) }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Pulangkan {{ $a->patient_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Status Pulang</label>
          <select name="discharge_status" class="form-select mb-2" required>
            <option value="sembuh">Sembuh</option>
            <option value="rujuk">Dirujuk</option>
            <option value="aps">Atas Permintaan Sendiri (APS)</option>
            <option value="meninggal">Meninggal</option>
            <option value="lain">Lain-lain</option>
          </select>
          <label class="form-label">Catatan (opsional)</label>
          <textarea name="note" class="form-control" rows="2"></textarea>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-danger">Pulangkan</button></div>
      </form>
    </div>
  </div>
@endforeach
@endcan

@endsection
