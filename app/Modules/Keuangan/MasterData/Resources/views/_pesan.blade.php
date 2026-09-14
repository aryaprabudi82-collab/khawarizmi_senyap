{{--
  Pesan hasil tindakan.

  Aturan domain modul ini sengaja ditulis sebagai kalimat yang menjelaskan
  AKIBATNYA, bukan sekadar "tidak boleh". Maka pesannya ditampilkan utuh —
  memotongnya jadi "terjadi kesalahan" membuang seluruh gunanya.
--}}
@if (session('sukses'))
  <div class="alert alert-success alert-dismissible">
    {{ session('sukses') }}
    <a class="btn-close" data-bs-dismiss="alert"></a>
  </div>
@endif

@if (session('info'))
  <div class="alert alert-info alert-dismissible">
    {{ session('info') }}
    <a class="btn-close" data-bs-dismiss="alert"></a>
  </div>
@endif

@if (session('gagal'))
  <div class="alert alert-danger alert-dismissible">
    {{ session('gagal') }}
    <a class="btn-close" data-bs-dismiss="alert"></a>
  </div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">
    <h4 class="alert-title">Isian belum benar</h4>
    <ul class="mb-0 mt-2">
      @foreach ($errors->all() as $e)
        <li>{{ $e }}</li>
      @endforeach
    </ul>
  </div>
@endif
