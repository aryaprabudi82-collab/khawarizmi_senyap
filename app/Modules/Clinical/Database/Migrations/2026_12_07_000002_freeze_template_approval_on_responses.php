<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membekukan status pengesahan template pada jawabannya (domain M item F).
 *
 * Template yang belum disahkan tetap boleh dipakai — menghalanginya berarti
 * pencatatan pindah ke kertas sampai komite bersidang, dan itu jauh lebih
 * buruk. Yang dilakukan justru sebaliknya: statusnya dicatat pada tiap
 * jawaban, sehingga kelak bisa dijawab dengan pasti rekam medis mana yang
 * dibuat memakai formulir yang belum disahkan.
 *
 * DIBEKUKAN, BUKAN DIBACA SAAT DIBUTUHKAN. Kalau dibaca dari template,
 * pengesahan yang terjadi hari ini akan membuat seluruh jawaban tahun lalu
 * tampak seolah dibuat dengan formulir yang sudah sah — padahal saat itu
 * belum. Prinsip yang sama seperti versi template dan skornya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical.form_responses', function (Blueprint $table) {
            $table->boolean('template_approved')->default(false)
                ->comment('Status pengesahan template SAAT formulir ini dibuka');
        });
    }

    public function down(): void
    {
        Schema::table('clinical.form_responses', function (Blueprint $table) {
            $table->dropColumn('template_approved');
        });
    }
};
