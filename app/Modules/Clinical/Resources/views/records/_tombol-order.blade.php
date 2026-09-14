{{--
  Satu tombol order penunjang.

  MENGAPA TOMBOLNYA TETAP TAMPIL SAAT HAKNYA TIDAK ADA.

  Sebelumnya tombol dibungkus @can polos, sehingga ia hilang sepenuhnya
  bagi peran yang tidak berhak. Akibatnya "tombolnya tidak ada" dan
  "tombolnya rusak" terlihat persis sama dari kursi pengguna — dan itulah
  yang membuat laporan "tombol tidak berfungsi" sulit ditelusuri.

  Di sini tombolnya tetap terlihat, tetapi NONAKTIF berikut alasannya.
  Pengguna jadi tahu bahwa fungsi itu memang ada, hanya bukan
  kewenangannya — dan tahu harus meminta ke siapa.

  Parameter:
    $hak             kode permission yang menggerbangi
    $label           teks tombol
    $warna           varian Tabler (danger/primary/warning/indigo)
    $aksi            URL tujuan form POST
    $alasanTidakBisa alasan tambahan selain hak, mis. master kosong (boleh null)
--}}
@php
  $berhak = auth()->user()?->can($hak) ?? false;
  $terhalang = $alasanTidakBisa !== null;
@endphp

@if ($berhak && ! $terhalang)
  <form method="POST" action="{{ $aksi }}" class="d-inline">
    @csrf
    <button class="btn btn-sm btn-outline-{{ $warna }}">{{ $label }}</button>
  </form>
@else
  <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip"
        title="{{ $terhalang ? $alasanTidakBisa : 'Perlu hak akses: ' . $hak }}">
    <button class="btn btn-sm btn-outline-secondary" disabled>{{ $label }}</button>
  </span>
@endif
