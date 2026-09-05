<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $dari }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $sampai }}"></div>
      <div class="col-6 col-md-2">
        <label class="form-label">Jenis Rawat</label>
        <select name="jenis_rawat" class="form-select">
          <option value="">Semua</option>
          <option value="ralan" @selected($jenisRawat === 'ralan')>Rawat Jalan</option>
          <option value="ranap" @selected($jenisRawat === 'ranap')>Rawat Inap</option>
        </select>
      </div>

      {{-- Hanya layar rekap biaya yang punya jenis biaya; layar pembayaran tidak. --}}
      @isset($sumber)
        <div class="col-6 col-md-2">
          <label class="form-label">Jenis Biaya</label>
          <select name="sumber" class="form-select">
            <option value="">Semua</option>
            @foreach (['registrasi', 'kamar', 'tindakan_ralan', 'operasi', 'resep_obat', 'order_penunjang', 'penyesuaian'] as $s)
              <option value="{{ $s }}" @selected($sumber === $s)>{{ str_replace('_', ' ', $s) }}</option>
            @endforeach
          </select>
        </div>
      @endisset

      <div class="col-6 col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </form>
  </div>
</div>
