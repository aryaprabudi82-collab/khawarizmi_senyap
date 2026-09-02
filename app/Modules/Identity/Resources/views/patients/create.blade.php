@extends('layouts.app')

@section('title', 'Pasien Baru')
@section('breadcrumb', 'Konteks identity')
@section('heading', 'Daftarkan Pasien Baru')

@section('actions')
  <a href="{{ route('pasien.index') }}" class="btn btn-link">Kembali</a>
@endsection

@section('content')
<form method="POST" action="{{ route('pasien.store') }}">
  @csrf

  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Identitas</h3></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label required" for="name">Nama lengkap</label>
              <input type="text" id="name" name="name" class="form-control"
                     value="{{ old('name', $namaAwal) }}" required autofocus>
            </div>

            <div class="col-md-4">
              <label class="form-label required" for="sex">Jenis kelamin</label>
              <select id="sex" name="sex" class="form-select" required>
                <option value="">— pilih —</option>
                <option value="L" @selected(old('sex') === 'L')>Laki-laki</option>
                <option value="P" @selected(old('sex') === 'P')>Perempuan</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="nik">NIK</label>
              <input type="text" id="nik" name="nik" class="form-control" maxlength="16"
                     inputmode="numeric" value="{{ old('nik') }}">
              <div class="form-hint">
                Boleh dikosongkan untuk bayi baru lahir atau pasien gawat darurat tanpa identitas.
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="mother_name">Nama ibu kandung</label>
              <input type="text" id="mother_name" name="mother_name" class="form-control"
                     value="{{ old('mother_name') }}">
              <div class="form-hint">Pembeda utama saat NIK belum ada.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="birth_place">Tempat lahir</label>
              <input type="text" id="birth_place" name="birth_place" class="form-control"
                     value="{{ old('birth_place') }}">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="birth_date">Tanggal lahir</label>
              <input type="date" id="birth_date" name="birth_date" class="form-control"
                     value="{{ old('birth_date') }}" max="{{ now()->toDateString() }}">
            </div>

            <div class="col-md-4">
              <label class="form-label" for="blood_type">Golongan darah</label>
              <select id="blood_type" name="blood_type" class="form-select">
                <option value="">—</option>
                @foreach (['A','B','AB','O'] as $gol)
                  <option value="{{ $gol }}" @selected(old('blood_type') === $gol)>{{ $gol }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label" for="religion">Agama</label>
              <select id="religion" name="religion" class="form-select">
                <option value="">—</option>
                @foreach (['Islam','Kristen','Katolik','Hindu','Buddha','Konghucu','Lainnya'] as $agama)
                  <option value="{{ $agama }}" @selected(old('religion') === $agama)>{{ $agama }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label" for="marital_status">Status kawin</label>
              <select id="marital_status" name="marital_status" class="form-select">
                <option value="">—</option>
                @foreach (['Belum Kawin','Kawin','Cerai Hidup','Cerai Mati'] as $status)
                  <option value="{{ $status }}" @selected(old('marital_status') === $status)>{{ $status }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="education">Pendidikan</label>
              <select id="education" name="education" class="form-select">
                <option value="">—</option>
                @foreach (['Tidak Sekolah','SD','SMP','SMA','D1','D2','D3','D4','S1','S2','S3'] as $pend)
                  <option value="{{ $pend }}" @selected(old('education') === $pend)>{{ $pend }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="occupation">Pekerjaan</label>
              <input type="text" id="occupation" name="occupation" class="form-control"
                     value="{{ old('occupation') }}">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Alamat dan kontak</h3></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label" for="address">Alamat</label>
              <textarea id="address" name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="village_name">Kelurahan/Desa</label>
              <input type="text" id="village_name" name="village_name" class="form-control"
                     value="{{ old('village_name') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="district_name">Kecamatan</label>
              <input type="text" id="district_name" name="district_name" class="form-control"
                     value="{{ old('district_name') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="city_name">Kabupaten/Kota</label>
              <input type="text" id="city_name" name="city_name" class="form-control"
                     value="{{ old('city_name') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="province_name">Provinsi</label>
              <input type="text" id="province_name" name="province_name" class="form-control"
                     value="{{ old('province_name') }}">
            </div>
            <div class="col-12">
              <label class="form-label" for="phone">Nomor telepon</label>
              <input type="text" id="phone" name="phone" class="form-control" value="{{ old('phone') }}">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Penanggung jawab</h3></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label" for="guardian_name">Nama</label>
              <input type="text" id="guardian_name" name="guardian_name" class="form-control"
                     value="{{ old('guardian_name') }}">
            </div>
            <div class="col-md-5">
              <label class="form-label" for="guardian_relation">Hubungan</label>
              <select id="guardian_relation" name="guardian_relation" class="form-select">
                <option value="">—</option>
                @foreach (['Diri Sendiri','Suami','Istri','Ayah','Ibu','Anak','Saudara','Lainnya'] as $hub)
                  <option value="{{ $hub }}" @selected(old('guardian_relation') === $hub)>{{ $hub }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12">
              <label class="form-label" for="guardian_phone">Nomor telepon</label>
              <input type="text" id="guardian_phone" name="guardian_phone" class="form-control"
                     value="{{ old('guardian_phone') }}">
            </div>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <span class="text-secondary small">Nomor RM dialokasikan otomatis.</span>
          <button type="submit" class="btn btn-primary">Simpan dan Daftarkan</button>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
