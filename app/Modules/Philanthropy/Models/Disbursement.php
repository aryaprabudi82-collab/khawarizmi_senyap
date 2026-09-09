<?php

namespace App\Modules\Philanthropy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penyaluran dana kesehatan kepada seorang penerima.
 *
 * PENGGANTI `ambil_dankes` KHANZA, yang berisi tanggal, kategori, dan
 * jumlah — TANPA penerimanya. Bantuan yang tercatat tanpa penerima tidak
 * bisa diaudit, tidak bisa mendeteksi penerimaan ganda, dan tidak bisa
 * menjawab pertanyaan yang paling wajar dari seorang amil: apakah keluarga
 * ini pernah dibantu, kapan, dan berapa.
 *
 * Menunjuk asesmennya juga, bukan cuma penerimanya: bantuan yang tidak
 * bisa ditelusuri ke dasar kelayakannya adalah bantuan yang tidak bisa
 * dipertanggungjawabkan kepada pemberi titipan.
 */
class Disbursement extends Model
{
    public const SUMBER_ZAKAT = 'zakat';

    public const SUMBER_INFAK = 'infak';

    public const SUMBER_SEDEKAH = 'sedekah';

    public const SUMBER_CSR = 'csr';

    public const SUMBER_LAINNYA = 'lainnya';

    public const SUMBER = [
        self::SUMBER_ZAKAT, self::SUMBER_INFAK, self::SUMBER_SEDEKAH,
        self::SUMBER_CSR, self::SUMBER_LAINNYA,
    ];

    /**
     * Sumber dana yang penerimanya wajib bergolongan asnaf.
     *
     * Zakat yang disalurkan di luar delapan golongan tidak sah sebagai
     * zakat, dan itu syarat dari luar rumah sakit — bukan kebijakan yang
     * boleh dilonggarkan panitia. Infak, sedekah, dan CSR tidak terikat.
     */
    public const WAJIB_ASNAF = [self::SUMBER_ZAKAT];

    protected $table = 'philanthropy.disbursements';

    protected $guarded = ['id'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'recipient_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    protected function casts(): array
    {
        return [
            'disbursed_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }
}
