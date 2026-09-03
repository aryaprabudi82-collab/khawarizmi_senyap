@extends('layouts.app')

@section('title', 'Data Master Lab Kesling')
@section('breadcrumb', 'Konteks envlab')
@section('heading', 'Data Master Lab Kesehatan Lingkungan & K3')

@section('content')

<div class="row g-3">

  {{-- Pelanggan --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pelanggan</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Kontak</th></tr></thead>
          <tbody>
            @forelse ($pelanggan as $p)
              <tr>
                <td class="font-monospace small">{{ $p->code }}</td>
                <td>{{ $p->name }}</td>
                <td><span class="badge bg-secondary-lt">{{ $p->kind }}</span></td>
                <td class="text-secondary small">{{ $p->contact_person }} {{ $p->contact_phone ? '· ' . $p->contact_phone : '' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada pelanggan.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('envlab-master.pelanggan.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama pelanggan" required></div>
          <div class="col-4">
            <select name="kind" class="form-select form-select-sm" required>
              <option value="internal">Internal</option>
              <option value="eksternal">Eksternal</option>
            </select>
          </div>
          <div class="col-4"><input type="text" name="contact_person" class="form-control form-control-sm" placeholder="Kontak"></div>
          <div class="col-4"><input type="text" name="contact_phone" class="form-control form-control-sm" placeholder="Telepon"></div>
          <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Jenis Sampel --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Jenis Sampel (Master Sampel Baku Mutu)</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th></tr></thead>
          <tbody>
            @forelse ($jenisSampel as $s)
              <tr>
                <td class="font-monospace small">{{ $s->code }}</td>
                <td>{{ $s->name }}<div class="text-secondary small">{{ $s->regulatory_reference }}</div></td>
                <td><span class="badge bg-azure-lt">{{ $s->category }}</span></td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada jenis sampel.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('envlab-master.jenis-sampel.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama jenis sampel" required></div>
          <div class="col-4">
            <select name="category" class="form-select form-select-sm" required>
              @foreach (\App\Modules\Envlab\Models\SampleType::CATEGORIES as $kategori)
                <option value="{{ $kategori }}">{{ $kategori }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-8"><input type="text" name="regulatory_reference" class="form-control form-control-sm" placeholder="Acuan regulasi (opsional), mis. PP No. 22 Tahun 2021"></div>
          <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Parameter --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Parameter Pengujian</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Kode</th><th>Nama</th><th>Satuan</th><th>Metode</th></tr></thead>
          <tbody>
            @forelse ($parameter as $p)
              <tr>
                <td class="font-monospace small">{{ $p->code }}</td>
                <td>{{ $p->name }}</td>
                <td>{{ $p->unit ?? '—' }}</td>
                <td class="text-secondary small">{{ $p->test_method }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-secondary py-3">Belum ada parameter.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('envlab-master.parameter.simpan') }}" class="row g-2">
          @csrf
          <div class="col-3"><input type="text" name="code" class="form-control form-control-sm" placeholder="Kode" required></div>
          <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Nama parameter" required></div>
          <div class="col-4"><input type="text" name="unit" class="form-control form-control-sm" placeholder="Satuan"></div>
          <div class="col-12"><input type="text" name="test_method" class="form-control form-control-sm" placeholder="Metode uji (opsional), mis. SNI 6989.72:2009"></div>
          <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Baku Mutu --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Nilai Normal Baku Mutu</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Jenis Sampel</th><th>Parameter</th><th>Baku Mutu</th></tr></thead>
          <tbody>
            @forelse ($bakuMutu as $b)
              <tr>
                <td>{{ $b->sampleType?->name }}</td>
                <td>{{ $b->parameter?->name }}</td>
                <td>{{ $b->displayRange() }} {{ $b->parameter?->unit }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="text-center text-secondary py-3">Belum ada baku mutu tercatat.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('envlab-master.baku-mutu.simpan') }}" class="row g-2">
          @csrf
          <div class="col-6">
            <select name="sample_type_id" class="form-select form-select-sm" required>
              <option value="">— jenis sampel —</option>
              @foreach ($jenisSampel as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-6">
            <select name="parameter_id" class="form-select form-select-sm" required>
              <option value="">— parameter —</option>
              @foreach ($parameter as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-4"><input type="number" step="0.0001" name="min_value" class="form-control form-control-sm" placeholder="Nilai min"></div>
          <div class="col-4"><input type="number" step="0.0001" name="max_value" class="form-control form-control-sm" placeholder="Nilai maks"></div>
          <div class="col-4"><input type="text" name="qualitative_standard" class="form-control form-control-sm" placeholder="mis. Negatif"></div>
          <div class="form-hint">Isi rentang minimal/maksimal ATAU standar kualitatif, sesuai jenis parameternya.</div>
          <div class="col-8"><input type="text" name="regulatory_reference" class="form-control form-control-sm" placeholder="Acuan regulasi (opsional)"></div>
          <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Simpan</button></div>
        </form>
      </div>
    </div>
  </div>

</div>

@endsection
