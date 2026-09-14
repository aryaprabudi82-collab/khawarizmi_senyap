{{--
  Tindakan & operasi kunjungan ini.

  SETIAP BARIS DI SINI LANGSUNG MENJADI TAGIHAN. Karena itu daftarnya
  ditampilkan lebih dulu, sebelum formulir penambahan: pemeriksa perlu
  melihat apa yang sudah tercatat sebelum menambah, supaya tindakan yang
  sama tidak dicatat dua kali oleh dua orang.
--}}

<div class="mb-3">
  <div class="small text-uppercase text-secondary mb-1">Tindakan</div>

  @if ($tindakan->isNotEmpty())
    <div class="table-responsive mb-2">
      <table class="table table-sm table-vcenter mb-0">
        <thead><tr><th>Tindakan</th><th class="text-end">Jml</th><th class="text-end">Total</th><th>Waktu</th></tr></thead>
        <tbody>
          @foreach ($tindakan as $t)
            <tr>
              <td>{{ $t->service_name }}@if ($t->note)<div class="text-secondary small">{{ $t->note }}</div>@endif</td>
              <td class="text-end">{{ rtrim(rtrim((string) $t->quantity, '0'), '.') }}</td>
              <td class="text-end">{{ number_format($t->amount, 0, ',', '.') }}</td>
              <td class="text-secondary small">{{ $t->performed_at->format('d-m H:i') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @else
    <div class="text-secondary small mb-2">Belum ada tindakan dicatat.</div>
  @endif

  {{--
    FORMULIR DINONAKTIFKAN SAAT KATALOGNYA KOSONG, bukan dibiarkan gagal.
    <select required> tanpa satu pun pilihan membuat tombol Catat tidak
    pernah bisa mengirim form — dan dari kursi pengguna itu terbaca persis
    seperti tombol rusak, tanpa pesan apa pun.
  --}}
  @if ($katalogTindakan->isEmpty())
    <div class="alert alert-warning py-2 mb-0">
      <strong>Belum bisa mencatat tindakan.</strong>
      Belum ada layanan berkategori <em>tindakan</em> di Data Master, sehingga tidak ada
      yang bisa dipilih. Minta Admin Data Master menambahkannya lebih dulu.
    </div>
  @else
    <form method="POST" action="{{ route('rme.tindakan.simpan', $kunjungan->id) }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-5">
        <select name="service_code" class="form-select form-select-sm" required aria-label="Pilih tindakan">
          <option value="">— pilih tindakan —</option>
          @foreach ($katalogTindakan as $layanan)
            <option value="{{ $layanan->code }}">{{ $layanan->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-4 col-md-2">
        <input type="number" name="quantity" class="form-control form-control-sm"
               value="1" min="0.01" step="0.01" required aria-label="Jumlah">
      </div>
      <div class="col-8 col-md-3">
        <input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan (opsional)">
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-sm btn-outline-primary w-100">Catat</button>
      </div>
    </form>
  @endif
</div>

@can('operasi')
  <div>
    <div class="small text-uppercase text-secondary mb-1">Operasi</div>

    @if ($operasi->isNotEmpty())
      <div class="table-responsive mb-2">
        <table class="table table-sm table-vcenter mb-0">
          <thead><tr><th>Tindakan</th><th>Operator</th><th>Anestesi</th><th class="text-end">Tarif</th></tr></thead>
          <tbody>
            @foreach ($operasi as $o)
              <tr>
                <td>{{ $o->service_name }}@if ($o->note)<div class="text-secondary small">{{ $o->note }}</div>@endif</td>
                <td>{{ $o->surgeon_name }}{{ $o->operating_room ? ' · ' . $o->operating_room : '' }}</td>
                <td>{{ $o->anesthesia_type ?? '—' }}</td>
                <td class="text-end">{{ number_format($o->amount, 0, ',', '.') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="text-secondary small mb-2">Belum ada operasi dicatat.</div>
    @endif

    @if ($katalogOperasi->isEmpty())
      <div class="alert alert-warning py-2 mb-0">
        <strong>Belum bisa mencatat operasi.</strong>
        Belum ada layanan berkategori <em>operasi</em> di Data Master.
      </div>
    @else
      <form method="POST" action="{{ route('rme.operasi.simpan', $kunjungan->id) }}" class="row g-2">
        @csrf
        <div class="col-12 col-md-6">
          <select name="service_code" class="form-select form-select-sm" required aria-label="Pilih tindakan operasi">
            <option value="">— pilih tindakan operasi —</option>
            @foreach ($katalogOperasi as $layanan)
              <option value="{{ $layanan->code }}">{{ $layanan->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-6">
          <input type="text" name="surgeon_name" class="form-control form-control-sm"
                 placeholder="Nama operator" required>
        </div>
        <div class="col-6 col-md-4">
          <select name="anesthesia_type" class="form-select form-select-sm" aria-label="Jenis anestesi">
            <option value="">— Anestesi —</option>
            <option value="umum">Umum</option>
            <option value="lokal">Lokal</option>
            <option value="regional">Regional</option>
            <option value="tanpa">Tanpa</option>
          </select>
        </div>
        {{-- Dipilih dari master, tidak diketik: laporan RL mengelompokkan
             utilisasi kamar operasi berdasarkan nilai ini, jadi dua ejaan
             memecah satu ruang jadi dua baris pada laporan wajib. --}}
        <div class="col-6 col-md-4">
          <select name="operating_room" class="form-select form-select-sm" aria-label="Ruang operasi">
            <option value="">— Ruang Operasi —</option>
            @foreach ($ruangOperasi as $r)
              <option value="{{ $r->code }}">{{ $r->code }} · {{ $r->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-12 col-md-4">
          <button class="btn btn-sm btn-outline-primary w-100">Catat</button>
        </div>
        <div class="col-12">
          <input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan (opsional)">
        </div>
      </form>
    @endif
  </div>
@endcan
