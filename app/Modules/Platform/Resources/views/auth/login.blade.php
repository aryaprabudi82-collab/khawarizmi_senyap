<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk &middot; SIMRS RSP UI</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
  <style>
    :root { --tblr-font-sans-serif: "Plus Jakarta Sans", system-ui, sans-serif; }
    body { font-family: var(--tblr-font-sans-serif);
           background: linear-gradient(135deg,#1d4ed8,#0e5aa7); }
  </style>
</head>
<body class="d-flex flex-column justify-content-center">
  <div class="container-tight py-4" style="max-width: 26rem;">

    <div class="text-center mb-4 text-white">
      <div class="h1 mb-1">SIMRS RSP UI</div>
      <div class="text-white-50">Sistem Informasi Manajemen Rumah Sakit</div>
    </div>

    <form class="card card-md" method="POST" action="{{ route('masuk.kirim') }}">
      @csrf
      <div class="card-body">
        <h2 class="card-title text-center mb-4">Masuk ke akun Anda</h2>

        @if ($errors->any())
          <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif

        <div class="mb-3">
          <label class="form-label" for="username">Nama pengguna</label>
          <input type="text" id="username" name="username" class="form-control"
                 value="{{ old('username') }}" autocomplete="username" autofocus required>
        </div>

        <div class="mb-2">
          <label class="form-label" for="password">Kata sandi</label>
          <input type="password" id="password" name="password" class="form-control"
                 autocomplete="current-password" required>
        </div>

        <div class="mb-3">
          <label class="form-check">
            <input type="checkbox" name="ingat_saya" value="1" class="form-check-input">
            <span class="form-check-label">Ingat saya di perangkat ini</span>
          </label>
        </div>

        <div class="form-footer">
          <button type="submit" class="btn btn-primary w-100">Masuk</button>
        </div>
      </div>
    </form>

    <div class="text-center text-white-50 mt-3 small">
      Setiap aktivitas masuk dicatat dalam jejak audit.
    </div>
  </div>
</body>
</html>
