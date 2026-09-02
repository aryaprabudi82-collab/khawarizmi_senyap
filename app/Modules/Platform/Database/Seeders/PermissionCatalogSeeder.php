<?php

namespace App\Modules\Platform\Database\Seeders;

use App\Modules\Platform\Services\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Memuat katalog kapabilitas dari database/data/permissions.json.
 *
 * Sumbernya adalah 1.183 access flag unik yang terbaca dari frmUtama.java
 * Khanza. Kodenya dipertahankan apa adanya supaya tiap permission di sistem ini
 * bisa dilacak balik ke fungsi Khanza yang setara — itu yang membuat peta
 * fungsional lama tetap berguna sebagai backlog.
 *
 * Bersifat idempoten: dijalankan berulang kali hanya memperbarui metadata,
 * tidak pernah menggandakan atau menghapus permission yang sudah dipakai peran.
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/permissions.json');

        if (! is_file($path)) {
            $this->command?->error("Katalog permission tidak ditemukan: {$path}");

            return;
        }

        /** @var list<array<string, mixed>> $catalog */
        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $now = now();

        $rows = array_map(fn (array $p): array => [
            'code' => $p['code'],
            'name' => $p['name'],
            'domain_code' => $p['domain_code'],
            'context' => $p['context'],
            'kind' => $p['kind'],
            'wave' => $p['wave'],
            'legacy_class' => $p['legacy_class'] ?: null,
            'legacy_package' => $p['legacy_package'] ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $catalog);

        // Dipotong agar tidak melewati batas parameter satu statement PostgreSQL.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('platform.permissions')->upsert(
                $chunk,
                ['code'],
                ['name', 'domain_code', 'context', 'kind', 'wave', 'legacy_class', 'legacy_package', 'updated_at']
            );
        }

        app(PermissionRegistry::class)->flush();

        $this->command?->info('Katalog permission: ' . count($rows) . ' kapabilitas dimuat.');
    }
}
