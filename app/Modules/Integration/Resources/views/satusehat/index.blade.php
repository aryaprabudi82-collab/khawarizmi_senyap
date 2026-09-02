@extends('layouts.app')

@section('title', 'Integrasi SATUSEHAT')
@section('breadcrumb', 'Konteks integration')
@section('heading', 'SATUSEHAT — Pemetaan & Sinkronisasi')

@section('content')

<div class="row g-3">

  {{-- Pemetaan lokasi --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Pemetaan Unit &rarr; Location SATUSEHAT</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Unit</th><th>ID Location</th></tr></thead>
          <tbody>
            @foreach ($unit as $u)
              <tr>
                <td>{{ $u->name }}</td>
                <td class="font-monospace small">{{ $pemetaanLokasi[$u->id]->external_id ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('integrasi.satusehat.pemetaan-lokasi') }}" class="row g-2">
          @csrf
          <div class="col-6">
            <select name="unit_id" class="form-select form-select-sm" required>
              @foreach ($unit as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-4"><input type="text" name="satusehat_location_id" class="form-control form-control-sm" placeholder="ID Location" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Simpan</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Pemetaan Praktisi &rarr; Practitioner SATUSEHAT</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Praktisi</th><th>ID Practitioner</th></tr></thead>
          <tbody>
            @foreach ($praktisi as $p)
              <tr>
                <td>{{ $p->name }}</td>
                <td class="font-monospace small">{{ $pemetaanPraktisi[$p->id]->external_id ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-body border-top">
        <form method="POST" action="{{ route('integrasi.satusehat.pemetaan-praktisi') }}" class="row g-2">
          @csrf
          <div class="col-6">
            <select name="practitioner_id" class="form-select form-select-sm" required>
              @foreach ($praktisi as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-4"><input type="text" name="satusehat_practitioner_id" class="form-control form-control-sm" placeholder="ID Practitioner" required></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Simpan</button></div>
        </form>
      </div>
    </div>
  </div>

  {{-- Sinkronisasi --}}
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Sinkronkan Kunjungan</h3></div>
      <div class="card-body">
        <div class="form-hint mb-2">
          Menyinkronkan Patient lalu Encounter untuk satu kunjungan. Biasanya dipicu dari layar registrasi; formulir ini jalan pintas administratif.
        </div>
        <form method="POST" id="form-sinkron-kunjungan" action="{{ route('integrasi.satusehat.kunjungan.sinkron', ['registrasi' => '__ID__']) }}" class="row g-2">
          @csrf
          <div class="col-8"><input type="number" name="registrasi_id" class="form-control" placeholder="ID Kunjungan" required></div>
          <div class="col-4"><button class="btn btn-primary w-100">Sinkron</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Sinkronkan Diagnosis Utama</h3></div>
      <div class="card-body">
        <div class="form-hint mb-2">Kunjungan dan pasiennya harus sudah tersinkron lebih dulu.</div>
        <form method="POST" id="form-sinkron-diagnosis" action="{{ route('integrasi.satusehat.diagnosis.sinkron', ['registrasi' => '__ID__']) }}" class="row g-2">
          @csrf
          <div class="col-8"><input type="number" name="registrasi_id" class="form-control" placeholder="ID Kunjungan" required></div>
          <div class="col-4"><button class="btn btn-primary w-100">Sinkron</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">Riwayat Pengiriman</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Resource</th><th>Sumber</th><th>Status</th><th>ID Eksternal</th><th>Terakhir Dicoba</th></tr></thead>
      <tbody>
        @forelse ($kiriman as $k)
          <tr>
            <td class="text-capitalize">{{ $k->resource_type }}</td>
            <td>{{ $k->source_context }} #{{ $k->source_id }}</td>
            <td>
              @php $warna = ['sent' => 'green', 'failed' => 'red', 'pending' => 'yellow'][$k->status] ?? 'secondary'; @endphp
              <span class="badge bg-{{ $warna }}-lt">{{ $k->status }}</span>
            </td>
            <td class="font-monospace small">{{ $k->external_reference ?? '—' }}</td>
            <td class="text-secondary small">{{ $k->updated_at->format('d-m-Y H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada pengiriman.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<script>
  ['form-sinkron-kunjungan', 'form-sinkron-diagnosis'].forEach(function (formId) {
    var form = document.getElementById(formId);
    form.addEventListener('submit', function () {
      var id = this.querySelector('[name=registrasi_id]').value;
      this.action = this.action.replace('__ID__', encodeURIComponent(id));
    });
  });
</script>

@endsection
