@extends('layouts.app')

@section('title', 'Pengujian ' . $pengujian->request_number)
@section('breadcrumb', 'Konteks envlab · ' . $pengujian->request_number)
@section('heading', $pengujian->customer_name)

@section('actions')
  <a href="{{ route('envlab-tests.index') }}" class="btn btn-link">Kembali ke daftar</a>
@endsection

@section('content')

<div class="row g-3">

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Ringkasan</h3></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-5">Status</dt><dd class="col-7">{{ \App\Modules\Envlab\Models\SampleTest::statusLabel($pengujian->status) }}</dd>
          <dt class="col-5">Jenis Sampel</dt><dd class="col-7">{{ $pengujian->sample_type_name }}</dd>
          <dt class="col-5">Deskripsi</dt><dd class="col-7">{{ $pengujian->sample_description ?? '—' }}</dd>
          <dt class="col-5">Diminta</dt><dd class="col-7">{{ $pengujian->requested_at->format('d-m-Y H:i') }} · {{ $pengujian->requested_by_name ?? '—' }}</dd>
          @if ($pengujian->assigned_at)
            <dt class="col-5">Ditugaskan</dt><dd class="col-7">{{ $pengujian->assigned_to_name }} ({{ $pengujian->assigned_at->format('d-m-Y H:i') }})</dd>
          @endif
          @if ($pengujian->rejection_reason)
            <dt class="col-5">Alasan Tolak</dt><dd class="col-7">{{ $pengujian->rejection_reason }}</dd>
          @endif
          @if ($pengujian->verified_at)
            <dt class="col-5">Diverifikasi</dt><dd class="col-7">{{ $pengujian->verified_by_name }} ({{ $pengujian->verified_at->format('d-m-Y H:i') }})</dd>
          @endif
          @if ($pengujian->validated_at)
            <dt class="col-5">Divalidasi</dt><dd class="col-7">{{ $pengujian->validated_by_name }} ({{ $pengujian->validated_at->format('d-m-Y H:i') }})</dd>
          @endif
        </dl>
      </div>
    </div>

    @if ($pengujian->status === 'diminta')
      @can('permintaan_pengujian_sampel_lab_kesehatan_lingkungan')
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Tidak Dapat Dilayani</h3></div>
          <div class="card-body">
            <form method="POST" action="{{ route('envlab-tests.tolak', $pengujian) }}">
              @csrf
              <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
              <button class="btn btn-outline-danger w-100">Tolak Sampel</button>
            </form>
          </div>
        </div>
      @endcan
      @can('penugasan_pengujian_sampel_lab_kesehatan_lingkungan')
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Dapat Dilayani</h3></div>
          <div class="card-body">
            <form method="POST" action="{{ route('envlab-tests.terima', $pengujian) }}">
              @csrf
              <input type="text" name="assigned_to_name" class="form-control mb-2" placeholder="Nama analis" required>
              <button class="btn btn-primary w-100">Terima &amp; Tugaskan</button>
            </form>
          </div>
        </div>
      @endcan
    @endif

    @if ($pengujian->status === 'hasil-tersedia')
      @can('verifikasi_pengujian_sampel_lab_kesehatan_lingkungan')
        <div class="card mb-3">
          <div class="card-body">
            <form method="POST" action="{{ route('envlab-tests.verifikasi', $pengujian) }}">
              @csrf
              <button class="btn btn-primary w-100">Verifikasi Hasil</button>
            </form>
          </div>
        </div>
      @endcan
    @endif

    @if ($pengujian->status === 'terverifikasi')
      @can('validasi_pengujian_sampel_lab_kesehatan_lingkungan')
        <div class="card mb-3">
          <div class="card-body">
            <form method="POST" action="{{ route('envlab-tests.validasi', $pengujian) }}">
              @csrf
              <button class="btn btn-success w-100">Validasi &amp; Selesaikan</button>
            </form>
          </div>
        </div>
      @endcan
    @endif

    @can('pembayaran_pengujian_sampel_lab_kesehatan_lingkungan')
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Pembayaran</h3></div>
        <div class="card-body">
          @if ($pengujian->payment_status === 'lunas')
            <span class="badge bg-green-lt">Lunas — Rp {{ number_format((float) $pengujian->price, 0, ',', '.') }}</span>
          @else
            <form method="POST" action="{{ route('envlab-tests.bayar', $pengujian) }}" class="row g-2">
              @csrf
              <div class="col-8"><input type="number" name="price" class="form-control" placeholder="Jumlah (Rp)" min="0" required></div>
              <div class="col-4"><button class="btn btn-outline-success w-100">Lunas</button></div>
            </form>
          @endif
        </div>
      </div>
    @endcan
  </div>

  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Parameter Pengujian</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Parameter</th><th>Baku Mutu</th><th>Hasil</th><th class="w-1"></th></tr></thead>
          <tbody>
            @foreach ($pengujian->items as $item)
              <tr>
                <td>{{ $item->parameter_name }}</td>
                <td class="text-secondary small">{{ $item->standardDisplay() }} {{ $item->unit }}</td>
                <td>
                  @if ($item->hasResult())
                    {{ $item->result_value ?? $item->result_text }} {{ $item->unit }}
                    @if ($item->is_exceeded)
                      <span class="badge bg-red-lt ms-1">melebihi baku mutu</span>
                    @endif
                  @else
                    <span class="text-secondary">belum ada hasil</span>
                  @endif
                </td>
                <td>
                  @if ($pengujian->status === 'diproses' && ! $item->hasResult())
                    @can('hasil_pengujian_sampel_lab_kesehatan_lingkungan')
                      <form method="POST" action="{{ route('envlab-tests.hasil.simpan', $item) }}" class="d-flex gap-1">
                        @csrf
                        @if ($item->standard_qualitative !== null)
                          <input type="text" name="result_text" class="form-control form-control-sm" placeholder="mis. {{ $item->standard_qualitative }}" required>
                        @else
                          <input type="number" step="0.0001" name="result_value" class="form-control form-control-sm" required>
                        @endif
                        <button class="btn btn-sm btn-outline-primary">Simpan</button>
                      </form>
                    @endcan
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

@endsection
