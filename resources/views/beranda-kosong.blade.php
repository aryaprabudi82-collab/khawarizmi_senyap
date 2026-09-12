@extends('layouts.app')

@section('title', 'Beranda')
@section('heading', 'Selamat datang, ' . (auth()->user()->name ?? ''))

@section('content')

{{--
  Halaman ini muncul saat seorang pengguna berhasil masuk tapi belum
  punya satu pun hak yang mengantarnya ke layar mana pun.

  Sebelum ada halaman ini, keadaan itu berakhir dengan 403 — pengguna
  memasukkan kata sandi yang BENAR lalu melihat "Forbidden", dan tidak
  ada cara membedakannya dari akun yang bermasalah. Yang sesungguhnya
  terjadi jauh lebih sederhana: perannya belum diberi hak apa pun.
--}}

<div class="card">
  <div class="card-body">
    <h3 class="card-title">Belum ada layar yang bisa dibuka</h3>

    <p>
      Akun Anda sudah aktif dan kata sandinya benar &mdash; yang belum ada adalah
      <b>hak akses</b>. Perannya belum diberi kewenangan atas satu pun layar,
      jadi tidak ada halaman yang bisa ditampilkan di sini.
    </p>

    <p class="text-secondary">
      Hubungi administrator sistem untuk menambahkan peran atau kewenangan yang
      sesuai dengan tugas Anda. Sebutkan nama pengguna
      <span class="font-monospace">{{ auth()->user()->username ?? '—' }}</span>
      supaya tidak perlu dicari.
    </p>

    @if (($peran = auth()->user()?->roles) && $peran->isNotEmpty())
      <div class="mt-3">
        <div class="text-secondary small">Peran yang melekat pada akun ini:</div>
        @foreach ($peran as $p)
          <span class="badge bg-blue-lt">{{ $p->name }}</span>
        @endforeach
      </div>
    @else
      <div class="alert alert-warning mt-3 mb-0">
        Akun ini <b>belum punya peran sama sekali</b>. Itu penyebab paling mungkin,
        dan hanya administrator yang bisa menambahkannya.
      </div>
    @endif
  </div>
</div>

@endsection
