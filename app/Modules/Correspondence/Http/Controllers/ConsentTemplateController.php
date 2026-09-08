<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\ConsentTemplate;
use App\Modules\Correspondence\Models\PatientConsent;
use App\Modules\Correspondence\Models\RefusalReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master persetujuan: template penjelasan + daftar alasan penolakan.
 *
 * Satu layar untuk dua kode Khanza (template_persetujuan_penolakan_tindakan
 * dan master_menolak_anjuran_medis), digerbangi kode pertama sebagai
 * umbrella — pola yang sama dengan master data envlab dan farmasi.
 */
class ConsentTemplateController
{
    public function index(): View
    {
        return view('correspondence::persetujuan.template', [
            'template' => ConsentTemplate::query()->with('items')->orderBy('code')->orderByDesc('version')->get(),
            'alasan' => RefusalReason::query()->orderBy('code')->get(),
            'jenis' => PatientConsent::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Formulir menyediakan sebelas baris kosong; yang tidak diisi dibuang
        // dulu supaya baris kosong tidak jatuh sebagai galat validasi.
        $request->merge([
            'items' => array_values(array_filter(
                (array) $request->input('items', []),
                fn ($butir) => filled($butir['label'] ?? null) || filled($butir['body'] ?? null)
            )),
        ]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'consent_type' => ['required', Rule::in(PatientConsent::TYPES)],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.label' => ['required', 'string', 'max:100'],
            'items.*.body' => ['required', 'string', 'max:4000'],
            'items.*.is_required' => ['nullable', 'boolean'],
        ], [], [
            'code' => 'kode template', 'name' => 'nama template',
            'consent_type' => 'jenis persetujuan', 'estimated_cost' => 'perkiraan biaya',
            'items' => 'butir penjelasan',
        ]);

        DB::transaction(function () use ($data) {
            /*
             * Revisi melahirkan VERSI BARU, tidak menimpa yang lama. Versi
             * lama tetap tersimpan karena persetujuan yang sudah
             * ditandatangani menunjuk ke sana, dan menghapusnya membuat
             * pertanyaan "apa persisnya yang ditandatangani" tak terjawab.
             */
            $sebelumnya = ConsentTemplate::query()->where('code', $data['code'])->max('version');

            ConsentTemplate::query()
                ->where('code', $data['code'])
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $template = ConsentTemplate::query()->create([
                'code' => $data['code'],
                'version' => ($sebelumnya ?? 0) + 1,
                'name' => $data['name'],
                'consent_type' => $data['consent_type'],
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'note' => $data['note'] ?? null,
                'is_active' => true,
                'created_by' => request()->user()->id,
            ]);

            foreach (array_values($data['items']) as $urut => $butir) {
                $template->items()->create([
                    'position' => $urut + 1,
                    'label' => $butir['label'],
                    'body' => $butir['body'],
                    'is_required' => (bool) ($butir['is_required'] ?? true),
                ]);
            }
        });

        return back()->with('sukses', 'Template "'.$data['name'].'" tersimpan sebagai versi baru.');
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:correspondence.medical_advice_refusal_reasons,code'],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['code' => 'kode alasan', 'name' => 'nama alasan']);

        RefusalReason::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Alasan penolakan "'.$data['name'].'" ditambahkan.');
    }
}
