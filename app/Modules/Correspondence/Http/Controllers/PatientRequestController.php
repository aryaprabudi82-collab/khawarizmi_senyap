<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\PatientRequest;
use App\Modules\Correspondence\Models\PropertyHandover;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\PatientRequestService;
use App\Modules\Correspondence\Services\PropertyHandoverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Hak pasien: permintaan (privasi, perlindungan, rohani, second opinion,
 * cuti) dan serah terima barang/anggota tubuh.
 *
 * Satu layar untuk enam kode Khanza, digerbangi
 * surat_permohonan_privasi sebagai umbrella.
 */
class PatientRequestController
{
    public function __construct(
        private readonly PatientRequestService $permintaan,
        private readonly PropertyHandoverService $serahTerima,
    ) {}

    public function index(): View
    {
        return view('correspondence::hak-pasien.index', [
            // Yang belum dijawab ditampilkan TERPISAH dan di atas. Daftar
            // riwayat biasa mengurut dari yang terbaru, dan itu justru
            // menyembunyikan permintaan lama yang terlantar.
            'tertunggak' => $this->permintaan->outstanding(),
            'riwayat' => PatientRequest::query()->latest('requested_at')->limit(50)->get(),
            'masihDititipkan' => $this->serahTerima->stillHeld(),
            'serahTerima' => PropertyHandover::query()->with('settles')->latest('occurred_at')->limit(50)->get(),
            'jenis' => PatientRequest::LABEL,
            'hubungan' => PatientRequest::HUBUNGAN,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(PatientRequest::JENIS)],
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'requester_name' => ['required', 'string', 'max:150'],
            'requester_relationship' => ['required', Rule::in(PatientRequest::HUBUNGAN)],
            'detail' => ['required', 'string', 'max:2000'],
            'leave_starts_at' => ['nullable', 'date'],
            'leave_ends_at' => ['nullable', 'date'],
        ], [], [
            'request_type' => 'jenis permintaan', 'patient_name' => 'nama pasien',
            'requester_name' => 'nama peminta', 'requester_relationship' => 'hubungan peminta',
            'detail' => 'isi permintaan',
        ]);

        // Kolom tanggal cuti dikosongkan untuk jenis lain supaya string kosong
        // dari formulir tidak lolos sebagai tanggal.
        if ($data['request_type'] !== PatientRequest::JENIS_CUTI) {
            $data['leave_starts_at'] = null;
            $data['leave_ends_at'] = null;
        }

        try {
            $p = $this->permintaan->request($data, $request->user()->id);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$p->request_number} tercatat.");
    }

    public function respond(Request $request, PatientRequest $permintaan): RedirectResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:dipenuhi,ditolak'],
            'response_note' => ['nullable', 'string', 'max:2000'],
        ], [], ['keputusan' => 'jawaban', 'response_note' => 'keterangan']);

        try {
            if ($data['keputusan'] === 'ditolak') {
                $this->permintaan->decline(
                    $permintaan,
                    (string) ($data['response_note'] ?? ''),
                    $request->user()->name,
                    $request->user()->id
                );
            } else {
                $this->permintaan->fulfil(
                    $permintaan,
                    $request->user()->name,
                    $request->user()->id,
                    $data['response_note'] ?? null
                );
            }
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} dijawab.");
    }

    public function storeHandover(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'arah' => ['required', 'in:dititipkan,diserahkan'],
            'settles_handover_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\PropertyHandover,id'],
            'kind' => ['required', Rule::in(PropertyHandover::JENIS)],
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:1000'],
            'quantity' => ['nullable', 'string', 'max:50'],
            'condition' => ['required', 'string', 'max:200'],
            'container_label' => ['nullable', 'string', 'max:100'],
            'counterparty_name' => ['required', 'string', 'max:150'],
            'counterparty_relationship' => ['required', Rule::in(PropertyHandover::HUBUNGAN)],
            'counterparty_id_number' => ['nullable', 'string', 'max:30'],
            'counterparty_phone' => ['nullable', 'string', 'max:30'],
            'counterparty_address' => ['nullable', 'string', 'max:200'],
            'officer_name' => ['required', 'string', 'max:150'],
        ], [], [
            'kind' => 'jenis', 'patient_name' => 'nama pasien', 'description' => 'uraian',
            'condition' => 'kondisi', 'container_label' => 'label wadah',
            'counterparty_name' => 'nama pihak kedua', 'counterparty_relationship' => 'hubungan',
            'officer_name' => 'nama petugas',
        ]);

        $arah = $data['arah'];
        $melunasiId = $data['settles_handover_id'] ?? null;
        unset($data['arah'], $data['settles_handover_id']);

        try {
            if ($arah === PropertyHandover::ARAH_DITITIPKAN) {
                $catatan = $this->serahTerima->receive($data, $request->user()->id);
            } else {
                $melunasi = $melunasiId ? PropertyHandover::query()->find($melunasiId) : null;
                $catatan = $this->serahTerima->hand($data, $request->user()->id, $melunasi);
            }
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Serah terima {$catatan->handover_number} tercatat.");
    }
}
