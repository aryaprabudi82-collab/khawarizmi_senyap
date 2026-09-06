<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran atomik untuk konteks integrasi (domain L item C).
 *
 * Konteks ini belum pernah butuh nomor sendiri: SEP dan surat kontrol
 * memakai nomor DARI BPJS, bukan nomor kita. Klaim berbeda — ia perlu
 * nomor internal untuk ditelusuri sebelum BPJS memberi nomornya sendiri.
 *
 * Bentuknya sama persis dengan number_sequences di konteks lain, supaya
 * NumberAllocator mana pun bisa dipakai tanpa kejutan.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 40)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.number_sequences');
    }
};
