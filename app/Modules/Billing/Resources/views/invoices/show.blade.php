@extends('layouts.app')

@section('title', 'Tagihan ' . $tagihan->invoice_number)
@section('breadcrumb', 'Konteks billing · ' . $tagihan->registration_number)
@section('heading', $tagihan->patient_name)

@section('actions')
  <a href="{{ route('tagihan.index') }}" class="btn btn-link">Kembali ke kasir</a>
@endsection

@section('content')

@if (! $tagihan->isPatientPayable() && ! $tagihan->isVoid())
  <div class="alert alert-info">
    Tagihan ini ditanggung <strong>{{ $tagihan->payer_name }}</strong> — tidak ditagihkan ke pasien di kasir.
    Penagihan ke penjamin (klaim/piutang) menyusul di domain keuangan.
  </div>
@endif

<div class="row g-3">

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-body">
        <div class="datagrid">
          <div class="datagrid-item">
            <div class="datagrid-title">No. Tagihan</div>
            <div class="datagrid-content font-monospace">{{ $tagihan->invoice_number }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">No. Rekam Medis</div>
            <div class="datagrid-content font-monospace">{{ $tagihan->patient_mrn }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Unit</div>
            <div class="datagrid-content">{{ $tagihan->unit_name ?? '—' }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Penjamin</div>
            <div class="datagrid-content">{{ $tagihan->payer_name }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Status</div>
            <div class="datagrid-content">
              <span class="badge bg-blue-lt">{{ \App\Modules\Billing\Models\Invoice::statusLabel($tagihan->status) }}</span>
            </div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Total tagihan</div>
            <div class="datagrid-content">Rp {{ number_format((float) $tagihan->total_amount, 0, ',', '.') }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Sudah dibayar</div>
            <div class="datagrid-content">Rp {{ number_format((float) $tagihan->paid_amount, 0, ',', '.') }}</div>
          </div>
          <div class="datagrid-item">
            <div class="datagrid-title">Sisa</div>
            <div class="datagrid-content fw-bold {{ $tagihan->outstanding() > 0 ? 'text-danger' : 'text-success' }}">
              Rp {{ number_format($tagihan->outstanding(), 0, ',', '.') }}
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <form method="POST" action="{{ route('tagihan.segarkan', $tagihan) }}" class="flex-fill">
          @csrf
          <button class="btn btn-outline-secondary w-100 btn-sm">Segarkan Biaya</button>
        </form>
        @if ($tagihan->status === 'terbuka' && $tagihan->payments->where('voided_at', null)->isEmpty())
          <form method="POST" action="{{ route('tagihan.batal', $tagihan) }}"
                onsubmit="return confirm('Batalkan tagihan ini?')" class="flex-fill">
            @csrf
            <input type="hidden" name="alasan" value="Dibatalkan dari layar kasir">
            <button class="btn btn-outline-danger w-100 btn-sm">Batalkan</button>
          </form>
        @endif
      </div>
    </div>

    @if ($tagihan->isPatientPayable() && $tagihan->outstanding() > 0)
      <div class="card">
        <div class="card-header"><h3 class="card-title">Terima pembayaran</h3></div>
        <form method="POST" action="{{ route('tagihan.bayar', $tagihan) }}">
          @csrf
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label" for="amount">Jumlah</label>
              <div class="input-group">
                <span class="input-group-text">Rp</span>
                <input type="number" step="1" min="1" max="{{ $tagihan->outstanding() }}"
                       id="amount" name="amount" class="form-control"
                       value="{{ old('amount', (int) $tagihan->outstanding()) }}" required>
              </div>
              <div class="form-hint">Sisa tagihan Rp {{ number_format($tagihan->outstanding(), 0, ',', '.') }}</div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="method">Metode</label>
              <select id="method" name="method" class="form-select">
                <option value="tunai">Tunai</option>
                <option value="debit">Kartu Debit</option>
                <option value="kredit">Kartu Kredit</option>
                <option value="qris">QRIS</option>
                <option value="transfer">Transfer Bank</option>
              </select>
            </div>
            <div class="mb-0">
              <label class="form-label" for="note">Catatan</label>
              <input type="text" id="note" name="note" class="form-control" placeholder="Opsional">
            </div>
          </div>
          <div class="card-footer">
            <button class="btn btn-success w-100">Catat Pembayaran</button>
          </div>
        </form>
      </div>
    @endif
  </div>

  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Rincian biaya</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr><th>Uraian</th><th class="text-end">Jumlah</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th><th>Waktu</th></tr>
          </thead>
          <tbody>
            @forelse ($tagihan->chargeLines as $baris)
              <tr>
                <td>{{ $baris->description }}</td>
                <td class="text-end">{{ rtrim(rtrim($baris->quantity, '0'), '.') }}</td>
                <td class="text-end">Rp {{ number_format((float) $baris->unit_price, 0, ',', '.') }}</td>
                <td class="text-end">Rp {{ number_format((float) $baris->amount, 0, ',', '.') }}</td>
                <td class="text-secondary small">{{ $baris->charged_at->format('d-m-Y H:i') }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-secondary py-3">Belum ada biaya tercatat.</td></tr>
            @endforelse
          </tbody>
          <tfoot>
            <tr class="fw-bold">
              <td colspan="3" class="text-end">Total</td>
              <td class="text-end">Rp {{ number_format((float) $tagihan->total_amount, 0, ',', '.') }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    @if ($tagihan->status === 'terbuka')
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Tambahan &amp; potongan biaya</h3></div>
        <div class="card-body">
          <form method="POST" action="{{ route('tagihan.penyesuaian.simpan', $tagihan) }}" class="row g-2">
            @csrf
            <div class="col-6 col-md-3">
              <label class="form-label">Jenis</label>
              <select name="kind" class="form-select" required>
                <option value="tambahan">Tambahan biaya</option>
                <option value="potongan">Potongan biaya</option>
              </select>
            </div>
            <div class="col-6 col-md-4"><label class="form-label">Keterangan</label><input type="text" name="description" class="form-control" maxlength="100" placeholder="mis. Ambulans" required></div>
            <div class="col-6 col-md-3">
              <label class="form-label">Nilai (Rp)</label>
              <input type="number" name="amount" class="form-control" min="1" step="1" required>
              <small class="text-secondary">Isi angka positif; potongan disimpan sebagai pengurang.</small>
            </div>
            <div class="col-12"><label class="form-label">Alasan</label><input type="text" name="reason" class="form-control" maxlength="1000"></div>
            <div class="col-12"><button class="btn btn-primary">Simpan Penyesuaian</button></div>
          </form>
        </div>
      </div>
    @endif

    @can('piutang_pasien')
      @if ($tagihan->status === 'terbuka' && $tagihan->isPatientPayable() && $tagihan->outstanding() > 0 && ! $tagihan->patientReceivable)
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Jadikan sisa tagihan sebagai piutang pasien</h3></div>
          <div class="card-body">
            <p class="text-secondary small">
              Dipakai saat pasien pulang tanpa melunasi. Sisa Rp {{ number_format($tagihan->outstanding(), 0, ',', '.') }}
              akan dicatat sebagai utang dengan jatuh tempo; uang muka yang dibayar sekarang langsung mengurangi sisanya.
            </p>
            <form method="POST" action="{{ route('piutang-pasien.simpan', $tagihan) }}" class="row g-2">
              @csrf
              <div class="col-6 col-md-3"><label class="form-label">Jatuh Tempo</label><input type="date" name="due_date" class="form-control" required></div>
              <div class="col-6 col-md-3"><label class="form-label">Uang Muka (Rp)</label><input type="number" name="down_payment" class="form-control" min="0" step="1" value="0"></div>
              <div class="col-6 col-md-3">
                <label class="form-label">Metode Uang Muka</label>
                <select name="down_payment_method" class="form-select">
                  <option value="tunai">Tunai</option>
                  <option value="debit">Debit</option>
                  <option value="kredit">Kredit</option>
                  <option value="qris">QRIS</option>
                  <option value="transfer">Transfer</option>
                </select>
              </div>
              <div class="col-12"><label class="form-label">Catatan</label><input type="text" name="note" class="form-control" maxlength="1000"></div>
              <div class="col-12"><button class="btn btn-primary">Catat Piutang</button></div>
            </form>
          </div>
        </div>
      @endif

      @if ($tagihan->patientReceivable)
        @php($piutang = $tagihan->patientReceivable)
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Piutang pasien</h3></div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-6 col-md-3"><div class="text-secondary small">Pokok</div><div>Rp {{ number_format((float) $piutang->principal_amount, 0, ',', '.') }}</div></div>
              <div class="col-6 col-md-3"><div class="text-secondary small">Sisa</div><div>Rp {{ number_format($piutang->outstanding(), 0, ',', '.') }}</div></div>
              <div class="col-6 col-md-3">
                <div class="text-secondary small">Jatuh Tempo</div>
                <div>
                  {{ $piutang->due_date->format('d-m-Y') }}
                  @if ($piutang->isOverdue())
                    <span class="badge bg-red-lt">terlambat</span>
                  @endif
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-secondary small">Status</div>
                <div>
                  @if ($piutang->isCancelled())
                    <span class="badge bg-secondary-lt">dibatalkan</span>
                  @elseif ($piutang->isSettled())
                    <span class="badge bg-green-lt">lunas</span>
                  @else
                    <span class="badge bg-yellow-lt">belum lunas</span>
                  @endif
                </div>
              </div>
            </div>
            @if ($piutang->note)
              <div class="text-secondary small mt-2">{{ $piutang->note }}</div>
            @endif
          </div>
        </div>
      @endif
    @endcan
    @if ($tagihan->manualAdjustments->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Riwayat penyesuaian</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Jenis</th><th>Keterangan</th><th class="text-end">Nilai</th><th>Status</th><th class="w-1"></th></tr></thead>
            <tbody>
              @foreach ($tagihan->manualAdjustments as $penyesuaian)
                <tr>
                  <td>
                    <span class="badge bg-{{ $penyesuaian->kind === 'potongan' ? 'orange' : 'blue' }}-lt">{{ $penyesuaian->kind }}</span>
                  </td>
                  <td>
                    {{ $penyesuaian->description }}
                    @if ($penyesuaian->reason)
                      <div class="text-secondary small">{{ $penyesuaian->reason }}</div>
                    @endif
                  </td>
                  <td class="text-end">Rp {{ number_format((float) $penyesuaian->amount, 0, ',', '.') }}</td>
                  <td>
                    @if ($penyesuaian->isVoid())
                      <span class="badge bg-secondary-lt" title="{{ $penyesuaian->void_reason }}">dibatalkan</span>
                    @else
                      <span class="badge bg-green-lt">berlaku</span>
                    @endif
                  </td>
                  <td>
                    @if (! $penyesuaian->isVoid() && $tagihan->status === 'terbuka')
                      <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#batal-penyesuaian-{{ $penyesuaian->id }}">Batalkan</button>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>

      @foreach ($tagihan->manualAdjustments->where('voided_at', null) as $penyesuaian)
        <div class="modal fade" id="batal-penyesuaian-{{ $penyesuaian->id }}" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('tagihan.penyesuaian.batal', $penyesuaian) }}">
              @csrf
              <div class="modal-header"><h5 class="modal-title">Batalkan: {{ $penyesuaian->description }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
              <div class="modal-body"><label class="form-label">Alasan pembatalan</label><textarea name="alasan" class="form-control" minlength="5" required></textarea></div>
              <div class="modal-footer"><button type="submit" class="btn btn-danger">Batalkan</button></div>
            </form>
          </div>
        </div>
      @endforeach
    @endif
    @if ($tagihan->payments->isNotEmpty())
      <div class="card">
        <div class="card-header"><h3 class="card-title">Riwayat pembayaran</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr><th>No. Pembayaran</th><th>Metode</th><th class="text-end">Jumlah</th><th>Kasir</th><th>Waktu</th><th class="w-1"></th></tr>
            </thead>
            <tbody>
              @foreach ($tagihan->payments as $p)
                <tr class="{{ $p->isVoided() ? 'opacity-50' : '' }}">
                  <td class="font-monospace small">{{ $p->payment_number }}</td>
                  <td>{{ \App\Modules\Billing\Models\Payment::methodLabel($p->method) }}</td>
                  <td class="text-end">Rp {{ number_format((float) $p->amount, 0, ',', '.') }}</td>
                  <td>{{ $p->received_by_name ?? '—' }}</td>
                  <td class="text-secondary small">{{ $p->paid_at->format('d-m-Y H:i') }}</td>
                  <td>
                    @if ($p->isVoided())
                      <span class="badge bg-red-lt">dibatalkan</span>
                    @else
                      <button class="btn btn-sm btn-ghost-danger" data-bs-toggle="modal" data-bs-target="#batal-bayar-{{ $p->id }}">Batalkan</button>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif
  </div>
</div>

@foreach ($tagihan->payments as $p)
  @if (! $p->isVoided())
    <div class="modal fade" id="batal-bayar-{{ $p->id }}" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('tagihan.pembayaran.batal', $p) }}">
          @csrf
          <div class="modal-header">
            <h5 class="modal-title">Batalkan pembayaran</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-secondary small">
              {{ $p->payment_number }} · Rp {{ number_format((float) $p->amount, 0, ',', '.') }}
            </p>
            <label class="form-label" for="alasan-{{ $p->id }}">Alasan pembatalan</label>
            <textarea id="alasan-{{ $p->id }}" name="alasan" class="form-control" rows="2"
                      required minlength="5" placeholder="Mis. salah input jumlah"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Tutup</button>
            <button type="submit" class="btn btn-danger">Batalkan</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endforeach

@endsection
