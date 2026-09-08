{{--
  Kondisi saat kembali dicatat TERPISAH dari kondisi eksemplar: yang
  pertama fakta tentang transaksi ini, yang kedua keadaan bendanya
  sekarang. Enum `status_buku` Khanza memaksa keduanya jadi satu nilai.
--}}
<form method="POST" action="{{ route('library.sirkulasi.kembali', $pinjaman) }}" class="d-flex gap-1">
  @csrf
  <select name="condition" class="form-select form-select-sm" style="width:7rem">
    @foreach ($kondisi as $k)
      <option value="{{ $k }}">{{ ucfirst($k) }}</option>
    @endforeach
  </select>
  <input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan">
  <button class="btn btn-sm btn-outline-success">Kembali</button>
</form>
