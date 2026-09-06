<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penagihan piutang pasien (domain K item D).
 *
 * Menaungi penagihan_piutang_pasien dan validasi_penagihan_piutang;
 * rincian_piutang_pasien dan piutang_pasien2 dilayani laporannya.
 *
 * CELAH YANG DITEMUKAN SAAT MEMERIKSA: billing.patient_receivables sudah
 * ada sejak domain I item B — piutang pasien bisa DIBUKA saat pasien
 * pulang belum lunas, dan bisa DIBATALKAN kalau salah — tapi tidak ada
 * satu pun cara mencatat pasiennya MEMBAYAR. Piutang yang bisa dibuka
 * tapi tidak pernah bisa ditutup akan menumpuk selamanya, dan angka
 * piutang rumah sakit terus naik tanpa pernah turun meski uangnya sudah
 * diterima. Itu bukan laporan yang salah sedikit; itu laporan yang salah
 * makin jauh setiap hari.
 *
 * TAPI TABEL PEMBAYARAN BARU SENGAJA TIDAK DIBUAT, meski itu dorongan
 * pertama saat menemukan celahnya. PatientReceivable::outstanding()
 * menurunkan sisanya dari tagihannya sendiri, dan billing.payments sudah
 * menampung pembayaran atas tagihan itu sejak awal. Tabel pembayaran
 * kedua akan menjadi sumber kebenaran kedua atas uang yang sama: dua
 * angka yang bisa berbeda, dan yang berbeda tidak akan ketahuan sampai
 * seseorang menjumlahkan keduanya. Pasien membayar piutangnya = pasien
 * membayar tagihannya, lewat mekanisme yang sudah ada.
 *
 * Jadi yang benar-benar kurang cuma satu: RIWAYAT PENAGIHANNYA.
 * Menghubungi pasien dan menerima uang adalah dua peristiwa berbeda, dan
 * riwayat penagihan justru yang dibutuhkan saat memutuskan piutang layak
 * dihapuskan atau belum — pembayarannya sendiri sudah tercatat di tempat
 * yang seharusnya.
 */
return new class extends Migration
{
    private const S = 'billing';

    private const HASIL = ['dijanjikan', 'menolak', 'tidak-terhubung', 'dibayar-sebagian', 'lunas'];

    public function up(): void
    {
        Schema::create(self::S . '.patient_receivable_collections', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('receivable_id')->constrained(self::S . '.patient_receivables');

            $table->date('contacted_on');
            $table->string('channel', 30)->comment('telepon/surat/kunjungan/pesan');
            $table->string('outcome', 30);
            $table->date('promised_on')->nullable()->comment('Tanggal yang dijanjikan pasien, bila ada');
            $table->string('note', 300)->nullable();

            $table->unsignedBigInteger('contacted_by')->nullable();
            $table->string('contacted_by_name', 120)->nullable();

            // Penagihan diverifikasi penyelia sebelum dipakai sebagai dasar
            // penghapusan piutang — validasi_penagihan_piutang. Tanpa ini,
            // catatan "sudah ditagih, pasien menolak" bisa ditulis siapa
            // saja dan langsung jadi alasan menghapus piutang.
            $table->timestampTz('validated_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();

            $table->timestampsTz();

            $table->index(['receivable_id', 'contacted_on']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".patient_receivable_collections
            ADD CONSTRAINT patient_receivable_collections_outcome_check
            CHECK (outcome IN ('" . implode("','", self::HASIL) . "'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.patient_receivable_collections');
    }
};
