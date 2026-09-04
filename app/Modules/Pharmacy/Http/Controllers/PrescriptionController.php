<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\PrescriptionItem;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Pharmacy\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrescriptionController
{
    public function __construct(
        private readonly PrescriptionService $prescriptions,
        private readonly StockLedger $stock,
    ) {}

    /**
     * Antrean farmasi: resep yang menunggu telaah dan yang siap diserahkan.
     *
     * permintaan_resep_pulang (domain D item 4) tidak jadi layar sendiri —
     * cukup filter ?kind=pulang di layar yang sama, kolom prescriptions.kind
     * sudah ada sejak migrasi 2026_09_15. resep_dokter (Daftar Resep Dokter)
     * juga tidak jadi layar sendiri, sudah terpenuhi listing default di sini.
     */
    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $status = $request->query('status');
        $kind = $request->query('kind');

        $daftar = Prescription::query()
            ->whereDate('prescribed_at', $tanggal->toDateString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderByRaw("CASE status
                WHEN 'menunggu-telaah' THEN 1
                WHEN 'disetujui' THEN 2
                WHEN 'ditulis' THEN 3
                ELSE 4 END")
            ->orderBy('prescribed_at')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = Prescription::query()
            ->whereDate('prescribed_at', $tanggal->toDateString())
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return view('pharmacy::prescriptions.index', [
            'daftar' => $daftar,
            'ringkasan' => $ringkasan,
            'tanggal' => $tanggal,
            'status' => $status,
            'kind' => $kind,
        ]);
    }

    /**
     * Rincian satu resep.
     *
     * Layar yang sama melayani dua peran: dokter menyusun isinya selama masih
     * bisa diubah, apoteker menelaah dan menyerahkan setelah dikirim. Yang
     * membedakan apa yang muncul adalah status resep dan hak akses, bukan
     * dua layar terpisah yang isinya hampir sama.
     */
    public function show(Prescription $resep): View
    {
        return view('pharmacy::prescriptions.show', [
            'resep' => $resep->load(['items.drug', 'reviews']),
            'temuan' => $this->prescriptions->screen($resep),
            'lokasi' => StockLocation::query()->where('is_active', true)->orderBy('name')->get(),
            'stok' => $this->stokPerItem($resep),
        ]);
    }

    /** Membuka atau melanjutkan resep untuk satu kunjungan. */
    public function createForRegistration(Request $request, int $registrasi): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:rawat-jalan,pulang'],
        ]);

        try {
            $resep = $this->prescriptions->create($registrasi, $request->user(), $data['kind'] ?? Prescription::KIND_RAWAT_JALAN);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('resep.show', $resep);
    }

    public function storeItem(Request $request, Prescription $resep): RedirectResponse
    {
        $data = $request->validate([
            'drug_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'dosage_instruction' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'drug_id' => 'obat',
            'quantity' => 'jumlah',
            'dosage_instruction' => 'aturan pakai',
        ]);

        try {
            $this->prescriptions->addItem(
                prescription: $resep,
                drugId: $data['drug_id'],
                quantity: (float) $data['quantity'],
                dosageInstruction: $data['dosage_instruction'],
                note: $data['note'] ?? null,
            );
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Obat ditambahkan ke resep.');
    }

    public function destroyItem(PrescriptionItem $item): RedirectResponse
    {
        $resep = $item->prescription;

        try {
            $this->prescriptions->removeItem($item);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('resep.show', $resep)->with('sukses', 'Obat dikeluarkan dari resep.');
    }

    public function submit(Prescription $resep): RedirectResponse
    {
        try {
            $this->prescriptions->submit($resep);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Resep dikirim ke apoteker untuk ditelaah.');
    }

    public function review(Request $request, Prescription $resep): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:disetujui,ditolak'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['outcome' => 'hasil telaah', 'note' => 'catatan apoteker']);

        try {
            $this->prescriptions->review(
                prescription: $resep,
                outcome: $data['outcome'],
                note: $data['note'] ?? null,
                actor: $request->user(),
            );
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Telaah tersimpan: resep ' . $data['outcome'] . '.');
    }

    public function dispense(Request $request, Prescription $resep): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
        ], [], ['location_id' => 'depo penyerahan']);

        try {
            $this->prescriptions->dispense($resep, $data['location_id'], $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Obat diserahkan dan stok berkurang sesuai batch yang keluar.');
    }

    public function cancel(Request $request, Prescription $resep): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        try {
            $this->prescriptions->cancel($resep, $data['alasan'], $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('resep.index')->with('sukses', 'Resep dibatalkan.');
    }

    /** Pencarian obat untuk formulir peresepan. */
    public function searchDrugs(Request $request)
    {
        return Drug::query()
            ->search((string) $request->query('q', ''))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Drug $d) => [
                'id' => $d->id,
                'label' => $d->label(),
                'unit' => $d->unit,
                'price' => (float) $d->sell_price,
                'controlled' => $d->isControlled(),
            ]);
    }

    /** Stok tersedia per item, ditampilkan agar apoteker tahu sebelum menyerahkan. */
    private function stokPerItem(Prescription $resep): array
    {
        $depo = StockLocation::query()->where('code', 'DEPO-RJ')->value('id')
            ?? StockLocation::query()->value('id');

        if ($depo === null) {
            return [];
        }

        $hasil = [];

        foreach ($resep->items as $item) {
            $hasil[$item->id] = $this->stock->availableQuantity($item->drug_id, $depo);
        }

        return $hasil;
    }
}
