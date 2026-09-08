<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\PropertyHandover;
use Illuminate\Support\Collection;

/**
 * Serah terima barang pasien & anggota tubuh (domain P item B).
 *
 * Dua arah, bukan satu — lihat catatan panjang di migrasinya. Yang satu
 * arah tidak pernah bisa menjawab "apakah masih ada barang pasien ini
 * yang dipegang rumah sakit".
 */
class PropertyHandoverService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /** Rumah sakit menerima titipan. */
    public function receive(array $data, ?int $officerId = null): PropertyHandover
    {
        return $this->record($data + ['direction' => PropertyHandover::ARAH_DITITIPKAN], $officerId);
    }

    /**
     * Rumah sakit menyerahkan kepada keluarga.
     *
     * $melunasi menunjuk titipan yang diselesaikan penyerahan ini, supaya
     * sisa titipan bisa dihitung dan bukan ditebak.
     */
    public function hand(array $data, ?int $officerId = null, ?PropertyHandover $melunasi = null): PropertyHandover
    {
        if ($melunasi !== null) {
            if ($melunasi->direction !== PropertyHandover::ARAH_DITITIPKAN) {
                throw new CorrespondenceException('Yang bisa dilunasi hanya catatan penitipan.');
            }

            if ($melunasi->settled()) {
                throw new CorrespondenceException(
                    'Titipan '.$melunasi->handover_number.' sudah diserahkan lewat '.
                    $melunasi->settlement->handover_number.'.'
                );
            }

            /*
             * Jenis disalin dari titipannya, tidak diterima dari pemanggil:
             * penyerahan "barang pasien" yang melunasi titipan anggota tubuh
             * akan membuat kedua catatan itu bercerita hal yang berbeda
             * tentang benda yang sama.
             */
            $data['kind'] = $melunasi->kind;
            $data['settles_handover_id'] = $melunasi->id;
        }

        return $this->record($data + ['direction' => PropertyHandover::ARAH_DISERAHKAN], $officerId);
    }

    /**
     * Titipan yang belum diserahkan kembali.
     *
     * DIHITUNG, tidak disimpan — kolom "masih dititipkan" akan menjadi
     * salah begitu ada penyerahan yang lupa memperbaruinya, dan tidak ada
     * yang tahu kapan itu terjadi.
     *
     * @return Collection<int, PropertyHandover>
     */
    public function stillHeld(?int $patientId = null): Collection
    {
        return PropertyHandover::query()
            ->where('direction', PropertyHandover::ARAH_DITITIPKAN)
            ->when($patientId !== null, fn ($q) => $q->where('patient_id', $patientId))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('correspondence.property_handovers as pelunas')
                    ->whereColumn('pelunas.settles_handover_id', 'correspondence.property_handovers.id');
            })
            ->orderBy('occurred_at')
            ->get();
    }

    private function record(array $data, ?int $officerId): PropertyHandover
    {
        $this->assertLayak($data);

        return PropertyHandover::query()->create($data + [
            'handover_number' => $this->numbers->allocate('STB'),
            'occurred_at' => $data['occurred_at'] ?? now(),
            'officer_id' => $officerId,
        ]);
    }

    private function assertLayak(array $data): void
    {
        if (! in_array($data['kind'] ?? null, PropertyHandover::JENIS, true)) {
            throw new CorrespondenceException('Jenis serah terima harus barang pasien atau anggota tubuh.');
        }

        if (! in_array($data['counterparty_relationship'] ?? null, PropertyHandover::HUBUNGAN, true)) {
            throw new CorrespondenceException('Hubungan pihak penerima/penitip dengan pasien harus dikenal.');
        }

        // Satu-satunya pembanding ketika belakangan ada yang menyatakan
        // barangnya rusak atau kurang.
        if (blank($data['condition'] ?? null)) {
            throw new CorrespondenceException(
                'Kondisi barang saat serah terima wajib dicatat — tanpa itu catatan ini cuma '.
                'membuktikan ada sesuatu yang berpindah tangan, bukan sesuatu yang mana.'
            );
        }

        // Wadah jaringan tanpa label tidak bisa dibedakan dari wadah mana pun.
        if (($data['kind'] ?? null) === PropertyHandover::JENIS_ANGGOTA_TUBUH
            && blank($data['container_label'] ?? null)) {
            throw new CorrespondenceException(
                'Serah terima anggota tubuh wajib menyebutkan label wadahnya.'
            );
        }
    }
}
