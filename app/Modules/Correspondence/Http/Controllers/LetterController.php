<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\IncomingLetter;
use App\Modules\Correspondence\Models\LetterClassification;
use App\Modules\Correspondence\Models\LetterDisposition;
use App\Modules\Correspondence\Models\LetterIndexTerm;
use App\Modules\Correspondence\Models\LetterLocation;
use App\Modules\Correspondence\Models\OutgoingLetter;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\LetterMasterService;
use App\Modules\Correspondence\Services\LetterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LetterController
{
    public function __construct(
        private readonly LetterService $letters,
        private readonly LetterMasterService $master,
    ) {}

    public function index(): View
    {
        return view('correspondence::surat.index', [
            'masuk' => IncomingLetter::query()->with('dispositions')->latest('received_at')->limit(50)->get(),
            'keluar' => OutgoingLetter::query()->latest('created_at')->limit(50)->get(),

            // Tunggakan ditampilkan terpisah: daftar terbaru menyembunyikan
            // surat lama yang belum dibalas dan disposisi yang lewat tenggat.
            'balasTerlambat' => $this->letters->overdueReplies(),
            'disposisiTerlambat' => $this->letters->overdueDispositions(),

            'klasifikasi' => LetterClassification::query()->where('is_active', true)->orderBy('code')->get(),
            'indeks' => LetterIndexTerm::query()->where('is_active', true)->orderBy('name')->get(),
            'penyimpanan' => $this->master->storableLocations(),
            'sifat' => IncomingLetter::SIFAT,
            'derajat' => IncomingLetter::DERAJAT,
        ]);
    }

    public function storeIncoming(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference_number' => ['nullable', 'string', 'max:60'],
            'sender' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:255'],

            // Dua sumbu, bukan satu: sifat menjawab seberapa terbatas surat
            // boleh dibaca, derajat menjawab seberapa cepat ia ditangani.
            'security' => ['required', Rule::in(IncomingLetter::SIFAT)],
            'urgency' => ['required', Rule::in(IncomingLetter::DERAJAT)],

            'classification_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\LetterClassification,id'],
            'received_at' => ['required', 'date'],
            'reply_status' => ['nullable', 'in:tidak-perlu,menunggu'],
            'reply_due_date' => ['nullable', 'date'],
            'attachment_note' => ['nullable', 'string', 'max:300'],
            'copy_to' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'reference_number' => 'nomor surat', 'sender' => 'pengirim', 'subject' => 'perihal',
            'security' => 'sifat', 'urgency' => 'derajat', 'received_at' => 'tanggal diterima',
            'reply_status' => 'status balasan', 'reply_due_date' => 'tenggat balas',
        ]);

        // Tenggat dikosongkan bila balasannya memang tidak ditunggu, supaya
        // string kosong dari formulir tidak lolos jadi tanggal.
        if (($data['reply_status'] ?? 'tidak-perlu') === IncomingLetter::BALAS_TIDAK_PERLU) {
            $data['reply_due_date'] = null;
        }

        try {
            $surat = $this->letters->recordIncoming($data, $request->user()->id);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat masuk {$surat->letter_number} tercatat.");
    }

    public function disposition(Request $request, IncomingLetter $surat): RedirectResponse
    {
        $data = $request->validate([
            'to_name' => ['required', 'string', 'max:150'],
            // Isi disposisi wajib: disposisi tanpa instruksi hanya
            // memindahkan kertas.
            'instruction' => ['required', 'string', 'max:1000'],
            'due_date' => ['nullable', 'date'],
            'index_term_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\LetterIndexTerm,id'],
        ], [], [
            'to_name' => 'diteruskan ke', 'instruction' => 'isi disposisi',
            'due_date' => 'tenggat', 'index_term_id' => 'indeks',
        ]);

        try {
            $this->letters->dispose($surat, $data + [
                'disposed_by_name' => $request->user()->name,
            ], $request->user()->id);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat {$surat->letter_number} didisposisikan ke {$data['to_name']}.");
    }

    public function completeDisposition(Request $request, LetterDisposition $disposisi): RedirectResponse
    {
        $data = $request->validate([
            'completion_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['completion_note' => 'keterangan penyelesaian']);

        try {
            $this->letters->completeDisposition($disposisi, $data['completion_note'] ?? null);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Disposisi ditandai selesai.');
    }

    public function markReplied(Request $request, IncomingLetter $surat): RedirectResponse
    {
        $data = $request->validate([
            'reply_letter_id' => ['required', 'integer', 'exists:App\Modules\Correspondence\Models\OutgoingLetter,id'],
        ], [], ['reply_letter_id' => 'surat balasan']);

        try {
            $this->letters->markReplied(
                $surat,
                OutgoingLetter::query()->findOrFail($data['reply_letter_id'])
            );
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat {$surat->letter_number} tercatat sudah dibalas.");
    }

    public function archiveIncoming(Request $request, IncomingLetter $surat): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\LetterLocation,id'],
        ], [], ['location_id' => 'lokasi arsip']);

        try {
            $this->letters->archiveIncoming(
                $surat,
                isset($data['location_id']) ? LetterLocation::query()->find($data['location_id']) : null
            );
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat {$surat->letter_number} diarsipkan.");
    }

    public function storeOutgoing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:255'],
            'security' => ['required', Rule::in(OutgoingLetter::SIFAT)],
            'urgency' => ['required', Rule::in(OutgoingLetter::DERAJAT)],
            'classification_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\LetterClassification,id'],
            'attachment_note' => ['nullable', 'string', 'max:300'],
            'copy_to' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'recipient' => 'tujuan', 'subject' => 'perihal',
            'security' => 'sifat', 'urgency' => 'derajat',
        ]);

        $klasifikasi = isset($data['classification_id'])
            ? LetterClassification::query()->find($data['classification_id'])
            : null;
        unset($data['classification_id']);

        try {
            $surat = $this->letters->draftOutgoing($data, $request->user()->id, $klasifikasi);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Draf surat {$surat->letter_number} tersimpan.");
    }

    public function send(OutgoingLetter $surat): RedirectResponse
    {
        try {
            $this->letters->send($surat);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat {$surat->letter_number} ditandai terkirim.");
    }
}
