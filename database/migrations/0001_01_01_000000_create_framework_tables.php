<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel milik framework, bukan milik domain — karena itu tetap di schema public
 * dan boleh disentuh konteks mana pun.
 *
 * Tabel `users` sengaja TIDAK dibuat di sini. Pengguna adalah milik bounded
 * context platform, jadi tempatnya di platform.users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            // Sengaja tanpa foreign key: platform.users berada di schema lain,
            // dan foreign key lintas schema dilarang oleh manifes konteks.
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
