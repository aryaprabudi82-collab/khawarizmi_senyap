<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  {{-- Layar ini menghadap ruang tunggu dan tidak ada yang menekan tombol
       di depannya. Segarnya lewat meta refresh, bukan JavaScript: kalau
       skripnya gagal termuat, halaman yang menyegarkan sendiri tetap
       menyegarkan sendiri, sementara halaman ber-JavaScript akan berhenti
       di angka lama sepanjang hari tanpa ada yang menyadari. --}}
  <meta http-equiv="refresh" content="20">

  <title>@yield('title', 'Antrean') &middot; SIMRS RSP UI</title>

  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 24px 32px;
      background: #0b1220; color: #f8fafc;
      font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
    }
    .kepala { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:20px; }
    .kepala h1 { margin:0; font-size:34px; letter-spacing:-.5px; }
    .kepala .jam { font-size:22px; color:#94a3b8; font-variant-numeric: tabular-nums; }

    .kolom { display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; }

    .kartu { background:#111c33; border:1px solid #1e2c4a; border-radius:14px; overflow:hidden; }
    .kartu h2 { margin:0; padding:14px 18px; font-size:19px; background:#16233d; border-bottom:1px solid #1e2c4a; }
    .kartu.siap h2 { background:#14532d; }
    .kartu ul { list-style:none; margin:0; padding:8px 0; }
    .kartu li { display:flex; align-items:center; gap:14px; padding:10px 18px; border-bottom:1px solid #16233d; }
    .kartu li:last-child { border-bottom:0; }

    .nomor {
      font-size:30px; font-weight:700; font-variant-numeric: tabular-nums;
      min-width:74px; text-align:right; color:#7dd3fc;
    }
    .kartu.siap .nomor { color:#86efac; }
    .nama { font-size:19px; color:#e2e8f0; }
    .kosong { padding:22px 18px; color:#64748b; font-size:17px; }

    .catatan { margin-top:22px; color:#64748b; font-size:13px; line-height:1.6; max-width:70ch; }
  </style>
</head>
<body>

<div class="kepala">
  <h1>@yield('heading', 'Antrean')</h1>
  <div class="jam">{{ now()->format('d/m/Y H:i') }}</div>
</div>

@yield('content')

<p class="catatan">
  Nama sengaja disamarkan. Layar ini menghadap ruang tunggu dan bisa dibaca siapa pun yang lewat &mdash; nama lengkap di sebelah nama poliklinik memberitahu seisi ruangan bahwa seseorang sedang berobat, dan ke poli apa. Nomor antrean cukup untuk mengenali diri sendiri.
</p>

</body>
</html>
