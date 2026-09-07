<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca asesmen klinis dan pesanan diet, lewat kontrak yang diterbitkan
 * konteks clinical dan inpatient.
 *
 * HANYA ASESMEN YANG SUDAH DIFINALISASI yang boleh diambil untuk dikirim,
 * dan penyaringnya diletakkan di sini — di satu tempat — supaya tidak ada
 * alur kirim yang bisa melewatinya karena lupa. Asesmen yang masih draf
 * boleh berubah kapan saja; mengirimkannya berarti menyebarkan penilaian
 * klinis yang dokternya sendiri belum menyatakan selesai, dan fasilitas
 * lain tidak punya cara membedakannya dari yang sudah final.
 *
 * PESANAN DIET YANG DIHENTIKAN TETAP DIAMBIL. Diet yang dihentikan adalah
 * keputusan klinis, bukan ketiadaan data — menyaringnya keluar mengulang
 * kesalahan yang pernah terjadi pada laporan gizi domain J item E, ketika
 * penyaring yang tampak berhati-hati justru menghapus diet yang benar-benar
 * pernah dimasak.
 */
class AssessmentContext
{
    private const ASESMEN = 'clinical.v_assessment_summary';
    private const DIET = 'inpatient.v_diet_order';
    private const RANAP = 'inpatient.v_admission_summary';

    /** Asesmen final satu kunjungan, terbaru lebih dulu. */
    public function finalizedFor(int $registrationId): Collection
    {
        return DB::table(self::ASESMEN)
            ->where('registration_id', $registrationId)
            ->whereNotNull('finalized_at')
            ->orderByDesc('finalized_at')
            ->get();
    }

    /** Satu asesmen final menurut id-nya; null kalau masih draf. */
    public function finalizedAssessment(int $assessmentId): ?stdClass
    {
        return DB::table(self::ASESMEN)
            ->where('assessment_id', $assessmentId)
            ->whereNotNull('finalized_at')
            ->first();
    }

    /** Termasuk yang masih draf — dipakai memberi tahu alasan penolakan. */
    public function assessment(int $assessmentId): ?stdClass
    {
        return DB::table(self::ASESMEN)->where('assessment_id', $assessmentId)->first();
    }

    /**
     * Pesanan diet satu perawatan berikut konteks pasiennya.
     *
     * Diet melekat pada PERAWATAN (admission), sedangkan resource FHIR
     * melekat pada pasien dan kunjungan — jadi keduanya digabung di sini,
     * lewat dua kontrak terbitan, bukan dengan menyentuh tabelnya.
     */
    public function dietOrdersFor(int $admissionId): Collection
    {
        return DB::table(self::DIET . ' as d')
            ->join(self::RANAP . ' as a', 'a.admission_id', '=', 'd.admission_id')
            ->where('d.admission_id', $admissionId)
            ->orderBy('d.id')
            ->selectRaw('d.id AS diet_id, d.admission_id, d.diet_type, d.status,
                         d.start_date, d.end_date, d.ordered_by_name,
                         a.registration_id, a.patient_id, a.patient_name,
                         a.dpjp_practitioner_id, a.dpjp_name')
            ->get();
    }

    public function dietOrder(int $dietId): ?stdClass
    {
        return DB::table(self::DIET . ' as d')
            ->join(self::RANAP . ' as a', 'a.admission_id', '=', 'd.admission_id')
            ->where('d.id', $dietId)
            ->selectRaw('d.id AS diet_id, d.admission_id, d.diet_type, d.status,
                         d.start_date, d.end_date, d.ordered_by_name,
                         a.registration_id, a.patient_id, a.patient_name,
                         a.dpjp_practitioner_id, a.dpjp_name')
            ->first();
    }
}
