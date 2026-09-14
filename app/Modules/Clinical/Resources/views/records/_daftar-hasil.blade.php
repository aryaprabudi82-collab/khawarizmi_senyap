{{--
  Panel hasil penunjang — dipakai lab, radiologi, dan PA.

  DUA BAGIAN, DAN PEMISAHANNYA PENTING:

  1. PERMINTAAN YANG BELUM BERHASIL. Order yang sudah diminta tapi hasilnya
     belum keluar adalah informasi klinis tersendiri — dokter perlu tahu ia
     sedang menunggu sesuatu. Menyembunyikannya membuat layar terlihat
     seolah tidak ada pemeriksaan sama sekali, dan asesmen ditulis tanpa
     menunggu hasil yang sebenarnya sudah dalam perjalanan.

  2. HASIL, berikut RENTANG RUJUKANNYA. Angka hasil tanpa rentangnya tidak
     bisa dinilai siapa pun: 13,5 bermakna berbeda untuk hemoglobin dan
     untuk leukosit. Nilai di luar rentang ditandai merah, karena yang
     membaca sedang di tengah pemeriksaan dan tidak akan membandingkan
     angka satu per satu.

  Parameter: $pesanan, $hasil, $kosong
--}}
@php
  $belumBerhasil = $pesanan->filter(fn ($p) => $p->resulted_at === null);
@endphp

@if ($pesanan->isEmpty() && $hasil->isEmpty())
  <div class="text-secondary text-center py-3"><em>{{ $kosong }}</em></div>
@else

  @if ($belumBerhasil->isNotEmpty())
    <div class="mb-2">
      <div class="small text-uppercase text-secondary mb-1">Menunggu hasil</div>
      @foreach ($belumBerhasil as $p)
        <div class="d-flex justify-content-between align-items-center border-bottom py-1">
          <div>
            <a href="{{ route('order.show', [$p->category, $p->order_id]) }}" class="font-monospace small">
              {{ $p->order_number }}
            </a>
            <div class="text-secondary small">
              {{ $p->requesting_practitioner_name ?? '—' }} ·
              {{ $p->requested_at ? \Carbon\Carbon::parse($p->requested_at)->format('d-m H:i') : '—' }}
            </div>
          </div>
          <span class="badge bg-yellow-lt">{{ $p->status }}</span>
        </div>
      @endforeach
    </div>
  @endif

  @if ($hasil->isNotEmpty())
    <div class="table-responsive">
      <table class="table table-sm table-vcenter mb-0">
        <thead>
          <tr>
            <th>Pemeriksaan</th>
            <th class="text-end">Hasil</th>
            <th>Rujukan</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($hasil as $h)
            <tr>
              <td>
                {{ $h->test_name }}
                <div class="text-secondary small font-monospace">{{ $h->test_code }}</div>
              </td>
              <td class="text-end {{ $h->is_abnormal ? 'text-danger fw-bold' : '' }}">
                @if ($h->result_numeric !== null)
                  {{ rtrim(rtrim((string) $h->result_numeric, '0'), '.') }}
                  <span class="text-secondary small">{{ $h->unit }}</span>
                @else
                  {{ $h->result_text ?? '—' }}
                @endif
                @if ($h->is_abnormal)
                  <div class="small">di luar rentang</div>
                @endif
              </td>
              <td class="text-secondary small">
                @if ($h->reference_low !== null || $h->reference_high !== null)
                  {{ rtrim(rtrim((string) $h->reference_low, '0'), '.') }}&ndash;{{ rtrim(rtrim((string) $h->reference_high, '0'), '.') }}
                @else
                  {{ $h->reference_text ?? '—' }}
                @endif
              </td>
              <td>
                <a href="{{ route('order.show', [$h->category ?? 'lab', $h->order_id]) }}"
                   class="btn btn-sm btn-ghost-primary">Lihat</a>
              </td>
            </tr>
            @if ($h->result_notes)
              <tr>
                <td colspan="4" class="text-secondary small pt-0">Catatan: {{ $h->result_notes }}</td>
              </tr>
            @endif
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
@endif
