@extends('layouts.app')

@section('title', 'Tata Usaha — Master Persetujuan')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Master Persetujuan')

@section('actions')
  <a href="{{ route('correspondence.persetujuan.index') }}" class="btn btn-link">&larr; Persetujuan Tindakan</a>
@endsection

@section('content')

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Template Penjelasan</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Menyimpan template dengan kode yang sama akan melahirkan <b>versi baru</b>, bukan menimpa
      yang lama. Versi lama tetap tersimpan karena persetujuan yang sudah ditandatangani
      menunjuk ke sana &mdash; menghapusnya membuat pertanyaan &ldquo;apa persisnya yang
      ditandatangani&rdquo; tak terjawab.
    </div>

    <form method="POST" action="{{ route('correspondence.persetujuan.master.template.simpan') }}" class="row g-2">
      @csrf
      <div class="col-6 col-md-3"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" placeholder="persetujuan-bedah-sesar" required></div>
      <div class="col-6 col-md-5"><label class="form-label">Nama</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis</label>
        <select name="consent_type" class="form-select" required>
          @foreach ($jenis as $j)
            <option value="{{ $j }}">{{ $j }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Perkiraan Biaya</label>
        <input type="number" step="0.01" min="0" name="estimated_cost" class="form-control">
        <div class="form-hint">Boleh kosong.</div>
      </div>
      <div class="col-12"><label class="form-label">Rujukan Pedoman/SPO</label><input type="text" name="note" class="form-control"></div>

      <div class="col-12"><hr class="my-1"><div class="form-label mb-0">Butir Penjelasan</div>
        <div class="form-hint">
          Permenkes 290/2008 pasal 7 ayat (3) menetapkan isi MINIMAL &mdash; diagnosis, tindakan,
          tujuan, alternatif, risiko dan komplikasi, prognosis, serta perkiraan biaya. Menambah
          butir di luar itu dibolehkan; mengurangi tidak.
        </div>
      </div>

      @foreach (range(0, 10) as $i)
        <div class="col-12 col-md-3">
          <input type="text" name="items[{{ $i }}][label]" class="form-control form-control-sm" placeholder="Nama butir {{ $i + 1 }}">
        </div>
        <div class="col-10 col-md-8">
          <input type="text" name="items[{{ $i }}][body]" class="form-control form-control-sm" placeholder="Kalimat yang dibacakan kepada pasien">
        </div>
        <div class="col-2 col-md-1">
          <label class="form-check form-switch mb-0" title="Butir wajib">
            <input type="hidden" name="items[{{ $i }}][is_required]" value="0">
            <input class="form-check-input" type="checkbox" name="items[{{ $i }}][is_required]" value="1" checked>
          </label>
        </div>
      @endforeach

      <div class="col-12"><button class="btn btn-primary">Simpan Template</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Template Tersimpan</h3></div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Versi</th><th>Butir</th><th>Status</th></tr></thead>
      <tbody>
        @forelse ($template as $t)
          <tr>
            <td class="font-monospace small">{{ $t->code }}</td>
            <td>{{ $t->name }}</td>
            <td><span class="badge bg-blue-lt">{{ $t->consent_type }}</span></td>
            <td>v{{ $t->version }}</td>
            <td class="small">{{ $t->items->pluck('label')->implode(', ') }}</td>
            <td>
              @if ($t->is_active)
                <span class="badge bg-green-lt">Aktif</span>
              @else
                <span class="badge bg-secondary-lt">Versi lama</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary py-3">Belum ada template.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Alasan Menolak Anjuran Medis</h3></div>
  <div class="card-body">
    <div class="form-hint mb-2">
      Daftar ini <b>sengaja lahir kosong</b>. Kosakata alasan menolak anjuran medis adalah
      diskresi RSP UI &mdash; Khanza pun cuma menyediakan kolom kode tanpa daftar baku.
      Mengisinya dengan tebakan akan melahirkan statistik resmi tentang kategori yang tidak
      pernah disepakati siapa pun.
    </div>
    <form method="POST" action="{{ route('correspondence.persetujuan.master.alasan.simpan') }}" class="row g-2">
      @csrf
      <div class="col-4 col-md-2"><label class="form-label">Kode</label><input type="text" name="code" class="form-control" required></div>
      <div class="col-8 col-md-8"><label class="form-label">Alasan</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tambah</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Kode</th><th>Alasan</th></tr></thead>
      <tbody>
        @forelse ($alasan as $a)
          <tr><td class="font-monospace small">{{ $a->code }}</td><td>{{ $a->name }}</td></tr>
        @empty
          <tr><td colspan="2" class="text-center text-secondary py-3">Belum ditetapkan RSP UI.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@endsection
