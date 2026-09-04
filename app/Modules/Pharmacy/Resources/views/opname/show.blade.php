@extends('layouts.app')

@section('title', 'Opname ' . $opname->opname_number)
@section('breadcrumb', 'Konteks pharmacy')
@section('heading', 'Opname ' . $opname->opname_number . ' — ' . $opname->location->name)

@section('actions')
  <a href="{{ route('pharmacy.opname.index') }}" class="btn btn-link">&larr; Daftar Opname</a>
@endsection

@section('content')

<div class="card">
  <div class="card-header"><h3 class="card-title">Hitung Fisik</h3></div>
  <div class="card-body">
    @if ($opname->status === 'draf')
      <form method="POST" action="{{ route('pharmacy.opname.hitung', $opname) }}" class="mb-3">
        @csrf
        <div class="table-responsive mb-2">
          <table class="table table-sm">
            <thead><tr><th>Obat</th><th>Batch</th><th class="text-end">Sistem</th><th style="width:130px">Hasil Hitung</th></tr></thead>
            <tbody>
              @foreach ($opname->items as $baris)
                <tr>
                  <td>
                    {{ $baris->batch->drug->name }}
                    <input type="hidden" name="item_id[]" value="{{ $baris->id }}">
                  </td>
                  <td class="text-secondary small">{{ $baris->batch->batch_number }}</td>
                  <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $baris->system_quantity, 2, ',', '.'), '0'), ',') }}</td>
                  <td><input type="number" step="0.01" min="0" name="counted_quantity[]" class="form-control form-control-sm" value="{{ $baris->counted_quantity }}"></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <button class="btn btn-sm btn-outline-primary">Simpan Hasil Hitung</button>
      </form>

      <form method="POST" action="{{ route('pharmacy.opname.selesai', $opname) }}" onsubmit="return confirm('Selesaikan opname? Selisih akan disesuaikan sebagai koreksi stok.')">
        @csrf
        <button class="btn btn-success">Selesaikan Opname</button>
      </form>
    @else
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>Obat</th><th>Batch</th><th class="text-end">Sistem</th><th class="text-end">Hitung Fisik</th><th class="text-end">Selisih</th></tr></thead>
          <tbody>
            @foreach ($opname->items as $baris)
              <tr>
                <td>{{ $baris->batch->drug->name }}</td>
                <td class="text-secondary small">{{ $baris->batch->batch_number }}</td>
                <td class="text-end font-monospace">{{ rtrim(rtrim(number_format((float) $baris->system_quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td class="text-end font-monospace">{{ $baris->counted_quantity !== null ? rtrim(rtrim(number_format((float) $baris->counted_quantity, 2, ',', '.'), '0'), ',') : '—' }}</td>
                <td class="text-end font-monospace {{ ($baris->difference() ?? 0) < 0 ? 'text-danger' : (($baris->difference() ?? 0) > 0 ? 'text-success' : '') }}">
                  {{ $baris->difference() !== null ? ($baris->difference() > 0 ? '+' : '') . rtrim(rtrim(number_format($baris->difference(), 2, ',', '.'), '0'), ',') : '—' }}
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>

@endsection
