<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\LabRadiologyOrder;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Models\OrderItemImage;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk konteks order.
 *
 * Satu siklus untuk lab dan radiologi: diminta -> diproses -> hasil-tersedia
 * -> selesai (terverifikasi). Hasil terkunci begitu diverifikasi, sejalan
 * dengan pola assessment final di konteks clinical - ralat sesudahnya
 * memerlukan pembatalan lalu permintaan ulang, bukan menyunting diam-diam.
 */
class OrderService
{
    public function __construct(private readonly RegistrationContext $registrations) {}

    /**
     * Membuka order untuk satu kunjungan.
     *
     * Boleh dibuat kosong lalu diisi lewat addItem() di layar order — sama
     * seperti resep, supaya tombol "Order Lab"/"Order Radiologi" dari layar
     * pemeriksaan klinis cukup satu klik tanpa perlu memilih pemeriksaan
     * terlebih dulu. startProcessing() menolak order yang masih kosong.
     *
     * @param  list<int>  $testIds
     * @throws OrderException
     */
    public function create(
        int $registrationId,
        string $category,
        array $testIds = [],
        ?string $clinicalNotes = null,
        ?User $actor = null,
    ): LabRadiologyOrder {
        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new OrderException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        // Panggilan tanpa pemeriksaan eksplisit (tombol "Order Lab"/"Order
        // Radiologi" di layar klinis) bersifat idempoten: melanjutkan order
        // yang masih diminta, bukan membuat yang baru setiap kali diklik.
        // Permintaan dengan daftar pemeriksaan eksplisit selalu membuat order
        // baru, supaya permintaan susulan (add-on test) tetap bisa dicatat.
        if ($testIds === []) {
            $existing = LabRadiologyOrder::query()
                ->where('registration_id', $registrationId)
                ->where('category', $category)
                ->where('status', LabRadiologyOrder::STATUS_DIMINTA)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $tests = $testIds === []
            ? collect()
            : TestCatalog::query()->whereIn('id', $testIds)->where('is_active', true)->get();

        if ($tests->count() !== count(array_unique($testIds))) {
            throw new OrderException('Ada pemeriksaan yang tidak ditemukan atau sudah tidak aktif.');
        }

        $kategoriSalah = $tests->firstWhere('category', '!=', $category);

        if ($kategoriSalah !== null) {
            throw new OrderException("{$kategoriSalah->name} bukan pemeriksaan {$category}.");
        }

        return DB::transaction(function () use ($kunjungan, $category, $tests, $clinicalNotes, $actor): LabRadiologyOrder {
            $order = LabRadiologyOrder::query()->create([
                'order_number' => $this->allocateNumber($category),
                'registration_id' => $kunjungan->id,
                'patient_id' => $kunjungan->patient_id,
                'registration_number' => $kunjungan->registration_number,
                'patient_mrn' => $kunjungan->patient_mrn,
                'patient_name' => $kunjungan->patient_name,
                'unit_name' => $kunjungan->unit_name,
                'category' => $category,
                'requesting_practitioner_id' => $kunjungan->practitioner_id,
                'requesting_practitioner_name' => $kunjungan->practitioner_name,
                'clinical_notes' => $clinicalNotes,
                'status' => LabRadiologyOrder::STATUS_DIMINTA,
                'requested_at' => now(),
                'created_by' => $actor?->id,
            ]);

            foreach ($tests as $test) {
                $order->items()->create([
                    'test_id' => $test->id,
                    // Disalin saat order dibuat: rentang rujukan dan harga di
                    // katalog bisa direvisi kemudian, hasil yang sudah tercatat
                    // tidak boleh ikut berubah maknanya.
                    'test_code' => $test->code,
                    'test_name' => $test->name,
                    'result_type' => $test->result_type,
                    'unit' => $test->unit,
                    'reference_low' => $test->reference_low,
                    'reference_high' => $test->reference_high,
                    'reference_text' => $test->reference_text,
                    'unit_price' => $test->price,
                ]);
            }

            return $order;
        });
    }

    /**
     * @throws OrderException
     */
    public function addItem(LabRadiologyOrder $order, int $testId): OrderItem
    {
        if (! $order->isEditable()) {
            throw new OrderException('Order ini tidak bisa diubah lagi. Status: ' . LabRadiologyOrder::statusLabel($order->status) . '.');
        }

        $test = TestCatalog::query()->where('is_active', true)->find($testId)
            ?? throw new OrderException('Pemeriksaan tidak ditemukan atau sudah tidak aktif.');

        if ($test->category !== $order->category) {
            throw new OrderException("{$test->name} bukan pemeriksaan " . LabRadiologyOrder::categoryLabel($order->category) . '.');
        }

        if ($order->items()->where('test_id', $test->id)->exists()) {
            throw new OrderException("{$test->name} sudah ada di order ini.");
        }

        return $order->items()->create([
            'test_id' => $test->id,
            'test_code' => $test->code,
            'test_name' => $test->name,
            'result_type' => $test->result_type,
            'unit' => $test->unit,
            'reference_low' => $test->reference_low,
            'reference_high' => $test->reference_high,
            'reference_text' => $test->reference_text,
            'unit_price' => $test->price,
        ]);
    }

    /**
     * @throws OrderException
     */
    public function removeItem(OrderItem $item): void
    {
        if (! $item->order->isEditable()) {
            throw new OrderException('Order ini tidak bisa diubah lagi.');
        }

        $item->delete();
    }

    /**
     * Sampel mulai dikerjakan / pencitraan dimulai.
     *
     * @throws OrderException
     */
    public function startProcessing(LabRadiologyOrder $order): LabRadiologyOrder
    {
        if ($order->status !== LabRadiologyOrder::STATUS_DIMINTA) {
            throw new OrderException('Hanya order berstatus diminta yang bisa mulai diproses.');
        }

        if ($order->items()->count() === 0) {
            throw new OrderException('Order belum memiliki pemeriksaan. Tambahkan minimal satu sebelum diproses.');
        }

        $order->update(['status' => LabRadiologyOrder::STATUS_DIPROSES, 'processed_at' => now()]);

        return $order->refresh();
    }

    /**
     * Mencatat hasil satu item pemeriksaan.
     *
     * Abnormalitas dihitung terhadap rentang rujukan yang disalin saat order
     * dibuat, bukan terhadap katalog saat ini - konsisten dengan prinsip yang
     * sama di clinical.observations.
     *
     * @throws OrderException
     */
    public function enterResult(
        OrderItem $item,
        ?float $numeric = null,
        ?string $text = null,
        ?string $notes = null,
        ?User $actor = null,
    ): OrderItem {
        $order = $item->order;

        if (in_array($order->status, [LabRadiologyOrder::STATUS_SELESAI, LabRadiologyOrder::STATUS_BATAL], true)) {
            throw new OrderException('Order ini sudah ' . LabRadiologyOrder::statusLabel($order->status) . ', hasil tidak bisa diubah.');
        }

        $abnormal = match ($item->result_type) {
            TestCatalog::RESULT_KUANTITATIF => $this->isNumericAbnormal($item, $numeric),
            TestCatalog::RESULT_KUALITATIF => $this->isQualitativeAbnormal($item, $text),
            default => false,
        };

        return DB::transaction(function () use ($item, $numeric, $text, $notes, $abnormal, $actor, $order): OrderItem {
            $item->update([
                'result_numeric' => $numeric,
                'result_text' => $text,
                'result_notes' => $notes,
                'is_abnormal' => $abnormal,
                'entered_by' => $actor?->id,
                'entered_by_name' => $actor?->name,
                'entered_at' => now(),
            ]);

            $this->syncOrderStatusAfterResult($order->refresh());

            return $item->refresh();
        });
    }

    public function attachImage(OrderItem $item, string $filePath, ?string $caption = null, ?User $actor = null): OrderItemImage
    {
        return OrderItemImage::query()->create([
            'order_item_id' => $item->id,
            'file_path' => $filePath,
            'caption' => $caption,
            'uploaded_by' => $actor?->id,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Mengunci hasil. Perubahan sesudahnya memerlukan pembatalan lalu
     * permintaan ulang, bukan penyuntingan diam-diam.
     *
     * @throws OrderException
     */
    public function verify(LabRadiologyOrder $order, ?User $actor = null): LabRadiologyOrder
    {
        if (! $order->isVerifiable()) {
            throw new OrderException(
                'Hanya order dengan hasil lengkap yang bisa diverifikasi. Status sekarang: '
                . LabRadiologyOrder::statusLabel($order->status) . '.'
            );
        }

        $order->update([
            'status' => LabRadiologyOrder::STATUS_SELESAI,
            'verified_at' => now(),
            'verified_by' => $actor?->id,
            'verified_by_name' => $actor?->name,
        ]);

        return $order->refresh();
    }

    /**
     * @throws OrderException
     */
    public function cancel(LabRadiologyOrder $order, string $reason): LabRadiologyOrder
    {
        if ($order->status === LabRadiologyOrder::STATUS_SELESAI) {
            throw new OrderException('Order yang sudah selesai dan terverifikasi tidak bisa dibatalkan.');
        }

        if ($order->status === LabRadiologyOrder::STATUS_BATAL) {
            throw new OrderException('Order ini sudah dibatalkan.');
        }

        $order->update([
            'status' => LabRadiologyOrder::STATUS_BATAL,
            'cancellation_reason' => $reason,
        ]);

        return $order->refresh();
    }

    /** Order pindah ke 'hasil-tersedia' otomatis begitu seluruh item terisi. */
    private function syncOrderStatusAfterResult(LabRadiologyOrder $order): void
    {
        if ($order->status === LabRadiologyOrder::STATUS_SELESAI) {
            return;
        }

        $items = $order->items;
        $semuaTerisi = $items->isNotEmpty() && $items->every(fn (OrderItem $i) => $i->hasResult());

        if ($semuaTerisi && $order->status !== LabRadiologyOrder::STATUS_HASIL_TERSEDIA) {
            $order->update(['status' => LabRadiologyOrder::STATUS_HASIL_TERSEDIA, 'resulted_at' => now()]);
        } elseif (! $semuaTerisi && $order->status === LabRadiologyOrder::STATUS_DIMINTA) {
            // Hasil parsial mulai masuk: order dianggap sedang diproses,
            // walau petugas tidak sempat menekan "mulai proses" secara manual.
            $order->update(['status' => LabRadiologyOrder::STATUS_DIPROSES, 'processed_at' => $order->processed_at ?? now()]);
        }
    }

    private function isNumericAbnormal(OrderItem $item, ?float $value): bool
    {
        if ($value === null || $item->reference_low === null || $item->reference_high === null) {
            return false;
        }

        return $value < (float) $item->reference_low || $value > (float) $item->reference_high;
    }

    private function isQualitativeAbnormal(OrderItem $item, ?string $value): bool
    {
        if ($value === null || $item->reference_text === null) {
            return false;
        }

        return mb_strtolower(trim($value)) !== mb_strtolower(trim($item->reference_text));
    }

    private function allocateNumber(string $category): string
    {
        $kode = match ($category) {
            LabRadiologyOrder::CATEGORY_LAB => 'LAB',
            LabRadiologyOrder::CATEGORY_RADIOLOGI => 'RAD',
            LabRadiologyOrder::CATEGORY_PA => 'PA',
            default => strtoupper($category),
        };
        $prefix = $kode . now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO orders.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = orders.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return $prefix . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
