#!/usr/bin/env bash
#
# Mengekspor HASIL MIGRASI DATA dari basis data pengembangan, untuk dimuat
# ke server produksi.
#
# YANG DIEKSPOR HANYA TIGA TABEL, dan itu batas yang disengaja:
#
#   identity.patients              371.708  pasien warisan HSN
#   organization.practitioners       1.524  pegawai dari daftar SDM RSUI
#   identity.number_sequences            1  penomoran rekam medis berikutnya
#
# YANG TIDAK IKUT, DAN TIDAK BOLEH IKUT:
#
#   platform.users, roles, role_permission, audit_logs
#     Produksi sudah dipakai orang — 35 akun dan 41 baris jejak audit yang
#     tidak ada di sini. Menimpanya menghapus akun yang dibuat tim lain dan
#     memutus jejak audit, yang justru paling tidak boleh hilang.
#
#   catalog.*, seluruh master lain
#     Sudah diisi seeder di produksi, dan tarifnya bisa saja sudah disesuaikan
#     di sana. Nilai produksi lebih benar daripada nilai pengembangan.
#
# BERISI PHI NYATA. Berkas hasil ekspor memuat nama, NIK, tanggal lahir,
# alamat, dan telepon 371.708 pasien sungguhan. Perlakukan seperti rekam
# medis kertas: jangan lewat email atau layanan berbagi berkas umum, hapus
# dari mesin perantara setelah dimuat, dan jangan pernah masuk git.
#
# Pemakaian:
#   bash scripts/ekspor-data-migrasi.sh /path/tujuan
#
set -euo pipefail

TUJUAN="${1:-./ekspor-migrasi}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DIR="${TUJUAN}/migrasi-${STAMP}"

# Kredensial dibaca dari .env, tidak ditulis di skrip.
ENV_FILE="$(dirname "$0")/../.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "GAGAL: .env tidak ditemukan di $ENV_FILE" >&2
    exit 1
fi

ambil() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }

# Perkakas PostgreSQL sering tidak terdaftar di PATH pada pemasangan Windows —
# dicari sendiri daripada menggagalkan skrip dengan "command not found" yang
# tidak menjelaskan apa-apa.
cari_perkakas() {
    local nama="$1"

    if command -v "$nama" >/dev/null 2>&1; then
        command -v "$nama"
        return
    fi

    local kandidat
    kandidat="$(ls -d "/c/Program Files/PostgreSQL"/*/bin 2>/dev/null | sort -rV | head -1)"

    if [[ -n "$kandidat" && -x "${kandidat}/${nama}.exe" ]]; then
        echo "${kandidat}/${nama}.exe"
        return
    fi

    echo "GAGAL: ${nama} tidak ditemukan. Pasang PostgreSQL client tools," >&2
    echo "atau tambahkan folder bin-nya ke PATH." >&2
    exit 1
}

PG_DUMP="$(cari_perkakas pg_dump)"
PSQL_BIN="$(cari_perkakas psql)"

DB_HOST="$(ambil DB_HOST)"
DB_PORT="$(ambil DB_PORT)"
DB_NAME="$(ambil DB_DATABASE)"
DB_USER="$(ambil DB_USERNAME)"
DB_PASS="$(ambil DB_PASSWORD)"

echo "Sumber : ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "Tujuan : ${DIR}"
echo

# Penjagaan: pastikan ini memang basis data pengembangan yang dimaksud, bukan
# server lain yang kebetulan tersambung.
if [[ "$DB_NAME" != "simrs_mandiri" ]]; then
    echo "BERHENTI: basis data '${DB_NAME}' bukan 'simrs_mandiri'." >&2
    echo "Skrip ini hanya untuk mengekspor DARI mesin pengembangan." >&2
    exit 1
fi

mkdir -p "$DIR"
export PGPASSWORD="$DB_PASS"

PSQL=("$PSQL_BIN" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -At)

# --- hitungan sebelum ekspor, untuk dicocokkan setelah impor ---------------

echo "Menghitung baris sumber..."
{
    echo "tabel;jumlah_baris"
    for T in identity.patients organization.practitioners identity.number_sequences; do
        N="$("${PSQL[@]}" -c "SELECT count(*) FROM ${T};")"
        echo "${T};${N}"
        printf '  %-34s %s\n' "$T" "$N" >&2
    done
} > "${DIR}/hitungan-sumber.csv"

echo

# --- ekspor per tabel ------------------------------------------------------
#
# --data-only: struktur TIDAK ikut. Produksi harus menjalankan `php artisan
# migrate` lebih dulu; menyalin struktur dari sini berisiko menimpa perubahan
# yang sudah ada di sana.
#
# --column-inserts: satu INSERT per baris dengan nama kolom disebutkan. Lebih
# lambat daripada COPY, tapi tahan terhadap perbedaan URUTAN kolom antara dua
# basis data — dan urutan kolom memang bisa berbeda kalau migrasinya dijalankan
# dengan urutan berbeda.

for T in identity.patients organization.practitioners identity.number_sequences; do
    BERKAS="${DIR}/$(echo "$T" | tr '.' '_').sql"
    echo "Mengekspor ${T}..."

    "$PG_DUMP" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
        --data-only --column-inserts --no-owner --no-privileges \
        --table="$T" > "$BERKAS"

    printf '  %s (%s)\n' "$(basename "$BERKAS")" "$(du -h "$BERKAS" | cut -f1)"
done

unset PGPASSWORD

# --- berkas penyerta -------------------------------------------------------

cat > "${DIR}/BACA-DULU.txt" <<'PETUNJUK'
EKSPOR DATA MIGRASI — SIMRS MANDIRI
===================================

BERKAS INI MEMUAT DATA PASIEN SUNGGUHAN.
Nama, NIK, tanggal lahir, alamat, dan telepon 371.708 orang. Perlakukan
seperti rekam medis kertas: jangan lewat email atau layanan berbagi umum,
hapus dari mesin perantara setelah dimuat, jangan pernah masuk git.

URUTAN PEMASANGAN DI PRODUKSI
-----------------------------

1. CADANGKAN PRODUKSI LEBIH DULU — ini tidak bisa dilewati.

       pg_dump -h <host> -U <user> -d <db> -Fc -f cadangan-sebelum-impor.dump

2. JALANKAN MIGRASI. Produksi harus punya tiga migrasi terbaru; tanpa itu
   kolomnya belum ada dan impor gagal di tengah:

       2027_04_01_000001_allow_unknown_patient_sex
       2027_04_02_000001_add_practitioner_category
       2027_04_03_000001_add_practitioner_employment_detail

       php artisan migrate --force

   Periksa hasilnya:
       php artisan migrate:status | tail -5

3. PERIKSA TABEL TUJUAN. Data uji di produksi bisa membuat nomor rekam medis
   bentrok — persoalan yang sama pernah terjadi di pengembangan:

       SELECT count(*) FROM identity.patients;            -- perkiraan: 3
       SELECT count(*) FROM organization.practitioners;   -- perkiraan: 6

   Bila ada, putuskan lebih dulu: dihapus, atau nomornya diubah. JANGAN
   langsung impor di atasnya.

4. IMPOR, SATU TABEL PER SATU, periksa setiap selesai:

       psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f identity_patients.sql
       psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f organization_practitioners.sql
       psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f identity_number_sequences.sql

   ON_ERROR_STOP=1 wajib: tanpa itu psql melanjutkan setelah galat dan
   menyisakan tabel terisi separuh tanpa ada yang menyadarinya.

5. SELARASKAN SEQUENCE. Tanpa langkah ini, pasien baru pertama yang didaftarkan
   akan memakai id yang sudah terpakai, dan INSERT-nya gagal di loket:

       SELECT setval(pg_get_serial_sequence('identity.patients','id'),
                     (SELECT max(id) FROM identity.patients));
       SELECT setval(pg_get_serial_sequence('organization.practitioners','id'),
                     (SELECT max(id) FROM organization.practitioners));

6. COCOKKAN HITUNGAN dengan hitungan-sumber.csv. Harus sama persis.

       SELECT count(*) FROM identity.patients;
       SELECT count(*) FROM organization.practitioners;
       SELECT * FROM identity.number_sequences;

7. PERIKSA SECARA ACAK, jangan hanya percaya hitungan:

       SELECT medical_record_number, nik IS NOT NULL AS ada_nik,
              birth_date IS NOT NULL AS ada_lahir, sex
       FROM identity.patients ORDER BY random() LIMIT 10;

YANG TIDAK IKUT DI EKSPOR INI — DAN JANGAN DITIMPA
--------------------------------------------------
platform.users, platform.roles, platform.role_permission, platform.audit_logs
Produksi sudah dipakai: 35 akun dan 41 baris jejak audit yang tidak ada di
pengembangan. Menimpanya menghapus akun tim lain dan memutus jejak audit.

catalog.* dan master lain sudah diisi seeder di produksi, dan tarif di sana
bisa saja sudah disesuaikan. Nilai produksi lebih benar.

KALAU GAGAL DI TENGAH
---------------------
Pulihkan dari cadangan langkah 1. Jangan mencoba menambal sebagian —
tabel yang terisi separuh lebih sulit ditelusuri daripada memulai ulang.
PETUNJUK

echo
echo "Selesai."
echo "  ${DIR}"
echo
echo "Baca ${DIR}/BACA-DULU.txt sebelum memuat ke produksi."
echo
echo "PERINGATAN: berkas ini memuat data pasien sungguhan."
echo "Jangan lewat email atau layanan berbagi umum. Hapus dari mesin"
echo "perantara setelah dimuat."
