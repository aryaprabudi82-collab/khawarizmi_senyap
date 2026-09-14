<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>@yield('title', 'Dokumen')</title>
  <style>
    @page { size: A4; margin: 2cm; }
    body { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 1.5; color: #000; max-width: 18cm; margin: 0 auto; padding: 1.5cm 0; }
    .kop { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
    .kop h1 { font-size: 14pt; margin: 0; }
    .kop p { margin: 2px 0; font-size: 10pt; }
    /* Logo kop: dicetak hitam-putih pun tetap terbaca karena ukurannya cukup. */
    .kop img { height: 52px; width: auto; margin-bottom: 6px; }
    h2.judul { text-align: center; text-decoration: underline; font-size: 13pt; margin: 20px 0; }
    table.data { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.data td { padding: 4px 8px; vertical-align: top; }
    table.data td.label { width: 180px; }
    .isi { margin: 16px 0; text-align: justify; }
    .ttd { display: flex; justify-content: space-between; margin-top: 60px; }
    .ttd .blok { text-align: center; width: 220px; }
    .ttd .garis { margin-top: 60px; border-top: 1px solid #000; }
    .no-print { text-align: center; margin-bottom: 20px; }
    @media print { .no-print { display: none; } }
  </style>
</head>
<body>
  <div class="no-print"><button onclick="window.print()">Cetak</button></div>

  {{--
    Logo pada kop surat. Dokumen yang keluar dari rumah sakit — surat
    keterangan, resep, PO — dibaca pihak luar, dan kop tanpa logo membuat
    keasliannya sulit dipastikan penerima.
  --}}
  <div class="kop">
    <img src="{{ asset('img/logo-rsui.png') }}" alt="Logo RS UI">
    <h1>RSP UI</h1>
    <p>Rumah Sakit Pendidikan Universitas Indonesia</p>
  </div>

  @yield('content')
</body>
</html>
