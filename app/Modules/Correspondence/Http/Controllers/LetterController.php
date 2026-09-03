<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\IncomingLetter;
use App\Modules\Correspondence\Models\OutgoingLetter;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\LetterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LetterController
{
    public function __construct(private readonly LetterService $letters) {}

    public function index(): View
    {
        return view('correspondence::surat.index', [
            'masuk' => IncomingLetter::query()->latest('received_at')->limit(50)->get(),
            'keluar' => OutgoingLetter::query()->latest('created_at')->limit(50)->get(),
        ]);
    }

    public function storeIncoming(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference_number' => ['nullable', 'string', 'max:60'],
            'sender' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:255'],
            'classification' => ['required', 'in:biasa,penting,rahasia,segera'],
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['reference_number' => 'nomor surat', 'sender' => 'pengirim', 'subject' => 'perihal', 'classification' => 'sifat', 'received_at' => 'tanggal diterima']);

        $surat = $this->letters->recordIncoming($data, $request->user()->id);

        return back()->with('sukses', "Surat masuk {$surat->letter_number} tercatat.");
    }

    public function disposition(Request $request, IncomingLetter $surat): RedirectResponse
    {
        $data = $request->validate(['forwarded_to' => ['required', 'string', 'max:150']], [], ['forwarded_to' => 'diteruskan ke']);

        try {
            $this->letters->disposition($surat, $data['forwarded_to']);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat {$surat->letter_number} didisposisikan ke {$data['forwarded_to']}.");
    }

    public function archiveIncoming(IncomingLetter $surat): RedirectResponse
    {
        try {
            $this->letters->archiveIncoming($surat);
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
            'classification' => ['required', 'in:biasa,penting,rahasia,segera'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['recipient' => 'tujuan', 'subject' => 'perihal', 'classification' => 'sifat']);

        $surat = $this->letters->draftOutgoing($data, $request->user()->id);

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
