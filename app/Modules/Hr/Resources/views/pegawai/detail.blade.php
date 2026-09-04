@extends('layouts.app')

@section('title', 'Kepegawaian — ' . $pegawai->name)
@section('breadcrumb', 'Konteks hr')
@section('heading', $pegawai->name)

@section('actions')
  <a href="{{ route('hr.index') }}" class="btn btn-link">&larr; Semua Pegawai</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-body d-flex flex-wrap gap-4">
        <div><div class="text-secondary small">NIP/NIK</div><div class="font-monospace">{{ $pegawai->employee_number }}</div></div>
        <div><div class="text-secondary small">Jabatan Saat Ini</div><div>{{ $pegawai->position }}</div></div>
        <div><div class="text-secondary small">Unit</div><div>{{ $unit->firstWhere('id', $pegawai->unit_id)->name ?? '—' }}</div></div>
        <div><div class="text-secondary small">Status</div><div>{{ ucfirst($pegawai->employment_type) }}</div></div>
        <div><div class="text-secondary small">Tanggal Masuk</div><div>{{ $pegawai->hire_date->format('d M Y') }}</div></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Riwayat Jabatan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sejak</th><th>Sampai</th><th>Jabatan</th><th>No. SK</th></tr></thead>
          <tbody>
            @forelse ($pegawai->positionHistory as $r)
              <tr>
                <td class="text-nowrap">{{ $r->effective_date->format('d M Y') }}</td>
                <td class="text-nowrap">{{ $r->end_date?->format('d M Y') ?? 'Sekarang' }}</td>
                <td>{{ $r->position }}</td>
                <td class="font-monospace small">{{ $r->sk_number ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada riwayat jabatan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.pegawai.jabatan.simpan', $pegawai) }}" class="row g-2">
          @csrf
          <div class="col-6 col-md-4"><input type="text" name="position" class="form-control form-control-sm" placeholder="Jabatan baru" required></div>
          <div class="col-6 col-md-3">
            <select name="unit_id" class="form-select form-select-sm">
              <option value="">— unit —</option>
              @foreach ($unit as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2"><input type="date" name="effective_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6 col-md-2"><input type="text" name="sk_number" class="form-control form-control-sm" placeholder="No. SK"></div>
          <div class="col-12 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Riwayat Gaji</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Sejak</th><th>Gaji Pokok</th><th>No. SK</th></tr></thead>
          <tbody>
            @forelse ($pegawai->salaryHistory as $r)
              <tr>
                <td class="text-nowrap">{{ $r->effective_date->format('d M Y') }}</td>
                <td>Rp {{ number_format($r->base_salary, 0, ',', '.') }}</td>
                <td class="font-monospace small">{{ $r->sk_number ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada riwayat gaji.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.pegawai.gaji.simpan', $pegawai) }}" class="row g-2">
          @csrf
          <div class="col-6 col-md-4"><input type="date" name="effective_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
          <div class="col-6 col-md-4"><input type="number" name="base_salary" class="form-control form-control-sm" placeholder="Gaji pokok" min="1" step="1000" required></div>
          <div class="col-8 col-md-3"><input type="text" name="sk_number" class="form-control form-control-sm" placeholder="No. SK"></div>
          <div class="col-4 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Riwayat Pendidikan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Jenjang</th><th>Institusi</th><th>Jurusan</th><th>Lulus</th></tr></thead>
          <tbody>
            @forelse ($pegawai->educations as $r)
              <tr>
                <td class="text-uppercase">{{ $r->education_level }}</td>
                <td>{{ $r->institution_name }}</td>
                <td class="text-secondary">{{ $r->major ?? '—' }}</td>
                <td>{{ $r->graduation_year }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada riwayat pendidikan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.pegawai.pendidikan.simpan', $pegawai) }}" class="row g-2">
          @csrf
          <div class="col-4 col-md-2">
            <select name="education_level" class="form-select form-select-sm" required>
              @foreach (\App\Modules\Hr\Models\EmployeeEducation::LEVELS as $lvl)
                <option value="{{ $lvl }}">{{ strtoupper($lvl) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-8 col-md-4"><input type="text" name="institution_name" class="form-control form-control-sm" placeholder="Institusi" required></div>
          <div class="col-6 col-md-3"><input type="text" name="major" class="form-control form-control-sm" placeholder="Jurusan"></div>
          <div class="col-4 col-md-2"><input type="number" name="graduation_year" class="form-control form-control-sm" placeholder="Tahun" min="1950" max="{{ now()->year + 1 }}" required></div>
          <div class="col-2 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Catatan Kepegawaian</h3></div>
      <div class="text-secondary small px-3 pt-2">Penghargaan, surat peringatan, kegiatan ilmiah &amp; pelatihan, dan riwayat penelitian.</div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Tanggal</th><th>Jenis</th><th>Judul</th><th>No. Dokumen</th></tr></thead>
          <tbody>
            @forelse ($pegawai->records as $r)
              <tr>
                <td class="text-nowrap">{{ $r->record_date->format('d M Y') }}</td>
                <td>
                  @php
                    $rona = match ($r->record_type) {
                      'penghargaan' => 'green', 'peringatan' => 'red',
                      'kegiatan_ilmiah' => 'azure', 'penelitian' => 'purple', default => 'secondary',
                    };
                  @endphp
                  <span class="badge bg-{{ $rona }}-lt">{{ \App\Modules\Hr\Models\EmployeeRecord::typeLabel($r->record_type) }}</span>
                </td>
                <td>{{ $r->title }}</td>
                <td class="font-monospace small">{{ $r->document_number ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada catatan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.pegawai.catatan.simpan', $pegawai) }}" class="row g-2">
          @csrf
          <div class="col-6 col-md-2">
            <select name="record_type" class="form-select form-select-sm" required>
              @foreach (\App\Modules\Hr\Models\EmployeeRecord::TYPES as $tipe)
                <option value="{{ $tipe }}">{{ \App\Modules\Hr\Models\EmployeeRecord::typeLabel($tipe) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-2"><input type="date" name="record_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
          <div class="col-12 col-md-4"><input type="text" name="title" class="form-control form-control-sm" placeholder="Judul" required></div>
          <div class="col-9 col-md-3"><input type="text" name="document_number" class="form-control form-control-sm" placeholder="No. dokumen/sertifikat"></div>
          <div class="col-3 col-md-1"><button class="btn btn-sm btn-outline-primary w-100">+</button></div>
          <div class="col-12"><input type="text" name="description" class="form-control form-control-sm" placeholder="Keterangan (opsional), mis. penyelenggara/jurnal"></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Berkas Kepegawaian</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Jenis</th><th>No. Dokumen</th><th>Berlaku Sampai</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($pegawai->documents as $d)
              <tr>
                <td>{{ $d->document_type_name }}</td>
                <td class="font-monospace small">{{ $d->document_number ?? '—' }}</td>
                <td>
                  @if ($d->expiry_date)
                    <span class="{{ $d->isExpired() ? 'text-danger fw-bold' : ($d->isExpiringSoon() ? 'text-warning fw-bold' : '') }}">
                      {{ $d->expiry_date->format('d M Y') }}
                    </span>
                  @else
                    —
                  @endif
                </td>
                <td><a href="{{ route('hr.pegawai.berkas.unduh', [$pegawai, $d]) }}" class="btn btn-sm btn-outline-secondary">Unduh</a></td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada berkas.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('hr.pegawai.berkas.simpan', $pegawai) }}" class="row g-2" enctype="multipart/form-data">
          @csrf
          <div class="col-6 col-md-3">
            <select name="document_type_id" class="form-select form-select-sm" required>
              <option value="">— jenis berkas —</option>
              @foreach ($jenisBerkas as $j)
                <option value="{{ $j->id }}">{{ $j->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6 col-md-3"><input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required></div>
          <div class="col-6 col-md-2"><input type="text" name="document_number" class="form-control form-control-sm" placeholder="No. dokumen"></div>
          <div class="col-6 col-md-2"><input type="date" name="expiry_date" class="form-control form-control-sm" placeholder="Berlaku sampai"></div>
          <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Unggah</button></div>
        </form>
        <div class="form-hint mt-2">PDF/JPG/PNG, maksimal 5 MB.</div>
      </div>
    </div>
  </div>
</div>

@endsection
