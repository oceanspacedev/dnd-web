# Rekam ulang GIF panduan

Skrip di folder ini merekam GIF animasi untuk halaman panduan di panel admin. Rekamannya berjalan di Chromium headless lewat Playwright, lalu ffmpeg menyusunnya menjadi GIF 1100×618, 10 fps, dengan palet 128 warna. Jalankan ulang skrip ini setiap kali tampilan yang direkam berubah.

| Panduan | Halaman | Flow | GIF (di `public/images/…`) |
| --- | --- | --- | --- |
| Panduan Ubah Posisi | `user-position-guide.blade.php` | `flows/posisi.js` | `panduan-ubah-posisi/ubah-posisi-massal.gif`, `ubah-posisi-perorangan.gif`, `ubah-posisi-tim-saya.gif` |
| Panduan Akses Data | `data-access-guide.blade.php` | `flows/akses.js` | `panduan-akses-data/akses-data-atur-admin.gif`, `akses-data-hasil-admin.gif`, `akses-data-tim-saya.gif` |
| Panduan Aturan Penilaian | `scoring-rules-guide.blade.php` | `flows/aturan.js` | `panduan-aturan-penilaian/kehadiran-isi-form.gif`, `kehadiran-import.gif`, `aturan-versi-baru.gif`, `komponen-tambah.gif` |
| Panduan KPI Manajerial | `kpi-supervisor-guide.blade.php` | `flows/kpi-manajerial.js` | `kpi-guides/kpi-flow-lengkap.gif`, `kpi-error-required-fields.gif`, `kpi-error-kpi-description.gif`, `kpi-error-value-plan.gif`, `kpi-error-percentage-total.gif`, `supervisor-review-flow.gif` |
| Panduan KPI Operasional | `kpi-staff-guide.blade.php` | `flows/kpi-operasional.js` | `kpi-guides/staff-login-flow.gif`, `staff-checklist-flow.gif` |

## Keamanan data

Rekaman hanya boleh memakai database demo yang dibuat `demo_seed.php`. Database itu hanya berisi nama palsu dari Faker (`id_ID`, seed `20261018`), jadi data karyawan asli tidak pernah masuk ke GIF.

- `DB_DATABASE` di `.env` menunjuk ke `dnd_feature_test`, yang berisi data asli. Karena itu server rekaman harus dijalankan dengan `DB_DATABASE` milik database demo, dan rekaman tidak boleh diarahkan ke `dnd_feature_test` atau ke data produksi.
- `demo_seed.php` hanya mau berjalan di `dnd_demo_panduan` (lapisan dasar) atau di `dnd_demo_kpimanajerial` / `dnd_demo_kpioperasional` (lapisan KPI). `reset.sh` hanya menerima `posisi`, `akses`, `aturan`, `kpimanajerial` dan `kpioperasional`, lalu hanya menyentuh `dnd_demo_<panduan>`. `aturan-template.php` hanya mau berjalan di `dnd_demo_aturan`.
- Jangan jalankan `php artisan config:cache`. Kalau konfigurasi di-cache, Laravel mengabaikan `DB_DATABASE` dari environment. `setup-db.sh`, `reset.sh` dan `serve.sh` menolak berjalan selama `bootstrap/cache/config.php` ada.
- Akun `demo_*` hanya ada di database demo. Server yang salah database tidak bisa dipakai login oleh flow, jadi rekaman berhenti di langkah login.

### WhatsApp: selalu ke stub lokal

`.env` berisi URL gateway WhatsApp yang asli (`WAG_URL`), dan `config/services.php` juga memakai URL gateway asli sebagai nilai bawaan. Login OTP mengirim pesan WhatsApp sungguhan lewat `App\Services\WhatsAppService::send`. Karena itu tidak ada proses rekaman yang boleh memakai nilai dari `.env`:

- `env.sh` memaksa `WAG_URL=http://127.0.0.1:8019`, `WAG_TOKEN` berisi token dummy, `MAIL_MAILER=log` dan `CACHE_STORE=array`. Nilainya dipaksa, bukan default, jadi `WAG_URL` yang kebetulan di-export di shell juga tertimpa. `setup-db.sh`, `reset.sh`, `serve.sh` dan `wa-stub.sh` memuat file ini lebih dulu. Flow yang menjalankan `php` sendiri (misalnya `aturan-template.php`) memakai nilai yang sama lewat `rec.SAFE_ENV` dari `lib.js`.
- phpdotenv tidak pernah menimpa variabel yang sudah ada di environment proses, jadi nilai dari `env.sh` menang atas `.env`.
- `guard.php` menyalakan aplikasi dengan environment yang sama lalu menolak kalau database bukan `dnd_demo_*`, kalau URL WhatsApp bukan `http://127.0.0.1:<port>` dari `env.sh`, kalau token bukan token dummy, atau kalau mailer bukan `log`. `serve.sh` menjalankannya sebelum `php -S`, `reset.sh` setelah setiap restore. `demo_seed.php` dan `aturan-template.php` punya pengecekan URL yang sama. Token tidak pernah dicetak.
- `wa-stub.php` (port 8019) hanya menyimpan pesan ke `.work/wa-outbox.jsonl` dan tidak meneruskan apa pun. Kalau stub mati, aplikasi menampilkan "OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi." dan tidak ada pesan yang keluar.
- Jangan pernah menyalakan server, tinker atau skrip demo tanpa `env.sh`.

Karena `WAG_TOKEN` dummy selalu terisi, halaman login semua server demo sekarang menampilkan "Atau masuk dengan" dan tombol "WhatsApp". Flow lama login dengan password di luar rekaman, jadi GIF-nya tidak berubah.

## Prasyarat

- Repo sudah terpasang: `composer install`, dan `vendor/` sudah ada.
- PHP 8.4, Node.js 18 atau lebih baru, ffmpeg (`brew install ffmpeg`).
- MySQL lokal. Skrip memakai `mysql -u root` tanpa password. Untuk user lain, set `MYSQL_USER`. Password atau host lain bisa diatur di `~/.my.cnf`. Server aplikasi tetap memakai `DB_HOST`, `DB_USERNAME` dan `DB_PASSWORD` dari `.env`, jadi keduanya harus menunjuk ke server MySQL yang sama.

## Langkah

Semua perintah di bawah dijalankan dari folder ini (`tools/guide-gifs`).

### 1. Pasang Playwright dan Chromium

```bash
npm ci
```

```bash
npm run install-browser
```

`npm ci` memasang `playwright-core` 1.58.2 di folder ini saja. `npm run install-browser` mengunduh Chromium headless shell ke cache Playwright (`~/Library/Caches/ms-playwright` di macOS). Skrip otomatis memakai revisi yang diharapkan `playwright-core`, atau revisi terbaru yang ada di cache. Untuk memakai Chromium lain, set `CHROMIUM_PATH`, misalnya `CHROMIUM_PATH=/path/ke/chrome-headless-shell`.

### 2. Buat database demo

```bash
sh setup-db.sh
```

Skrip di folder ini dijalankan lewat `sh` karena file-nya membawa atribut provenance macOS yang bisa menolak eksekusi langsung (`./setup-db.sh`).

Skrip ini menjalankan tiga langkah:

1. Membuat `dnd_demo_panduan`, lalu menjalankan `demo_seed.php`. Seeder itu menjalankan `migrate:fresh` lalu mengisi akun demo per peran (`demo_superadmin`, `demo_admin_bu`, `demo_manager`, `demo_teamleader`), 16 karyawan contoh, serta kehadiran dan penilaian September 2026 untuk 16 karyawan itu. `demo_admin_bu` dibuat sebagai staf COO; GIF 1 Panduan Akses Data menjadikannya ADMIN untuk ULS dan GMD. Password demo ada di `lib.js` (`PASSWORD`).
2. Menyimpan dump database itu ke `.work/demo_base.sql`.
3. Membuat satu salinan per panduan, yaitu `dnd_demo_posisi`, `dnd_demo_akses`, `dnd_demo_aturan`, `dnd_demo_kpimanajerial` dan `dnd_demo_kpioperasional`, lewat `reset.sh`.

Setiap flow mengembalikan database panduannya ke isi dump ini sebelum merekam, jadi rekaman bisa diulang berkali-kali. Untuk mereset satu database secara manual, jalankan `sh reset.sh <panduan>`, misalnya `sh reset.sh posisi` atau `sh reset.sh kpioperasional`.

Untuk `kpimanajerial` dan `kpioperasional`, `reset.sh` menambahkan lapisan KPI (`php demo_seed.php kpi`) di atas dump itu. Lapisan ini sengaja tidak masuk ke `demo_base.sql`: kolom No. HP tampil di daftar Karyawan, jadi nomor WhatsApp demo dan KPI akan mengubah GIF tiga panduan lain. Isi tiga panduan lama tetap sama persis.

### 3. Jalankan satu server per panduan

Buka satu terminal untuk setiap panduan yang akan direkam, lalu biarkan server tetap berjalan:

```bash
sh serve.sh posisi
```

```bash
sh serve.sh akses
```

```bash
sh serve.sh aturan
```

```bash
sh serve.sh kpimanajerial
```

```bash
sh serve.sh kpioperasional
```

`serve.sh` memuat `env.sh`, menjalankan `guard.php`, lalu menjalankan `php -S` dari folder `public/`. Setiap server memakai `DB_DATABASE` miliknya sendiri dan `CACHE_STORE=array`, sehingga database dan cache-nya tidak tercampur dengan server lain atau server development di port 8000. (`CACHE_PREFIX` tidak cukup: store `file` mengabaikan prefix, jadi semua server berbagi file cache yang sama.) Kalau stub WhatsApp belum berjalan di port 8019, `serve.sh` menyalakannya di latar belakang (log: `.work/wa-stub.log`). Stub tetap hidup setelah server dihentikan; matikan dengan `pkill -f "127.0.0.1:8019"`.

| Panduan | Port | Database | Perintah yang dijalankan `serve.sh` (setelah `env.sh`) |
| --- | --- | --- | --- |
| posisi | 8011 | `dnd_demo_posisi` | `DB_DATABASE=dnd_demo_posisi php -d memory_limit=1G -S 127.0.0.1:8011 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` |
| akses | 8012 | `dnd_demo_akses` | `DB_DATABASE=dnd_demo_akses php -d memory_limit=1G -S 127.0.0.1:8012 …/server.php` |
| aturan | 8013 | `dnd_demo_aturan` | `DB_DATABASE=dnd_demo_aturan php -d memory_limit=1G -S 127.0.0.1:8013 …/server.php` |
| kpimanajerial | 8014 | `dnd_demo_kpimanajerial` | `DB_DATABASE=dnd_demo_kpimanajerial php -d memory_limit=1G -S 127.0.0.1:8014 …/server.php` |
| kpioperasional | 8015 | `dnd_demo_kpioperasional` | `DB_DATABASE=dnd_demo_kpioperasional php -d memory_limit=1G -S 127.0.0.1:8015 …/server.php` |

Untuk menjalankan server di latar belakang (misalnya dari agen), arahkan output ke log lalu hentikan lewat port-nya:

```bash
nohup sh serve.sh kpioperasional > .work/serve-kpioperasional.log 2>&1 &
pkill -f "127.0.0.1:8015"; pkill -f "127.0.0.1:8019"
```

### Stub WhatsApp dan OTP

`wa-stub.php` adalah router `php -S` di `127.0.0.1:8019`. `serve.sh` menyalakannya otomatis; untuk menjalankannya sendiri di terminal terpisah: `sh wa-stub.sh`.

| Route | Fungsi |
| --- | --- |
| `POST /api/v1/messages` | Permintaan yang sama dengan `WhatsAppService::send`. Hanya diterima dengan `Authorization: Bearer <token dummy dari env.sh>` (selain itu 401). Menjawab 200 JSON, lalu menambahkan `{time, time_ms, recipient, text}` ke `.work/wa-outbox.jsonl`. Header tidak pernah disimpan. |
| `GET /last?phone=<nomor>&since=<ms>` | Pesan terakhir untuk nomor itu (ejaan `08…`, `62…` atau `+62…`) yang diterima sejak `since` (milidetik epoch). 404 kalau belum ada. |
| `GET /health` | `wa-stub ok`. |

Aplikasi hanya menyimpan hash OTP, jadi kode aslinya hanya bisa dibaca dari pesan keluar. Di flow:

```js
const since = Date.now(); // tepat sebelum klik "Kirim OTP WhatsApp"
await r.clickStep(page, page.getByRole('button', { name: 'Kirim OTP WhatsApp' })); // r = new rec.Recorder(...)
const otp = await rec.readOtp('0812-0000-0101', { since }); // menunggu sampai 15 detik, lalu error
await page.getByLabel('Kode OTP').fill(otp);
```

`rec.readOtp(phone, { since, timeoutMs })` mengembalikan 6 digit kode dan melempar error kalau tidak ada pesan (stub mati, nomor salah, atau aplikasi tidak mengirim). `rec.lastWaMessage(phone, since)` mengembalikan pesan lengkapnya atau `null`. OTP berlaku sesuai `WA_OTP_EXPIRES_IN` di `.env` (60 detik, teks pesan "Berlaku 1 menit"). Rate limit OTP memakai cache, dan dengan `CACHE_STORE=array` cache itu kosong di setiap request, jadi rekaman yang diulang tidak terkena "Terlalu banyak permintaan OTP".

### Data demo Panduan KPI

Lapisan KPI (`seedKpiLayer()` di `demo_seed.php`) tidak memakai Faker dan tidak menambah user. Bulan mengikuti jam komputer saat `reset.sh` berjalan.

- `kpi_types` 1 Harian, 2 Mingguan, 3 Bulanan, dan `kpi_categories` 1 ADMINISTRATION, 2 REPORTING, 3 MAIN JOB. Id-nya dipaksa karena form Buat Kpi dan widget Checklist KPI memakai id ini.
- Deskripsi KPI: ADMINISTRATION "Timesheet lengkap", "Arsip surat jalan lengkap", "Input penjualan harian ke sistem"; REPORTING "Laporan bulanan tepat waktu", "Laporan stok opname bulanan", "Kirim laporan stok mingguan ke Kepala Toko"; MAIN JOB "Tiket selesai per bulan", "Penerimaan barang sesuai jadwal", "Menjalankan SOP buka dan tutup kasir", "Jumlah transaksi member baru", "Selisih kas akhir shift" (Lower is Better). "Insiden kritikal" sengaja tidak ada, karena GIF Manajerial membuatnya lewat tombol +.
- KPI Bulanan posisi Kasir untuk IFA PURWANTI (`demo_staf_1`) dan ICHA RAHAYU HANDAYANI S.IP (`demo_staf_5`): MAIN JOB 60% (SOP buka/tutup kasir NON dengan subtask "Hitung modal awal kasir" dan "Setor kas akhir shift"; transaksi member baru RESULT plan 40; selisih kas RESULT plan 3), ADMINISTRATION 20% dan REPORTING 20% (masing-masing satu NON). Bulan berjalan belum diisi; bulan lalu sudah diisi dan periodenya sudah ditutup.
- Admin Gudang (PRIMA LURHUR HAKIM, `demo_staf_4`) tidak punya KPI, dan belum ada penilaian bulan berjalan untuknya.
- No. HP WhatsApp palsu: `demo_staf_1` 0812-0000-0101 (login OTP Panduan KPI Operasional) dan `demo_manager` 0812-0000-0003 (login OTP di awal `supervisor-review-flow.gif`, Panduan KPI Manajerial). Keduanya disimpan dalam format `08…` lewat model `User`.

Rekam di bulan yang sama dengan `reset.sh` terakhir. Flow selalu memanggil `rec.resetDb()` lebih dulu, jadi bulan berjalan otomatis ikut.

### 4. Rekam GIF

Di terminal lain:

```bash
node flows/posisi.js perorangan
```

| Flow | Perintah | Pilihan |
| --- | --- | --- |
| Ubah Posisi | `node flows/posisi.js [massal] [perorangan] [timSaya]` | Tanpa argumen, ketiga GIF direkam. |
| Akses Data | `node flows/akses.js [1] [2] [3] [--keep-db]` | 1 = atur admin (Jabatan ADMIN, Akses Data otomatis Badan Usaha Tertentu dengan Area sendiri, lalu diganti GMD dan ULS), 2 = hasil admin, 3 = tim saya. GIF 2 menampilkan hasil GIF 1, jadi rekam `1 2` bersamaan, atau tambahkan `--keep-db` setelah GIF 1 direkam. |
| Aturan Penilaian | `node flows/aturan.js [kehadiran-isi-form] [kehadiran-import] [aturan-versi-baru] [komponen-tambah] [--keep-db]` | Tanpa argumen, keempat GIF direkam. `kehadiran-isi-form` dan `kehadiran-import` memakai periode bawaan aplikasi, yaitu bulan lalu menurut jam komputer. `kehadiran-import` membuat file import `.work/kehadiran-import.xlsx` dari template bulan lalu lewat `flows/aturan-template.php`, dan hanya mengisi karyawan yang belum punya kehadiran bulan itu (`demo_manager` dan `demo_teamleader`). |
| KPI Manajerial | `node flows/kpi-manajerial.js [kpi-flow-lengkap] [kpi-error-required-fields] [kpi-error-kpi-description] [kpi-error-value-plan] [kpi-error-percentage-total] [supervisor-review-flow] [--keep-db]` | Tanpa argumen, keenam GIF direkam dengan akun `demo_manager` (GARDA SITOMPUL) untuk posisi Admin Gudang (PRIMA LURHUR HAKIM). Lima GIF KPI login dengan password di luar rekaman; Month dan Periode dipatok Oktober 2026 (`10/2026`, `2026-10`). `kpi-flow-lengkap` menyimpan KPI MAIN JOB 60 / ADMINISTRATION 20 / REPORTING 20 dan membuat indikator "Insiden kritikal" lewat ikon +. Keempat GIF error tidak menyimpan apa pun dan mencetak jumlah request Livewire, pesan browser dan isi notifikasi ke konsol; `kpi-error-required-fields` mulai dari form yang sudah berisi satu baris KPI di setiap tab (tab tanpa baris dilewati aplikasi tanpa error), jadi langkah solusinya menghasilkan form yang benar-benar lengkap. `supervisor-review-flow` login lewat WhatsApp OTP ke nomor palsu 081200000003 (kode dibaca dari stub dengan `rec.readOtp`), lalu membuat penilaian Oktober 2026 untuk PRIMA LURHUR HAKIM. |
| KPI Operasional | `node flows/kpi-operasional.js [staff-login-flow] [staff-checklist-flow] [--keep-db]` | Tanpa argumen, kedua GIF direkam dengan akun `demo_staf_1` (IFA PURWANTI, Kasir). `staff-login-flow` login lewat WhatsApp OTP ke nomor palsu 081200000101: kode dibaca dari stub (`rec.readOtp`), lalu ditampilkan di kartu "Pesan WhatsApp di HP Anda (contoh)" di samping form (kartu ini hanya ada di rekaman). `staff-checklist-flow` login dengan password di luar rekaman, menampilkan filter yang terbuka di bulan berjalan, pindah ke bulan lalu yang sudah terkunci (Ditutup sejak), kembali ke bulan berjalan, lalu mengisi checklist sampai Total Score 91.00. Jalankan di bulan yang sama dengan reset terakhir (flow selalu reset lebih dulu). |

Setiap flow mencetak satu baris JSON per GIF, berisi path, jumlah frame, durasi dan ukuran file. `--keep-db` melewati reset database. Gunakan opsi ini hanya untuk melanjutkan dari hasil rekaman sebelumnya.

`node flows/aturan.js rebuild <nama-gif>` menyusun ulang GIF dari frame yang sudah ada di `.work/frames/<nama-gif>/`, tanpa browser dan tanpa database.

### 5. Periksa hasil

GIF langsung menimpa file di `public/images/panduan-ubah-posisi/`, `public/images/panduan-akses-data/` dan `public/images/panduan-aturan-penilaian/`. Untuk meninjau hasil sebelum menimpa file di repo, set `GUIDE_GIFS_OUT_DIR`:

```bash
GUIDE_GIFS_OUT_DIR=.work/preview node flows/posisi.js perorangan
```

Cek ukuran dan durasi GIF:

```bash
ffprobe -v error -show_entries stream=width,height -show_entries format=duration -of csv=p=0 ../../public/images/panduan-ubah-posisi/ubah-posisi-perorangan.gif
```

Hasilnya harus `1100,618`. Frame PNG per langkah tersimpan di `.work/frames/<nama-gif>/`. Kalau login gagal, tangkapan layarnya tersimpan di `.work/debug/`.

## Variabel environment

| Variabel | Default | Fungsi |
| --- | --- | --- |
| `CHROMIUM_PATH` | Chromium headless shell di cache Playwright | Path ke binary Chromium yang dipakai. |
| `PLAYWRIGHT_BROWSERS_PATH` | Cache Playwright bawaan OS | Lokasi cache browser Playwright. |
| `GUIDE_GIFS_OUT_DIR` | `public/images/<folder panduan>` | Folder tujuan GIF. |
| `GUIDE_GIFS_WORK_DIR` | `tools/guide-gifs/.work` | Tempat dump database, frame, file import dan screenshot debug. |
| `MYSQL_USER` | `root` | User MySQL untuk `setup-db.sh` dan `reset.sh`. |
| `SIM_Y` | `190` | Posisi vertikal blok simulasi di GIF `aturan-versi-baru`. |
| `WAG_URL`, `WAG_TOKEN` | dipaksa oleh `env.sh` | Selalu stub lokal `http://127.0.0.1:8019` dan token dummy. Tidak bisa diubah dari shell. |

## Kalau UI berubah

- Selector dan urutan langkah ada di `flows/*.js`. Komentar di setiap langkah menjelaskan apa yang direkam dan elemen mana yang diberi kotak merah.
- Nama karyawan di flow (misalnya `IFA PURWANTI` atau `YUNITA USADA`) berasal dari seed Faker. Kalau versi Faker atau isi `demo_seed.php` berubah, nama-nama itu bisa ikut berubah. Sesuaikan flow-nya, lalu jalankan ulang `sh setup-db.sh`.
- Setelah ada migration baru, jalankan ulang `sh setup-db.sh` supaya dump demo mengikuti skema terbaru.

## Membersihkan

Hentikan server dengan Ctrl+C (atau `pkill -f "127.0.0.1:<port>"`), hentikan stub dengan `pkill -f "127.0.0.1:8019"`, lalu hapus database demo kalau sudah tidak dipakai:

```bash
mysql -u root -e "DROP DATABASE IF EXISTS dnd_demo_panduan; DROP DATABASE IF EXISTS dnd_demo_posisi; DROP DATABASE IF EXISTS dnd_demo_akses; DROP DATABASE IF EXISTS dnd_demo_aturan; DROP DATABASE IF EXISTS dnd_demo_kpimanajerial; DROP DATABASE IF EXISTS dnd_demo_kpioperasional;"
```

`.work/wa-outbox.jsonl` berisi kode OTP demo yang sudah terpakai. File ini ada di `.work` (diabaikan git) dan boleh dihapus kapan saja.
