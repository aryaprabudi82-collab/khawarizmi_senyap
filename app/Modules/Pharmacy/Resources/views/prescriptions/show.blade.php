@extends('layouts.app')

@section('title', 'Resep ' . $resep->prescription_number)
@section('breadcrumb', 'Konteks pharmacy &middot; ' . $resep->registration_number)
@section('heading', $resep->patient_name)

@section('actions')
  <a href="{{ route('resep.index') }}" class="btn btn-link">Kembali ke antrean</a>
@endsection

@section('content')

{{-- Peringatan alergi mendahului apa pun --}}
@if ($temuan !== [])
  @php $adaBerat = collect($temuan)->contains(fn ($t) => $t['severity'] === 'berat'); @endphp
  <div class="alert alert-{{ $adaBerat ? 'danger' : 'warning' }}">
    <h4 class="alert-title">
      {{ $adaBerat ? 'Peringatan alergi berat' : 'Peringatan alergi' }}
      &middot; {{ count($temuan) }} temuan
    </h4>
    <ul class="mb-2 mt-2">
      @foreach ($temuan as $t)
        <li>
          <strong>{{ $t['drug_name'] }}</strong> cocok dengan alergi
          <strong>{{ $t['substance'] }}</strong> ({{ $t['severity'] }})
          @if ($t['reaction']) &mdash; reaksi: {{ $t['reaction'] }} @endif
          <span class="text-secondary small">&middot; dicocokkan pada {{ $t['matched_on'] }}</span>
        </li>
      @endforeach
    </ul>
    <div class="small text-secondary">
      Pencocokan dilakukan pada nama zat dan nama generik. Alergi lintas golongan
      (mis. penisilin terhadap sefalosporin) belum terdeteksi — telaah apoteker tetap diperlukan.
    </div>
  </div>
@endif

<div class="row g-3">

  {{-- Kiri: identitas dan status --}}
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item">
            <div class="datagrid-title">No. Resep</div>
            <div class="datagrid-content font-monospace">{{ $resep->prescription_number }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">No. Rekam Medis</div>
            <div class="datagrid-content font-monospace">{{ $resep->patient_mrn }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Unit</div>
            <div class="datagrid-content">{{ $resep->unit_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Dokter penulis</div>
            <div class="datagrid-content">{{ $resep->prescriber_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Status</div>
            <div class="datagrid-content">
              <span class="badge bg-blue-lt">{{ \App\Modules\Pharmacy\Models\Prescription::statusLabel($resep->status) }}</span>
            </div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Nilai resep</div>
            <div class="datagrid-content">Rp {{ number_format((float) $resep->total_amount, 0, ',', '.') }}</div>
          </div>
        </div>
      </div>
    </div>

    {{-- Jejak telaah --}}
    @if ($resep->reviews->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Jejak telaah</h3></div>
        <div class="list-group list-group-flush">
          @foreach ($resep->reviews as $telaah)
            <div class="list-group-item">
              <div class="d-flex justify-content-between">
                <span class="badge bg-{{ $telaah->outcome === 'disetujui' ? 'green' : 'red' }}-lt">
                  {{ $telaah->outcome }}
                </span>
                <span class="text-secondary small">{{ $telaah->reviewed_at->format('d-m-Y H:i') }}</span>
              </div>
              <div class="small mt-1">{{ $telaah->reviewer_name ?? 'sistem' }}</div>
              @if ($telaah->pharmacist_note)
                <div class="text-secondary small mt-1">{{ $telaah->pharmacist_note }}</div>
              @endif
              @if (! empty($telaah->findings))
                <div class="text-secondary small mt-1">
                  {{ count($telaah->findings) }} temuan tercatat saat telaah.
                </div>
              @endif
            </div>
          @endforeach
        </div>
      </div>
    @endif

    {{-- Pembatalan --}}
    @if (! in_array($resep->status, ['diserahkan', 'batal'], true))
      <div class="card">
        <div class="card-body">
          <form method="POST" action="{{ route('resep.batal', $resep) }}">
            @csrf
            <label class="form-label" for="alasan">Batalkan resep</label>
            <textarea id="alasan" name="alasan" class="form-control mb-2" rows="2"
                      minlength="5" placeholder="Alasan pembatalan" required></textarea>
            <button class="btn btn-outline-danger w-100">Batalkan Resep</button>
          </form>
        </div>
      </div>
    @endif
  </div>

  {{-- Kanan: isi resep --}}
  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Isi resep</h3></div>

      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr>
              <th>Obat</th><th>Aturan pakai</th>
              <th class="text-end">Jumlah</th><th class="text-end">Stok</th>
              <th class="text-end">Subtotal</th><th class="w-1"></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($resep->items as $item)
              @php $tersedia = $stok[$item->id] ?? null; @endphp
              <tr>
                <td>
                  <div class="fw-semibold">{{ $item->drug_name }}</div>
                  @if ($item->drug?->isControlled())
                    <span class="badge bg-red-lt">
                      {{ $item->drug->is_narcotic ? 'Narkotika' : 'Psikotropika' }}
                    </span>
                  @endif
                </td>
                <td class="text-secondary">{{ $item->dosage_instruction }}</td>
                <td class="text-end">
                  {{ rtrim(rtrim($item->quantity, '0'), '.') }} {{ $item->drug_unit }}
                </td>
                <td class="text-end {{ $tersedia !== null && $tersedia < (float) $item->quantity ? 'text-danger fw-semibold' : 'text-secondary' }}">
                  {{ $tersedia === null ? '—' : rtrim(rtrim(number_format($tersedia, 2, ',', '.'), '0'), ',') }}
                </td>
                <td class="text-end">Rp {{ number_format($item->subtotal(), 0, ',', '.') }}</td>
                <td>
                  @if ($resep->isEditable())
                    <form method="POST" action="{{ route('resep.item.hapus', $item) }}">
                      @csrf @method('DELETE')
                      <button class="btn btn-sm btn-ghost-danger">Hapus</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-secondary py-3">Resep masih kosong.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($resep->isEditable())
        <div class="card-body border-top">
          <form method="POST" action="{{ route('resep.item.simpan', $resep) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-12 col-md-4">
              <label class="form-label" for="cari-obat">Obat</label>
              <input type="text" id="cari-obat" class="form-control" list="daftar-obat"
                     placeholder="Ketik nama atau kode obat" autocomplete="off">
              <input type="hidden" name="drug_id" id="drug_id">
              <datalist id="daftar-obat"></datalist>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label" for="quantity">Jumlah</label>
              <input type="number" step="0.01" min="0.01" id="quantity" name="quantity" class="form-control" required>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label" for="dosage_instruction">Aturan pakai</label>
              <input type="text" id="dosage_instruction" name="dosage_instruction" class="form-control"
                     placeholder="mis. 3x1 sesudah makan" required>
            </div>
            <div class="col-12 col-md-2">
              <button class="btn btn-outline-primary w-100">Tambah</button>
            </div>
          </form>
        </div>
      @endif
    </div>

    {{-- Kirim ke apoteker --}}
    @if ($resep->isEditable() && $resep->items->isNotEmpty())
      <form method="POST" action="{{ route('resep.kirim', $resep) }}" class="mb-3">
        @csrf
        <div class="card">
          <div class="card-body d-flex justify-content-between align-items-center">
            <div>
              <strong>Kirim ke apoteker</strong>
              <div class="text-secondary small">
                Resep wajib ditelaah apoteker sebelum bisa diserahkan. Stok belum berkurang di tahap ini.
              </div>
            </div>
            <button class="btn btn-primary">Kirim untuk Ditelaah</button>
          </div>
        </div>
      </form>
    @endif

    {{-- Telaah apoteker --}}
    @if ($resep->status === 'menunggu-telaah')
      @can('telaah_resep')
        <form method="POST" action="{{ route('resep.telaah', $resep) }}" class="mb-3">
          @csrf
          <div class="card">
            <div class="card-header"><h3 class="card-title">Telaah apoteker</h3></div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label" for="note">Catatan apoteker</label>
                <textarea id="note" name="note" class="form-control" rows="2"
                          placeholder="Wajib diisi bila menyetujui resep yang memicu peringatan alergi berat">{{ old('note') }}</textarea>
              </div>
              <div class="d-flex gap-2">
                <button name="outcome" value="disetujui" class="btn btn-success">Setujui</button>
                <button name="outcome" value="ditolak" class="btn btn-outline-danger">Tolak</button>
              </div>
            </div>
          </div>
        </form>
      @else
        <div class="alert alert-info">Resep menunggu telaah apoteker.</div>
      @endcan
    @endif

    {{-- Penyerahan --}}
    @if ($resep->isDispensable())
      @can('beri_obat')
        <form method="POST" action="{{ route('resep.serah', $resep) }}">
          @csrf
          <div class="card">
            <div class="card-header"><h3 class="card-title">Serahkan obat</h3></div>
            <div class="card-body">
              <label class="form-label" for="location_id">Depo penyerahan</label>
              <select id="location_id" name="location_id" class="form-select mb-2" required>
                @foreach ($lokasi as $l)
                  <option value="{{ $l->id }}" @selected($l->code === 'DEPO-RJ')>{{ $l->name }}</option>
                @endforeach
              </select>
              <div class="form-hint mb-3">
                Batch diambil dengan kaidah FEFO: yang paling dekat kedaluwarsa keluar lebih dulu.
                Stok berkurang saat tombol ini ditekan.
              </div>
              <button class="btn btn-success">Serahkan dan Kurangi Stok</button>
            </div>
          </div>
        </form>
      @endcan
    @endif

    @if ($resep->status === 'diserahkan')
      <div class="alert alert-success d-flex justify-content-between align-items-center">
        <span>
          Diserahkan {{ $resep->dispensed_at?->format('d-m-Y H:i') }}
          oleh {{ $resep->dispensed_by_name ?? '—' }}.
        </span>
        @can('pembayaran_ralan')
          <form method="POST" action="{{ route('tagihan.buka', $resep->registration_id) }}">
            @csrf
            <button class="btn btn-sm btn-success">Buka Tagihan</button>
          </form>
        @endcan
      </div>
    @endif
  </div>
</div>

@push('scripts')
<script>
  // Melengkapi nama obat sambil mengetik, lalu menyimpan id-nya.
  document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('cari-obat');
    var hidden = document.getElementById('drug_id');
    var daftar = document.getElementById('daftar-obat');
    if (!input || !hidden || !daftar) return;

    var peta = {};
    var tunda;

    input.addEventListener('input', function () {
      // Pilihan sebelumnya batal begitu teksnya diubah.
      hidden.value = peta[input.value] || '';

      clearTimeout(tunda);
      var q = input.value.trim();
      if (q.length < 2) return;

      tunda = setTimeout(function () {
        fetch('{{ route('resep.cari-obat') }}?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (hasil) {
            daftar.innerHTML = '';
            peta = {};
            hasil.forEach(function (o) {
              peta[o.label] = o.id;
              var opt = document.createElement('option');
              opt.value = o.label;
              daftar.appendChild(opt);
            });
            hidden.value = peta[input.value] || '';
          })
          .catch(function () { /* pencarian gagal: biarkan petugas mengulang */ });
      }, 250);
    });
  });
</script>
@endpush

@endsection
