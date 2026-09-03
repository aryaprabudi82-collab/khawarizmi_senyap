@extends('layouts.app')

@section('title', 'Tata Usaha — Surat')
@section('breadcrumb', 'Konteks correspondence')
@section('heading', 'Surat Masuk & Surat Keluar')

@section('actions')
  <a href="{{ route('correspondence.pengumuman.index') }}" class="btn btn-link">Pengumuman &rarr;</a>
@endsection

@section('content')

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Catat Surat Masuk</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('correspondence.masuk.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12"><label class="form-label">Pengirim</label><input type="text" name="sender" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Perihal</label><input type="text" name="subject" class="form-control" required></div>
          <div class="col-6"><label class="form-label">No. Surat Asli</label><input type="text" name="reference_number" class="form-control"></div>
          <div class="col-6">
            <label class="form-label">Sifat</label>
            <select name="classification" class="form-select">
              <option value="biasa">Biasa</option><option value="penting">Penting</option><option value="rahasia">Rahasia</option><option value="segera">Segera</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label">Tanggal Diterima</label><input type="date" name="received_at" class="form-control" value="{{ now()->toDateString() }}" required></div>
          <div class="col-12"><button class="btn btn-primary">Catat</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Surat Masuk</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Agenda</th><th>Pengirim</th><th>Perihal</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($masuk as $s)
              <tr>
                <td class="font-monospace small">{{ $s->letter_number }}</td>
                <td>{{ $s->sender }}</td>
                <td class="text-secondary small">{{ $s->subject }}</td>
                <td>
                  @php $warna = ['diterima' => 'yellow', 'didisposisikan' => 'blue', 'diarsipkan' => 'secondary'][$s->status]; @endphp
                  <span class="badge bg-{{ $warna }}-lt">{{ $s->status }}</span>
                  @if ($s->forwarded_to)
                    <div class="text-secondary small">&rarr; {{ $s->forwarded_to }}</div>
                  @endif
                </td>
                <td>
                  @if ($s->status === 'diterima')
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#disposisi-{{ $s->id }}">Disposisi</button>
                  @elseif ($s->status === 'didisposisikan')
                    <form method="POST" action="{{ route('correspondence.masuk.arsip', $s) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-secondary">Arsipkan</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada surat masuk.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Buat Surat Keluar</h3></div>
      <div class="card-body">
        <form method="POST" action="{{ route('correspondence.keluar.simpan') }}" class="row g-2">
          @csrf
          <div class="col-12"><label class="form-label">Tujuan</label><input type="text" name="recipient" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Perihal</label><input type="text" name="subject" class="form-control" required></div>
          <div class="col-6">
            <label class="form-label">Sifat</label>
            <select name="classification" class="form-select">
              <option value="biasa">Biasa</option><option value="penting">Penting</option><option value="rahasia">Rahasia</option><option value="segera">Segera</option>
            </select>
          </div>
          <div class="col-12"><button class="btn btn-primary">Simpan sebagai Draf</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Surat Keluar</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>No. Surat</th><th>Tujuan</th><th>Perihal</th><th>Status</th><th class="w-1"></th></tr></thead>
          <tbody>
            @forelse ($keluar as $s)
              <tr>
                <td class="font-monospace small">{{ $s->letter_number }}</td>
                <td>{{ $s->recipient }}</td>
                <td class="text-secondary small">{{ $s->subject }}</td>
                <td>
                  @php $warna = ['draft' => 'yellow', 'terkirim' => 'green'][$s->status]; @endphp
                  <span class="badge bg-{{ $warna }}-lt">{{ $s->status }}</span>
                </td>
                <td>
                  @if ($s->isDraft())
                    <form method="POST" action="{{ route('correspondence.keluar.kirim', $s) }}">
                      @csrf
                      <button class="btn btn-sm btn-outline-success">Kirim</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada surat keluar.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

@foreach ($masuk as $s)
  @if ($s->status === 'diterima')
    <div class="modal fade" id="disposisi-{{ $s->id }}" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('correspondence.masuk.disposisi', $s) }}">
          @csrf
          <div class="modal-header"><h5 class="modal-title">Disposisi {{ $s->letter_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body"><label class="form-label">Diteruskan Ke</label><input type="text" name="forwarded_to" class="form-control" required></div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Disposisikan</button></div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
