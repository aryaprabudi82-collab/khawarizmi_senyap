<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan satu kode lokal ke kode standar SATUSEHAT.
 *
 * standard_code dan code_system SELALU sepasang: kode tanpa sistemnya akan
 * dikirim sebagai sistem yang salah. Dijaga constraint dan service.
 */
class SatusehatCodeMapping extends Model
{
    protected $table = 'integration.satusehat_code_mappings';

    protected $guarded = ['id'];

    /** Sistem kode standar yang dikenal SATUSEHAT. */
    public const SISTEM = ['snomed', 'loinc', 'kfa', 'icd10', 'icd9', 'internal'];

    /** URI resmi tiap sistem kode, dipakai saat menyusun resource FHIR. */
    public const URI = [
        'snomed' => 'http://snomed.info/sct',
        'loinc' => 'http://loinc.org',
        'kfa' => 'http://sys-ids.kemkes.go.id/kfa',
        'icd10' => 'http://hl7.org/fhir/sid/icd-10',
        'icd9' => 'http://hl7.org/fhir/sid/icd-9-cm',
        'internal' => 'http://sys-ids.kemkes.go.id/local',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'mapped_at' => 'datetime'];
    }

    public function isMapped(): bool
    {
        return $this->standard_code !== null;
    }

    /** URI sistem kodenya, untuk dipasang pada resource FHIR. */
    public function systemUri(): ?string
    {
        return $this->code_system === null ? null : (self::URI[$this->code_system] ?? null);
    }
}
