<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\OutboundMessage;
use Carbon\CarbonInterface;

/**
 * Ledger setiap percobaan pengiriman ke BPJS/SATUSEHAT, dikunci pada
 * (target_system, resource_type, source_context, source_id, source_event_at)
 * — timestamp kejadian ASLI di sumbernya, bukan waktu percobaan kirim.
 * Kalau sinkronisasi yang sama dijalankan ulang (retry job, klik ulang
 * tombol admin), baris yang sama diperbarui, bukan bertambah baris baru.
 *
 * updateOrCreate() dipakai — bukan INSERT ... ON CONFLICT DO NOTHING seperti
 * charge_lines/journal_entries — karena di sini status baris justru memang
 * perlu berubah antar percobaan (pending -> sent/failed, attempt_count
 * bertambah). Ini bukan jalur yang butuh jaminan konkurensi setingkat
 * transaksi keuangan; race amat jarang (satu operator memicu sinkronisasi
 * yang sama nyaris bersamaan) dan akibatnya paling buruk cuma attempt_count
 * kurang presisi, bukan data yang salah.
 */
class OutboundMessageLedger
{
    public function record(
        string $targetSystem,
        string $resourceType,
        string $sourceContext,
        int $sourceId,
        CarbonInterface $sourceEventAt,
        array $requestPayload,
        bool $success,
        array $responsePayload,
        ?string $externalReference,
        ?string $errorMessage,
    ): OutboundMessage {
        $key = [
            'target_system' => $targetSystem,
            'resource_type' => $resourceType,
            'source_context' => $sourceContext,
            'source_id' => $sourceId,
            'source_event_at' => $sourceEventAt,
        ];

        $existing = OutboundMessage::query()->where($key)->first();

        return OutboundMessage::query()->updateOrCreate($key, [
            'status' => $success ? OutboundMessage::STATUS_SENT : OutboundMessage::STATUS_FAILED,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'external_reference' => $externalReference,
            'error_message' => $errorMessage,
            'attempt_count' => ($existing->attempt_count ?? 0) + 1,
            'sent_at' => $success ? now() : $existing?->sent_at,
        ]);
    }
}
