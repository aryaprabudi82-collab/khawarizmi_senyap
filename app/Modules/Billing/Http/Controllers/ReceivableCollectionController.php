<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Models\PatientReceivable;
use App\Modules\Billing\Models\ReceivableCollection;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\ReceivableCollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Penagihan & laporan piutang pasien (domain K item D).
 *
 * Verifikasi catatan penagihan digerbangi TERPISAH: catatan "sudah
 * ditagih, pasien menolak" tidak boleh diverifikasi oleh penagihnya
 * sendiri, karena catatan itulah yang jadi dasar menghapus piutang.
 */
class ReceivableCollectionController
{
    public function __construct(private readonly ReceivableCollectionService $penagihan) {}

    public function index(Request $request): View
    {
        $jenisRawat = $request->query('jenis_rawat') ?: null;

        if ($jenisRawat !== null && ! in_array($jenisRawat, ['ralan', 'ranap'], true)) {
            $jenisRawat = null;
        }

        return view('billing::penagihan.index', [
            'jenisRawat' => $jenisRawat,
            'daftarHasil' => ReceivableCollection::HASIL,
            'daftarKanal' => ReceivableCollection::KANAL,

            'terutang' => $this->penagihan->outstanding($jenisRawat),
            'perPenjamin' => $this->penagihan->byPayer($jenisRawat),
            'perBulan' => $this->penagihan->monthlyByPayer($jenisRawat),
            'umur' => $this->penagihan->aging($jenisRawat),
            'belumDitagih' => $this->penagihan->neverContacted($jenisRawat),
            'menungguVerifikasi' => $this->penagihan->pendingValidation(),
        ]);
    }

    public function store(Request $request, PatientReceivable $piutang): RedirectResponse
    {
        $data = $request->validate([
            'contacted_on' => ['required', 'date'],
            'channel' => ['required', Rule::in(ReceivableCollection::KANAL)],
            'outcome' => ['required', Rule::in(array_keys(ReceivableCollection::HASIL))],
            'promised_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->penagihan->contact($piutang, $data, $request->user()?->id, $request->user()?->name);
        } catch (BillingException $e) {
            return back()->withInput()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('status', 'Upaya penagihan tercatat, menunggu verifikasi penyelia.');
    }

    public function validateContact(Request $request, ReceivableCollection $penagihan): RedirectResponse
    {
        try {
            $this->penagihan->validateContact($penagihan, $request->user()?->id);
        } catch (BillingException $e) {
            return back()->withErrors(['validated_at' => $e->getMessage()]);
        }

        return back()->with('status', 'Catatan penagihan diverifikasi.');
    }
}
