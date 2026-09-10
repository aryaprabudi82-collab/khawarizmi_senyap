<?php

namespace App\Modules\Philanthropy\Services;

use App\Modules\Philanthropy\Models\AssessmentCriterion;
use App\Modules\ReadinessCheck;

/**
 * Syarat kesiapan konteks philanthropy.
 *
 * DIPINDAH KE SINI DARI PLATFORM, dan itu bukan sekadar kerapian. Versi
 * pertama pemeriksaan kesiapan mengueri `philanthropy.assessment_criteria`
 * dari konteks platform — dan uji batas konteks TIDAK menangkapnya, karena
 * pemindainya mencari literal 'schema.tabel' yang diapit kutip sementara
 * nama tabelnya berada di tengah string SQL panjang.
 *
 * Pelanggarannya tetap nyata walau tidak tertangkap. Yang membuatnya salah
 * bukan aturannya, melainkan akibatnya: kalau kategori kriteria bertambah
 * suatu hari, daftarnya harus diperbarui di dua tempat — dan yang di
 * platform akan tertinggal tanpa ada yang tahu.
 */
class PhilanthropyReadiness implements ReadinessCheck
{
    public function readinessItems(): array
    {
        /*
         * TIDAK CUKUP MENGHITUNG BARIS TABELNYA. Delapan golongan asnaf
         * sudah diseed sejak migrasi karena ditetapkan di luar rumah sakit
         * (At-Taubah 60), jadi tabelnya TIDAK PERNAH kosong. Pemeriksaan
         * yang menghitung baris akan melaporkan "beres" padahal lima belas
         * kategori lainnya masih kosong dan pertanyaannya tidak akan muncul
         * di formulir survei sama sekali.
         */
        $kosong = AssessmentCriterion::kategoriKosong();

        // Asnaf tidak dihitung sebagai pekerjaan RSP UI: ia memang sudah
        // terisi, dan kalaupun kosong itu masalah pemasangan, bukan diskresi.
        $kosong = array_values(array_diff($kosong, [AssessmentCriterion::KATEGORI_ASNAF]));

        $total = count(AssessmentCriterion::KATEGORI) - 1;

        return [[
            'judul' => 'Kriteria kelayakan ZIS',
            'status' => $kosong === [] ? self::BERES : self::PERINGATAN,
            'akibat' => $kosong === []
                ? 'Seluruh kategori terisi.'
                : count($kosong).' dari '.$total.' kategori belum punya pilihan (golongan '
                  .'asnaf sudah terisi karena ditetapkan di luar rumah sakit). Kategori '
                  .'tanpa kosakata TIDAK akan muncul di formulir survei sama sekali, jadi '
                  .'kelayakan diputuskan tanpa pernah menanyakannya. Batas penghasilan dan '
                  .'jenis dinding yang dianggap tidak layak adalah penilaian amil RSP UI.',
        ]];
    }
}
