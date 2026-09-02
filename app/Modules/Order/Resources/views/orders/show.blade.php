@extends('layouts.app')

@section('title', 'Order ' . $order->order_number)
@section('breadcrumb', 'Konteks order &middot; ' . $order->registration_number)
@section('heading', $order->patient_name)

@section('actions')
  <a href="{{ route('order.index', $kategori) }}" class="btn btn-link">Kembali ke antrean</a>
@endsection

@section('content')

<div class="row g-3">

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item">
            <div class="datagrid-title">No. Order</div>
            <div class="datagrid-content font-monospace">{{ $order->order_number }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">No. Rekam Medis</div>
            <div class="datagrid-content font-monospace">{{ $order->patient_mrn }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Unit</div>
            <div class="datagrid-content">{{ $order->unit_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Dokter peminta</div>
            <div class="datagrid-content">{{ $order->requesting_practitioner_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Status</div>
            <div class="datagrid-content">
              <span class="badge bg-blue-lt">{{ \App\Modules\Order\Models\LabRadiologyOrder::statusLabel($order->status) }}</span>
            </div>
          </div>
          @if ($order->clinical_notes)
            <div class="datagrid-item">
              <div class="datagrid-title">Indikasi klinis</div>
              <div class="datagrid-content">{{ $order->clinical_notes }}</div>
            </div>
          @endif
        </div>
      </div>

      <div class="card-footer d-flex flex-column gap-2">
        @if ($order->status === 'diminta')
          <form method="POST" action="{{ route('order.proses', [$kategori, $order]) }}">
            @csrf
            <button class="btn btn-outline-primary w-100 btn-sm">Mulai Diproses</button>
          </form>
        @endif

        @if ($order->isVerifiable())
          <form method="POST" action="{{ route('order.verifikasi', [$kategori, $order]) }}">
            @csrf
            <button class="btn btn-success w-100 btn-sm">Verifikasi &amp; Selesaikan</button>
          </form>
        @endif

        @if (! in_array($order->status, ['selesai', 'batal'], true))
          <form method="POST" action="{{ route('order.batal', [$kategori, $order]) }}">
            @csrf
            <input type="hidden" name="alasan" value="">
            <button type="button" class="btn btn-outline-danger w-100 btn-sm"
                    onclick="var a=prompt('Alasan pembatalan (minimal 5 karakter):'); if(a && a.length>=5){this.form.alasan.value=a; this.form.submit();}">
              Batalkan Order
            </button>
          </form>
        @endif
      </div>
    </div>

    @if ($order->isEditable())
      <div class="card">
        <div class="card-header"><h3 class="card-title">Tambah pemeriksaan</h3></div>
        <div class="card-body">
          <form method="POST" action="{{ route('order.item.simpan', [$kategori, $order]) }}" class="row g-2">
            @csrf
            <div class="col-12">
              <input type="text" id="cari-tes" class="form-control" list="daftar-tes"
                     placeholder="Ketik nama pemeriksaan" autocomplete="off">
              <input type="hidden" name="test_id" id="test_id">
              <datalist id="daftar-tes">
                @foreach ($katalog as $t)
                  <option value="{{ $t->code }} — {{ $t->name }}" data-id="{{ $t->id }}"></option>
                @endforeach
              </datalist>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-primary w-100">Tambah</button>
            </div>
          </form>
        </div>
      </div>
    @endif
  </div>

  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Pemeriksaan</h3></div>

      <div class="list-group list-group-flush">
        @forelse ($order->items as $item)
          <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <div class="fw-semibold">{{ $item->test_name }}</div>
                @if ($item->referenceDisplay())
                  <div class="text-secondary small">Rujukan: {{ $item->referenceDisplay() }}</div>
                @endif
              </div>
              <div class="d-flex align-items-center gap-2">
                @if ($item->is_abnormal)
                  <span class="badge bg-red-lt">di luar rujukan</span>
                @endif
                @if ($order->isEditable() && $order->items->count() > 1)
                  <form method="POST" action="{{ route('order.item.hapus', [$kategori, $item]) }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-ghost-danger">Hapus</button>
                  </form>
                @endif
              </div>
            </div>

            @if (! in_array($order->status, ['selesai', 'batal'], true))
              <form method="POST" action="{{ route('order.hasil.simpan', [$kategori, $item]) }}" class="row g-2">
                @csrf
                @if ($item->result_type === 'kuantitatif')
                  <div class="col-6 col-md-4">
                    <div class="input-group input-group-sm">
                      <input type="number" step="0.01" name="result_numeric" class="form-control"
                             value="{{ old('result_numeric', $item->result_numeric) }}" placeholder="Nilai">
                      <span class="input-group-text">{{ $item->unit }}</span>
                    </div>
                  </div>
                @elseif ($item->result_type === 'kualitatif')
                  <div class="col-6 col-md-4">
                    <input type="text" name="result_text" class="form-control form-control-sm"
                           value="{{ old('result_text', $item->result_text) }}"
                           placeholder="mis. {{ $item->reference_text }}">
                  </div>
                @else
                  <div class="col-12">
                    <textarea name="result_notes" class="form-control form-control-sm" rows="3"
                              placeholder="Laporan hasil pemeriksaan">{{ old('result_notes', $item->result_notes) }}</textarea>
                  </div>
                @endif
                <div class="col-auto">
                  <button class="btn btn-sm btn-outline-primary">Simpan Hasil</button>
                </div>
              </form>
            @else
              <div class="text-secondary small">
                @if ($item->result_type === 'kuantitatif')
                  Hasil: {{ rtrim(rtrim($item->result_numeric, '0'), '.') }} {{ $item->unit }}
                @elseif ($item->result_type === 'kualitatif')
                  Hasil: {{ $item->result_text }}
                @else
                  {{ $item->result_notes }}
                @endif
              </div>
            @endif

            @if ($item->entered_at)
              <div class="text-secondary small mt-1">
                Dicatat {{ $item->entered_at->format('d-m-Y H:i') }} oleh {{ $item->entered_by_name ?? '—' }}
              </div>
            @endif
          </div>
        @empty
          <div class="list-group-item text-secondary text-center py-4">Belum ada pemeriksaan pada order ini.</div>
        @endforelse
      </div>

      @if ($order->status === 'selesai')
        <div class="card-footer text-secondary small">
          Diverifikasi {{ $order->verified_at?->format('d-m-Y H:i') }} oleh {{ $order->verified_by_name ?? '—' }}.
        </div>
      @endif
    </div>
  </div>
</div>

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('cari-tes');
    var hidden = document.getElementById('test_id');
    var daftar = document.getElementById('daftar-tes');
    if (!input || !hidden || !daftar) return;

    input.addEventListener('input', function () {
      hidden.value = '';
      var opsi = daftar.querySelectorAll('option');
      for (var i = 0; i < opsi.length; i++) {
        if (opsi[i].value === input.value) { hidden.value = opsi[i].dataset.id; break; }
      }
    });
  });
</script>
@endpush

@endsection
