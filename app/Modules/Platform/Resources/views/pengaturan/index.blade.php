@extends('layouts.app')

@section('title', 'Pengaturan Aplikasi')
@section('breadcrumb', 'Konteks platform')
@section('heading', 'Pengaturan Aplikasi &amp; Identitas Rumah Sakit')

@section('actions')
  @can('user')
    <a href="{{ route('platform.pengguna.index') }}" class="btn btn-link">Pengguna &amp; Peran &rarr;</a>
  @endcan
@endsection

@php use App\Modules\Platform\Models\Setting; @endphp

@section('content')

<div class="alert alert-info">
  <p class="mb-1"><b>Sebelas menu Khanza jadi satu layar berkelompok.</b> Khanza memberi satu menu untuk satu tabel pengaturan &mdash; masing-masing satu baris, sebagian bahkan tanpa <i>primary key</i> (<code>set_embalase</code>) dan satu di antaranya MyISAM (<code>set_keterlambatan</code>). Sebelas layar terpisah berarti tidak ada satu pun tempat yang bisa menjawab pertanyaan paling wajar seorang admin baru: apa saja yang belum diatur.</p>
  <p class="mb-0"><b>Setiap perubahan menyimpan riwayatnya berikut tanggal mulai berlaku.</b> Tabel <code>set_*</code> Khanza hanya menyimpan nilai berjalan, jadi tarif embalase yang dipakai menagih resep bulan lalu tidak bisa direkonstruksi &mdash; dan tagihan yang tidak bisa direkonstruksi tidak bisa dibantah maupun dibenarkan.</p>
</div>

@if ($belumDitetapkan !== [])
  <div class="alert alert-warning">
    <b>{{ count($belumDitetapkan) }} pengaturan belum ditetapkan.</b> Nilainya sengaja dibiarkan kosong, bukan diisi tebakan: kosong <b>bukan</b> nol. Kalau embalase yang belum diputuskan diisi nol, ia akan tertagih sebagai gratis dan tidak ada yang tahu bahwa itu bukan keputusan siapa pun.
    <div class="mt-1 font-monospace small">{{ implode(', ', $belumDitetapkan) }}</div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header"><h3 class="card-title">Identitas Rumah Sakit</h3></div>
  <div class="card-body">
    <form method="POST" action="{{ route('platform.pengaturan.institusi') }}" class="row g-2">
      @csrf
      <div class="col-12 col-md-6"><label class="form-label">Nama Rumah Sakit</label><input type="text" name="name" class="form-control" value="{{ old('name', $institusi?->name) }}" required></div>
      <div class="col-12 col-md-6"><label class="form-label">Alamat</label><input type="text" name="address" class="form-control" value="{{ old('address', $institusi?->address) }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Kota/Kabupaten</label><input type="text" name="city" class="form-control" value="{{ old('city', $institusi?->city) }}"></div>
      <div class="col-6 col-md-3"><label class="form-label">Provinsi</label><input type="text" name="province" class="form-control" value="{{ old('province', $institusi?->province) }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Kode Pos</label><input type="text" name="postal_code" class="form-control" value="{{ old('postal_code', $institusi?->postal_code) }}"></div>
      <div class="col-6 col-md-2"><label class="form-label">Telepon</label><input type="text" name="phone" class="form-control" value="{{ old('phone', $institusi?->phone) }}"></div>
      <div class="col-12 col-md-2"><label class="form-label">Surel</label><input type="email" name="email" class="form-control" value="{{ old('email', $institusi?->email) }}"></div>

      <div class="col-12"><hr class="my-1"><div class="form-label mb-0">Kode Fasilitas pada Sistem Luar</div>
        <div class="form-hint">Tiga kolom, bukan satu: kode fasilitas berbeda per sistem, dan satu kolom "kode PPK" akan memaksa salah satunya salah.</div>
      </div>
      <div class="col-6 col-md-4"><label class="form-label">Kode Kemenkes</label><input type="text" name="code_kemenkes" class="form-control" value="{{ old('code_kemenkes', $institusi?->code_kemenkes) }}"></div>
      <div class="col-6 col-md-4"><label class="form-label">Kode BPJS</label><input type="text" name="code_bpjs" class="form-control" value="{{ old('code_bpjs', $institusi?->code_bpjs) }}"></div>
      <div class="col-6 col-md-4"><label class="form-label">Kode Inhealth</label><input type="text" name="code_inhealth" class="form-control" value="{{ old('code_inhealth', $institusi?->code_inhealth) }}"></div>

      <div class="col-12"><button class="btn btn-primary">Simpan Identitas</button></div>
    </form>
    <div class="form-hint mt-2"><b>Nama bukan kunci.</b> <code>setting</code> Khanza memakai <code>nama_instansi</code> sebagai <i>primary key</i>, jadi mengganti nama rumah sakit tidak mengubah rumah sakitnya melainkan melahirkan yang kedua &mdash; dan yang lama tetap di sana bersama seluruh rujukan yang menempel padanya.</div>
  </div>
</div>

@foreach ($pengaturan as $kelompok => $baris)
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">{{ ucfirst($kelompok) }}</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th style="width:28%">Pengaturan</th><th style="width:14%">Nilai Berjalan</th><th>Ubah</th></tr></thead>
        <tbody>
          @foreach ($baris as $s)
            <tr>
              <td>
                <div>{{ $s->label }}</div>
                <div class="text-secondary small font-monospace">{{ $s->key }}</div>
              </td>
              <td>
                @if ($s->belumDitetapkan())
                  <span class="badge bg-yellow-lt">belum ditetapkan</span>
                @elseif ($s->value_type === Setting::TIPE_BOOLEAN)
                  {{ $s->value === '1' ? 'ya' : 'tidak' }}
                @else
                  {{ $s->value }}
                @endif
              </td>
              <td>
                <form method="POST" action="{{ route('platform.pengaturan.perbarui', $s) }}" class="row g-1">
                  @csrf
                  <div class="col-12 col-md-4">
                    @if ($s->value_type === Setting::TIPE_BOOLEAN)
                      <select name="value" class="form-select form-select-sm">
                        <option value="">— belum ditetapkan —</option>
                        <option value="1" @selected($s->value === '1')>Ya</option>
                        <option value="0" @selected($s->value === '0')>Tidak</option>
                      </select>
                    @elseif ($s->value_type === Setting::TIPE_PILIHAN)
                      <select name="value" class="form-select form-select-sm">
                        <option value="">— belum ditetapkan —</option>
                        @foreach ($s->options ?? [] as $o)
                          <option value="{{ $o }}" @selected($s->value === $o)>{{ $o }}</option>
                        @endforeach
                      </select>
                    @else
                      <input type="{{ in_array($s->value_type, [Setting::TIPE_ANGKA, Setting::TIPE_UANG], true) ? 'number' : 'text' }}"
                             step="any" name="value" class="form-control form-control-sm" value="{{ $s->value }}">
                    @endif
                  </div>
                  <div class="col-6 col-md-3">
                    <input type="date" name="effective_from" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                  </div>
                  <div class="col-6 col-md-3">
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="alasan{{ $s->value_type === Setting::TIPE_UANG ? ' (wajib)' : '' }}" @required($s->value_type === Setting::TIPE_UANG)>
                  </div>
                  <div class="col-12 col-md-2"><button class="btn btn-sm w-100">Simpan</button></div>
                </form>
                @if ($s->revisions()->exists())
                  <div class="text-secondary small mt-1">
                    Perubahan terakhir:
                    @php $r = $s->revisions()->first(); @endphp
                    {{ $r->old_value ?? '—' }} &rarr; {{ $r->new_value ?? '—' }},
                    berlaku {{ $r->effective_from->format('d/m/Y') }}
                    @if ($r->changed_by_name) oleh {{ $r->changed_by_name }} @endif
                    @if ($r->reason) &mdash; {{ $r->reason }} @endif
                  </div>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endforeach

@endsection
