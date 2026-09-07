<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencarian & riwayat peserta BPJS (domain L item J).
 *
 * Menaungi bpjs_cek_nik, bpjs_cek_skdp, bpjs_histori_pelayanan, dan
 * bpjs_daftar_finger_print.
 *
 * SATU TABEL UNTUK EMPAT PENCARIAN, dengan alasan yang sama seperti enam
 * "cek rujukan" di item A: keempatnya adalah satu perbuatan yang sama —
 * kita bertanya kepada BPJS tentang seorang peserta dan menyimpan
 * jawabannya. Yang berbeda cuma kunci pencariannya dan bentuk jawabannya,
 * dan keduanya sudah tertampung di lookup_type + raw_response. Empat tabel
 * yang berkolom nyaris sama berarti empat tempat yang harus diubah setiap
 * kali aturan penyimpanan jawaban BPJS berubah.
 *
 * JAWABAN BPJS ADALAH SALINAN, BUKAN KEBENARAN KITA. Nama, NIK, dan kelas
 * rawat menurut BPJS boleh berbeda dari rekam medis kita, dan perbedaan itu
 * DILAPORKAN, tidak pernah dipakai menimpa data pasien di konteks identity.
 * Membiarkan sistem luar mengubah identitas pasien adalah cara paling
 * halus untuk merusak rekam medis.
 *
 * HISTORI PELAYANAN TIDAK DILEBUR KE RIWAYAT KUNJUNGAN KITA. Isinya
 * kunjungan pasien di fasilitas lain — informasi yang berharga bagi dokter,
 * tapi bukan kunjungan yang pernah terjadi di sini. Meleburnya membuat
 * rekam medis kita seolah memuat pelayanan yang tidak pernah kita berikan,
 * dan itu tidak bisa dibedakan lagi begitu tercampur.
 *
 * FINGER PRINT: YANG DICATAT ADALAH STATUS MENURUT BPJS, BUKAN SIDIK
 * JARINYA. Sistem ini tidak punya perangkat pemindai dan tidak menyimpan
 * data biometrik apa pun — yang ditanyakan hanyalah "apakah peserta ini
 * sudah terdaftar sidik jarinya di BPJS", karena jawabannya menentukan
 * apakah pasien perlu diarahkan mendaftar lebih dulu. Menyimpan sidik jari
 * sendiri adalah data pribadi bersifat spesifik menurut UU 27/2022 dan
 * menuntut perlindungan yang jauh berbeda; tidak dilakukan tanpa keputusan
 * dan sarana tersendiri.
 */
return new class extends Migration
{
    private const S = 'integration';

    private const JENIS = ['nik', 'skdp', 'histori', 'fingerprint'];

    public function up(): void
    {
        Schema::create(self::S . '.bpjs_member_lookups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('lookup_type', 20);

            // Kunci yang dicari: NIK, nomor kartu, atau nomor SKDP —
            // tergantung jenisnya. Disimpan supaya pencarian yang gagal bisa
            // ditelusuri; tanpa ini, pertanyaan "kenapa peserta tidak
            // ketemu" tidak bisa dijawab siapa pun.
            $table->string('search_key', 40);

            $table->date('period_from')->nullable();
            $table->date('period_until')->nullable();

            $table->boolean('found')->default(false);

            // Ringkasan yang bisa dibaca petugas tanpa membuka JSON mentah.
            $table->string('card_number', 20)->nullable();
            $table->string('participant_name', 150)->nullable();
            $table->string('summary', 300)->nullable();
            $table->unsignedInteger('result_count')->default(0);

            $table->json('raw_response')->nullable();
            $table->string('error_message', 300)->nullable();

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampsTz();

            $table->index(['lookup_type', 'checked_at']);
            $table->index('search_key');
            $table->index('card_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_member_lookups
            ADD CONSTRAINT bpjs_member_lookups_type_check
            CHECK (lookup_type IN ('" . implode("','", self::JENIS) . "'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.bpjs_member_lookups');
    }
};
