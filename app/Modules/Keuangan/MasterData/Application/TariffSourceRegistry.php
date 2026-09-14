<?php

namespace App\Modules\Keuangan\MasterData\Application;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Domain\TariffResolver;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Collection;

/**
 * Pintu masuk tunggal ke seluruh tarif rumah sakit — gate Wave 1.
 *
 * INI YANG SEBELUMNYA TIDAK ADA. "Berapa tarif item X pada tanggal Y untuk
 * penjamin Z" dulu dijawab lima tempat berbeda dengan lima cara berbeda,
 * dan tidak ada satu pun yang bisa menjawabnya untuk item yang bukan
 * miliknya. Akibatnya bukan sekadar merepotkan: tidak ada cara memeriksa
 * apakah SELURUH yang bisa ditagihkan sudah punya tarif dan sudah punya
 * akun, sehingga item yang terlewat baru ketahuan saat menutup buku.
 *
 * YANG DISERAGAMKAN BUKAN BENTUK TARIFNYA, MELAINKAN CARA MENANYAKANNYA.
 * Tarif tindakan berdimensi penjamin dan kelas; harga obat dikali markup;
 * tarif kamar per hari rawat; tarif parkir dihitung dari durasi. Kelimanya
 * tetap dihitung sumbernya masing-masing — registry hanya tahu siapa yang
 * harus ditanya.
 *
 * TIDAK MENYIMPAN APA PUN. Registry tidak punya tabel dan tidak punya
 * cache. Tarif yang di-cache adalah tarif yang bisa basi, dan tarif basi
 * yang dipakai menagih adalah kesalahan yang tidak meninggalkan jejak.
 */
class TariffSourceRegistry
{
    /** @var array<string, TariffResolver> */
    private array $resolver = [];

    /** @param iterable<TariffResolver> $resolvers */
    public function __construct(iterable $resolvers = [])
    {
        foreach ($resolvers as $r) {
            $this->daftarkan($r);
        }
    }

    public function daftarkan(TariffResolver $resolver): void
    {
        $this->resolver[$resolver->konteks()] = $resolver;
    }

    /** @return array<int, string> */
    public function konteksTerdaftar(): array
    {
        $daftar = array_keys($this->resolver);
        sort($daftar);

        return $daftar;
    }

    /**
     * Tarif satu item CDM pada satu tanggal.
     *
     * @param  array<string, mixed>  $konteksPenagihan
     *
     * @throws KeuanganException bila konteks sumbernya tidak punya resolver
     */
    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money
    {
        $konteks = (string) $item->source_context;

        if (! isset($this->resolver[$konteks])) {
            /*
             * Dilempar, tidak dikembalikan null. Konteks tanpa resolver
             * berarti ada golongan item yang tarifnya tidak bisa
             * ditanyakan siapa pun — dan itu cacat pemasangan, bukan
             * keadaan bisnis yang wajar. Mengembalikan null akan membuatnya
             * tampak sama dengan "tarifnya belum diisi", dan cacatnya
             * bertahan sampai ada yang membandingkan angka.
             */
            throw new KeuanganException(
                "Konteks sumber '{$konteks}' belum punya resolver tarif. Item '{$item->code}' "
                .'tidak bisa dihitung tarifnya sampai resolvernya dipasang — dan selama itu '
                .'setiap tagihan yang memuatnya akan salah nilai.'
            );
        }

        return $this->resolver[$konteks]->tarif($item, $tanggal, $konteksPenagihan);
    }

    /**
     * Seluruh baris sumber yang belum punya item CDM, per konteks.
     *
     * Inilah jawaban atas "apa saja yang bisa ditagih tapi belum bisa
     * dijurnalkan" — pertanyaan yang sebelum ini tidak bisa dijawab.
     *
     * @return Collection<string, Collection<int, array{source_id:int, code:string, name:string, golongan:string}>>
     */
    public function belumTertaut(): Collection
    {
        return collect($this->resolver)
            ->map(fn (TariffResolver $r) => collect(iterator_to_array($r->belumTertaut(), false)))
            ->reject(fn (Collection $c) => $c->isEmpty());
    }

    /**
     * Ringkasan cakupan penautan — dipakai layar kesiapan.
     *
     * @return array<string, array{tertaut:int, belum:int}>
     */
    public function cakupan(): array
    {
        $hasil = [];

        foreach ($this->resolver as $konteks => $r) {
            $belum = count(iterator_to_array($r->belumTertaut(), false));

            $tertaut = ChargeItem::query()
                ->where('source_context', $konteks)
                ->whereNull('valid_until')
                ->count();

            $hasil[$konteks] = ['tertaut' => $tertaut, 'belum' => $belum];
        }

        ksort($hasil);

        return $hasil;
    }
}
