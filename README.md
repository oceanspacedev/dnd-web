<p align="center">
  <img src="public/dnd-purple.png" alt="Logo DnD" width="150">
</p>

<h1 align="center">DnD Web</h1>

<p align="center">
  Aplikasi internal untuk mengelola struktur karyawan, KPI bulanan, aktivitas kerja,
  kehadiran, penilaian, jurnal harian, approval, leaderboard, dan reminder.
</p>

DnD menyediakan dua jalur penggunaan:

- **Admin panel Filament** di `/admin` untuk operasional berbasis web.
- **REST API v1** di `/api/v1` untuk integrasi dengan aplikasi lain.

Bahasa utama aplikasi adalah Bahasa Indonesia dan seluruh perhitungan waktu menggunakan zona waktu `Asia/Jakarta`.

> [!IMPORTANT]
> DnD adalah aplikasi internal. API sudah memakai autentikasi Sanctum, tetapi sebagian besar endpoint bisnis belum menerapkan pemeriksaan role dan ownership per objek. Resource Filament umumnya memakai policy dan scoped query, tetapi custom widget, action, import, dan export belum semuanya memiliki guard yang setara. Jangan membuka aplikasi ke klien yang tidak tepercaya sebelum audit authorization selesai.

## Daftar isi

- [Tujuan dan scope](#tujuan-dan-scope)
- [Fitur utama](#fitur-utama)
- [Role, hierarchy, dan hak akses](#role-hierarchy-dan-hak-akses)
- [Cara kerja aplikasi](#cara-kerja-aplikasi)
- [Arsitektur](#arsitektur)
- [Tech stack](#tech-stack)
- [Persiapan development](#persiapan-development)
- [Konfigurasi environment](#konfigurasi-environment)
- [Menjalankan aplikasi](#menjalankan-aplikasi)
- [API dan dokumentasi](#api-dan-dokumentasi)
- [Scheduler dan reminder KPI](#scheduler-dan-reminder-kpi)
- [Workflow development](#workflow-development)
- [Testing dan quality check](#testing-dan-quality-check)
- [Deployment checklist](#deployment-checklist)
- [Batasan dan technical debt](#batasan-dan-technical-debt)
- [Troubleshooting](#troubleshooting)

## Tujuan dan scope

DnD menyatukan proses pengelolaan performa dan pekerjaan karyawan dalam satu sistem. Alur utamanya dimulai dari struktur organisasi dan hubungan atasan, dilanjutkan dengan pembuatan KPI, pengisian realisasi, input kehadiran dan penilaian, lalu ditampilkan sebagai checklist dan leaderboard.

### Termasuk dalam scope

- Master area, divisi, posisi, role, kategori KPI, dan indikator KPI.
- Data karyawan, struktur atasan, arsip pengguna, serta import/export.
- KPI bulanan, target, realisasi, extra task, penilaian, dan penguncian periode.
- Kehadiran bulanan dan penilaian perilaku karyawan.
- Aktivitas terstruktur harian, mingguan, dan bulanan.
- Jurnal kerja harian berbentuk narasi dan rekap per periode.
- Pengajuan tugas dan alur approve/reject.
- Cutpoint, overopen, dashboard, leaderboard, dan laporan.
- Reminder KPI melalui email dan WhatsApp.
- API internal untuk seluruh domain utama.

### Di luar scope implementasi saat ini

- Payroll, reimbursement, saldo cuti, dan proses HRIS penuh.
- Aplikasi mobile atau frontend publik terpisah; repository ini berisi panel Filament dan API backend.
- SSO dan reset password mandiri. Reset password pada halaman login sengaja dinonaktifkan.
- Approval khusus jurnal harian. Jurnal tidak memiliki status `PENDING`, `APPROVED`, atau `REJECTED`.
- File PDF/DOCX native untuk rekap jurnal. Format saat ini dijelaskan pada bagian [Jurnal harian](#4-jurnal-harian).
- Pipeline CI/CD dan environment Docker siap pakai; keduanya belum tersedia di repository.

## Fitur utama

Ketersediaan modul pada setiap surface saat ini:

| Modul | Panel `/admin` | API v1 | Keterangan |
|---|:---:|:---:|---|
| Dashboard KPI dan leaderboard | Ya | Ya | Leaderboard panel terlihat oleh semua user yang dapat masuk panel |
| KPI, checklist, dan extra task | Ya | Sebagian | API memiliki KPI/detail CRUD dan checklist read; roll-up extra task serta lock mutasi hanya ada di panel |
| Kehadiran | Ya | Ya | Data per karyawan dan periode |
| Penilaian karyawan | Ya | Ya | Empat aspek dengan nilai 0–5 |
| Jurnal harian | Ya | Ya | Jurnal pribadi dan pemantauan tim |
| User dan struktur organisasi | Ya | Ya | Role, area, divisi, posisi, atasan, bulk update posisi, dan panduan mutasi |
| Import/export | Ya | Sebagian | Excel, JSON karyawan, dan berbagai laporan |
| Reminder email/WhatsApp | Pengaturan | Ya | Eksekusi juga tersedia melalui command scheduler |
| Cutpoint | Ya | Ya | Mengurangi nilai leaderboard web |
| Daily/Weekly/Monthly activity | Tidak | Ya | Fitur API-only saat ini |
| Request approval | Tidak | Ya | Fitur API-only saat ini |
| Overopen | Tidak | Ya | Fitur API-only saat ini |

> [!NOTE]
> **Activity** dan **Work Journal** adalah dua konsep berbeda. Activity menyimpan task terstruktur harian, mingguan, dan bulanan beserta status; log bertingkat saat ini khusus daily activity. Work Journal menyimpan uraian pekerjaan bebas per hari untuk riwayat dan rekap.

## Role, hierarchy, dan hak akses

Role awal dari seeder adalah `ADMIN`, `STAFF`, `TEAM LEADER`, `COORDINATOR`, `MANAGER`, `CHIEF`, dan `BOD`.

### Model hierarchy

- `users.approval_id` menunjuk user yang menjadi atasan/approver.
- Scope yang dikelola atasan terdiri dari **bawahan langsung dan satu tingkat di bawahnya**. Scope tidak dihitung rekursif tanpa batas.
- `roles.requires_approval` menentukan apakah form user wajib meminta atasan.
- `requires_approval` bukan pemberi permission. Hak akses tetap ditentukan oleh policy dan query setiap resource.

### Akses efektif panel web

| Aktor | Akses utama |
|---|---|
| `ADMIN` | Seluruh data, master, konfigurasi, jurnal, mutasi lintas divisi, dan seluruh user |
| Atasan (`TEAM LEADER`, `COORDINATOR`, `MANAGER`, `CHIEF`, `BOD`, atau user dengan bawahan) | KPI, kehadiran, penilaian, jurnal tim, serta melihat/mengedit profil dan posisi karyawan dalam scope approval (termasuk aksi massal *Ubah Posisi Massal*) |
| Semua user panel | Melihat leaderboard; widget Checklist KPI menawarkan KPI sendiri serta KPI user dalam approval scope jika ada |
| Pemilik jurnal | CRUD jurnal sendiri pada panel web |
| User yang memiliki bawahan | Dapat melihat jurnal tim sesuai scope |

Semua user aktif dapat masuk ke panel Filament. Resource list umumnya dibatasi oleh policy/scoped query, tetapi custom widget, import, export, dan action harus memiliki guard sendiri. Contohnya, mutasi pada widget `ChecklistKPI` saat ini memeriksa deadline tetapi belum memverifikasi ownership/scope ID yang dikirim oleh Livewire. Jangan menganggap pilihan yang tampil di UI sebagai boundary authorization.

Role `TEAM LEADER`, `CHIEF`, dan `BOD` tidak otomatis memperoleh semua akses manajerial hanya karena nama role; periksa implementasi policy dan action sebelum menambah atau mengubah kemampuan role.

Source of truth untuk scope hierarchy adalah [`app/Services/ApprovalScopeService.php`](app/Services/ApprovalScopeService.php). Policy resource web berada di [`app/Policies`](app/Policies); custom widget/action tetap harus diaudit terpisah.

## Cara kerja aplikasi

```mermaid
flowchart LR
    A[Admin menyiapkan master dan user] --> B[Hubungkan user ke atasan]
    B --> C[ADMIN, MANAGER, atau COORDINATOR membuat KPI]
    C --> D[Karyawan mengisi checklist dan realisasi]
    D --> E[Import kehadiran dan penilaian]
    E --> F[Hitung skor dan leaderboard]
    F --> G[Review, export, dan tindak lanjut]
    D --> H[Reminder KPI terjadwal]
    I[Karyawan menulis jurnal harian] --> J[Atasan memantau jurnal tim]
    J --> K[Rekap jurnal dan ringkasan AI opsional]
```

### 1. Bootstrap organisasi dan user

1. Admin menyiapkan area, divisi, posisi, role, kategori KPI, dan indikator KPI melalui resource master. Nested create di form KPI/User belum seluruhnya mengikuti policy master; lihat technical debt.
2. Admin menentukan role yang membutuhkan atasan melalui `requires_approval`.
3. Setiap user dihubungkan ke atasan melalui `approval_id`.
4. Policy dan scoped query menggunakan hierarchy tersebut untuk membatasi resource panel web; custom action tetap harus melakukan authorization eksplisit.

User dapat dibuat manual, di-import dari Excel, atau disinkronkan dari JSON Talenta melalui panel admin. Import JSON memiliki perilaku khusus:

- User yang sudah ada hanya diperbarui pada email dan nomor HP yang dikirim eksplisit.
- User baru wajib memiliki employee ID, nama, posisi, dan password awal minimal 12 karakter serta maksimal 72 byte. Password hanya diwajibkan unik antar-user baru dalam file import yang sama, bukan terhadap seluruh user di database.
- Role, area, divisi, posisi, approval, dan flag operasional user baru diwarisi dari peer aktif dengan profil posisi/area/divisi yang sama.
- Import ditolak bila profil peer ambigu, supervisor sudah diarsipkan, identifier konflik, atau nomor kontak tidak valid.
- Nomor HP dinormalisasi ke format WhatsApp Indonesia `08...`.

Jalur import JSON yang didukung untuk operasional saat ini adalah panel admin. Lihat keterbatasan endpoint API pada bagian [Batasan dan technical debt](#batasan-dan-technical-debt).

#### Manajemen dan mutasi posisi karyawan di panel

- **Pencarian instan**: Kolom nama lengkap, ID karyawan, kontak, posisi, divisi, area, jabatan, dan approval dapat dicari langsung dari kotak pencarian utama (*global search* tabel).
- **Filter dropdown searchable**: Filter Area, Divisi, Jabatan, Posisi, dan Approval pada tabel dilengkapi kotak pencarian teks langsung di dalam dropdown tanpa perlu menggulir manual.
- **Ubah Posisi Massal (*Bulk Action*)**: Admin dan Atasan dapat mencentang beberapa karyawan sekaligus di tabel, lalu memilih menu **"Ubah Posisi Massal"** untuk memindahkan posisi mereka secara serentak melalui panel slide-over samping (`md`).
- **Izin Atasan Berdasarkan Scope**: Atasan di setiap posisi/level (memiliki bawahan tercatat via `approval_id` / `ApprovalScopeService` atau ber-role `TEAM LEADER`, `COORDINATOR`, `MANAGER`, `CHIEF`, `BOD`) memiliki izin untuk melihat dan mengedit profil serta posisi karyawan bawahannya di menu *"Tim Saya"*.
- **Panduan interaktif web**: Panduan operasional dapat dibaca langsung melalui menu sidebar kiri di `/admin/panduan-ubah-posisi` maupun melalui tombol slide-over panduan di header tabel Karyawan.

### 2. Siklus KPI

1. Admin memelihara master kategori dan indikator KPI melalui resource khusus.
2. `ADMIN`, `MANAGER`, atau `COORDINATOR` membuat KPI bulanan berdasarkan posisi dan scope user.
3. Bobot seluruh kategori KPI dalam satu paket harus berjumlah 100%.
4. User panel mengisi indikator miliknya atau indikator user yang muncul dalam approval scope melalui Checklist KPI.
5. Pada widget panel, non-admin dapat mengubah checklist sampai akhir bulan ditambah grace period `KPI_CHECKLIST_LOCK_DAYS`.
6. Nilai KPI, kehadiran, review, dan cutpoint dirangkum pada leaderboard web.

Deadline tersebut belum ditegakkan oleh endpoint mutasi KPI API. Widget panel juga belum melakukan authorization ulang terhadap ID objek saat mutasi, sehingga keduanya perlu di-hardening sebelum dianggap sebagai boundary keamanan.

Jenis indikator KPI:

- `NON`: checklist selesai/belum selesai dengan hasil 1 atau 0.
- `RESULT` positif: pencapaian dihitung dari `actual / plan`.
- `RESULT` negatif atau *lower is better*: pencapaian dihitung dari `plan / max(actual, 1)`.
- `Extra Task`: pekerjaan pengganti/child KPI detail yang dapat memperbarui hasil indikator induk. Field ini berbeda dari field teks `subtasks` pada KPI detail.

Baseline perhitungan leaderboard web saat ini:

```text
Skor total = KPI (maks. 70)
           + Kehadiran (maks. 15)
           + Review karyawan (maks. 15)
           - Cutpoint
```

Review karyawan terdiri dari `responsiveness`, `problem_solver`, `helpfulness`, dan `initiative`, masing-masing bernilai 0–5. Rumus KPI bersama berada di [`app/Services/KpiScoringService.php`](app/Services/KpiScoringService.php); implementasi leaderboard web berada di [`app/Filament/Widgets/LeaderboardKPI.php`](app/Filament/Widgets/LeaderboardKPI.php).

Panduan visual tersedia di panel:

- `/admin/panduan-kpi-bawahan` untuk operasional karyawan.
- `/admin/panduan-kpi-atasan` untuk alur manajerial.
- `/admin/panduan-ubah-posisi` untuk alur pencarian dan mutasi posisi karyawan.

### 3. Kehadiran, review, cutpoint, dan overopen

- Kehadiran menyimpan hari kerja, keterlambatan kurang/lebih dari 30 menit, sakit, dan data periode.
- Review menyimpan empat dimensi perilaku yang menjadi komponen 15% penilaian.
- Cutpoint adalah potongan poin manual per periode dan dikurangi dari leaderboard web.
- Overopen adalah counter mingguan yang diisi manual untuk keterlambatan daily, weekly, atau monthly; record-nya tidak berelasi langsung ke record activity. Overopen tidak otomatis menghasilkan cutpoint.
- CRUD dan import kehadiran/review pada panel memakai pembatasan scope.
- Scope export belum seragam: export kehadiran saat ini mengambil seluruh tabel, sedangkan export review untuk non-admin hanya mengambil bawahan langsung. Perlakukan file export sebagai data sensitif sampai guard-nya diseragamkan.

### 4. Jurnal harian

Perilaku berikut adalah aturan pada panel web:

- Satu jurnal aktif per user per tanggal divalidasi pada aplikasi.
- Aktivitas wajib diisi; catatan/kendala bersifat opsional.
- Karyawan dapat melihat, mengubah, dan menghapus jurnal sendiri.
- Atasan dapat melihat jurnal bawahan dalam scope, tetapi tidak mengubah atau menghapusnya.
- Admin dapat melihat dan mengelola seluruh jurnal.
- Tersedia filter karyawan, rentang tanggal, pencarian, export seluruh data, dan export pilihan.
- Aksi **Buat Rekap** merangkum jurnal user yang sedang login pada rentang tanggal tertentu.
- Bila `OPENAI_API_KEY` tersedia, rekap mencoba memakai Laravel AI/OpenAI. Bila tidak tersedia atau request AI gagal, sistem memakai ringkasan lokal.

Opsi “PDF” saat ini menghasilkan halaman HTML siap cetak yang dapat disimpan sebagai PDF dari browser. Opsi Word menghasilkan dokumen HTML-compatible dengan ekstensi `.doc`, bukan `.docx` native.

API jurnal belum menegakkan ownership secara konsisten, dapat menerima `user_id` lain, dan validasi satu jurnal per tanggal dapat terlewati pada jalur tertentu. Jangan mengandalkan aturan panel sebagai jaminan authorization API.

### 5. Request approval

Request API secara normal dibuat dengan status `PENDING` dan approver default dari `approval_id` user yang sedang login, bukan dari user lain yang mungkin dikirim melalui payload. Endpoint approve/reject mencatat status `APPROVED` atau `REJECTED`, user pemroses, dan waktu pemrosesan.

Endpoint tersebut ditujukan untuk approver, tetapi implementasi saat ini belum memastikan pemanggil sama dengan `approval_id`, belum mewajibkan status awal `PENDING`, dan endpoint create masih menerima `user_id`/`approval_id` dari request. Anggap alur ini belum aman untuk client yang tidak tepercaya.

Workflow ini berbeda dari `requires_approval` pada role dan berbeda dari jurnal harian.

### 6. Reminder KPI

Admin dapat membuat aturan untuk:

- `pembuatan_kpi`: mengingatkan atasan bila minimal satu bawahan langsung belum mempunyai record KPI apa pun pada bulan berjalan. Satu record kategori sudah membuat bawahan dianggap “sudah dibuatkan KPI”; command belum memvalidasi kelengkapan seluruh paket/kategori.
- `pengisian_kpi`: mengingatkan karyawan yang masih memiliki indikator KPI belum lengkap.

Target `pembuatan_kpi` ditentukan dari relasi `approval_id`, bukan dari permission pembuat KPI. Akibatnya `TEAM LEADER`, `CHIEF`, atau `BOD` dapat menerima reminder/link meskipun policy saat ini hanya mengizinkan `ADMIN`, `MANAGER`, dan `COORDINATOR` membuat KPI.

Setiap aturan menentukan tanggal tenggat, offset hari pengingat, pengiriman setelah tenggat, channel email/WhatsApp, dan template pesan. Placeholder yang tersedia adalah `{nama}`, `{tenggat}`, `{periode}`, dan `{link}`.

Sistem menggunakan lock dan deduplikasi per aturan, user, channel, dan tanggal agar pengiriman yang sudah berhasil tidak dikirim ulang pada hari yang sama.

## Arsitektur

```mermaid
flowchart TB
    Browser --> Filament[Filament dan Livewire /admin]
    Filament --> Policies[Policies dan scoped queries]
    ApiClient[API client] --> Sanctum[Sanctum /api/v1]
    Sanctum --> Controllers[Controllers, Form Requests, API Resources]
    Scheduler[Laravel Scheduler] --> Reminder[kpi:send-reminders]
    Filament --> Services[Domain Services]
    Controllers --> Services
    Policies --> Models[Eloquent Models]
    Services --> Models
    Reminder --> Mail[Email]
    Reminder --> WA[WhatsApp gateway]
    Reminder --> Models
    Models --> DB[(MySQL / MariaDB)]
    Services --> AI[Laravel AI / OpenAI opsional]
```

### Peta source code

| Lokasi | Tanggung jawab |
|---|---|
| `app/Filament/Resources` | CRUD, form, tabel, import/export panel web |
| `app/Filament/Widgets` | Checklist KPI dan leaderboard dashboard |
| `app/Http/Controllers/Api/V1` | Endpoint REST API v1 |
| `app/Http/Requests/Api/V1` | Validasi input API |
| `app/Http/Resources/Api/V1` | Bentuk response JSON API |
| `app/Models` | Model dan relasi Eloquent |
| `app/Policies` | Authorization panel web |
| `app/Services` | Logika domain reusable, scoring, scope, import, dan integrasi |
| `app/Console/Commands` | Reminder dan maintenance KPI |
| `database/migrations` | Evolusi schema database |
| `database/seeders` | Data awal development |
| `routes/api.php` | Seluruh route API v1 |
| `routes/console.php` | Jadwal reminder KPI |
| `tests` | Unit dan feature test |

## Tech stack

| Komponen | Teknologi |
|---|---|
| Backend | PHP >=8.3, Laravel 12 |
| Admin UI | Filament 4, Livewire 3, Mekaya Theme |
| Frontend build | Vite 7, Tailwind CSS 4, Axios |
| Database utama | MySQL/MariaDB |
| API auth | Laravel Sanctum 4 |
| API docs | Dedoc Scramble / OpenAPI |
| Import/export | Laravel Excel 4 dan PhpSpreadsheet 5 |
| AI opsional | Laravel AI dengan provider default OpenAI |
| Test | PHPUnit 11 / Laravel test runner |
| Static analysis | Larastan / PHPStan |

## Persiapan development

### Prasyarat

- Git.
- PHP 8.3 atau lebih baru.
- Composer 2.
- Node.js 24 LTS direkomendasikan; Node.js 22 LTS masih didukung. Jangan memakai Node.js 20 yang sudah EOL.
- MySQL 8+ atau MariaDB yang kompatibel.
- Extension PHP: `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite` (untuk test), `simplexml`, `xml`, `xmlreader`, `xmlwriter`, dan `zip`.

SMTP, WhatsApp gateway, dan OpenAI bersifat opsional untuk development dasar.

### Clone dan dependency

```bash
git clone https://github.com/oceanspacedev/dnd-web.git
cd dnd-web
git switch dev-azka

composer install
cp .env.example .env
php artisan key:generate
npm ci
```

Jangan menjalankan `composer update` hanya untuk setup; gunakan versi dependency yang dikunci oleh `composer.lock`.

### Konfigurasi database

Buat database lokal dan isi koneksinya di `.env`:

```dotenv
APP_NAME=DnD
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dnd
DB_USERNAME=root
DB_PASSWORD=
```

#### Fresh onboarding

Pastikan `.env` menunjuk ke database development yang kosong, lalu jalankan:

```bash
php artisan migrate --seed
php artisan storage:link
```

> [!WARNING]
> Jangan menjalankan `php artisan migrate:fresh`, `migrate:refresh`, atau `db:wipe` pada database yang berisi data. Perintah tersebut menghapus tabel/data.

Seluruh migration dan seeder mendukung bootstrap database baru. Seeder membuat data contoh development, termasuk login lokal `admin` / `complete123`. Ganti password itu segera. Seeder belum idempotent, sehingga hanya boleh dijalankan pada database development kosong dan tidak boleh dijalankan berulang atau di production.

## Konfigurasi environment

Jangan commit `.env` atau credential apa pun ke Git.

| Variabel | Wajib | Fungsi |
|---|:---:|---|
| `APP_KEY` | Ya | Kunci enkripsi Laravel; dibuat dengan `php artisan key:generate` |
| `APP_URL` | Ya | Base URL aplikasi dan link pada reminder |
| `DB_*` | Ya | Koneksi database |
| `CACHE_STORE` | Ya | Cache default Laravel; default proyek `database` |
| `FILESYSTEM_DISK` | Ya | Disk filesystem default; `local` memakai `storage/app/private` |
| `SESSION_DRIVER` | Ya | Penyimpanan session; default proyek `database` |
| `QUEUE_CONNECTION` | Ya | Backend queue; default proyek `database` |
| `KPI_CHECKLIST_LOCK_DAYS` | Tidak | Grace period pengisian KPI setelah akhir bulan; default `5` |
| `KPI_CACHE_TTL_*` | Tidak | TTL cache master kategori, deskripsi, dan posisi; default `300` detik |
| `MAIL_*` | Untuk email | SMTP dan identitas pengirim |
| `WA_API_URL` | Untuk WhatsApp | Endpoint gateway WhatsApp |
| `WA_API_KEY` | Untuk WhatsApp | Bearer credential gateway WhatsApp |
| `WA_CONNECT_TIMEOUT` | Tidak | Timeout koneksi gateway; default `5` detik |
| `WA_API_TIMEOUT` | Tidak | Timeout request gateway; default `15` detik |
| `KPI_REMINDER_CACHE_STORE` | Untuk reminder | Store lock command reminder manual maupun terjadwal; default `kpi_reminders` berbasis database |
| `OPENAI_API_KEY` | Tidak | Mengaktifkan ringkasan AI pada rekap jurnal |
| `OPENAI_URL` | Tidak | Endpoint OpenAI-compatible untuk provider OpenAI |
| `API_VERSION` | Tidak | Versi yang tampil pada OpenAPI; default `0.0.1` |
| `SCRAMBLE_DEV_TOOLS` | Tidak | Menyalakan developer tools pada halaman dokumentasi API |

Gunakan nama konfigurasi Laravel 12 `CACHE_STORE` dan `FILESYSTEM_DISK`. Nama lama `CACHE_DRIVER` dan `FILESYSTEM_DRIVER` tidak lagi digunakan oleh konfigurasi proyek.

Variabel opsional OpenAI dan Scramble sudah tersedia di `.env.example`; biarkan API key kosong bila fitur AI tidak digunakan dan jangan memasukkan credential ke Git.

Untuk local development tanpa SMTP, gunakan:

```dotenv
MAIL_MAILER=log
MAIL_FROM_ADDRESS=dev@example.test
MAIL_FROM_NAME="${APP_NAME}"
```

Repository tidak membawa container Mailhog/Mailpit. Bila memakai SMTP lokal, jalankan servicenya secara terpisah dan sesuaikan `MAIL_HOST` serta `MAIL_PORT`.

AI tidak wajib. Tanpa `OPENAI_API_KEY`, rekap jurnal tetap bekerja dengan generator ringkasan lokal.

## Menjalankan aplikasi

Jalankan backend dan Vite pada terminal terpisah:

```bash
# Terminal 1
php artisan serve
```

```bash
# Terminal 2
npm run dev
```

Buka:

- Aplikasi: `http://127.0.0.1:8000/admin`
- Health check: `http://127.0.0.1:8000/up`
- Dokumentasi API lokal: `http://127.0.0.1:8000/docs/api`

Scheduler tidak wajib untuk menjalankan UI, tetapi harus dijalankan bila sedang mengembangkan reminder:

```bash
php artisan schedule:work
```

Queue default adalah `database` dan migration tabel `jobs` sudah tersedia. Jalankan worker saat fitur yang mengantrekan job digunakan:

```bash
php artisan queue:work
```

Sebagai alternatif, `composer run dev` menjalankan server Laravel, queue listener, log viewer, dan Vite bersama-sama.

## API dan dokumentasi

Base URL API:

```text
http://127.0.0.1:8000/api/v1
```

Login adalah satu-satunya endpoint publik. Endpoint lain berada di balik middleware `auth:sanctum`.

```bash
curl --request POST http://127.0.0.1:8000/api/v1/auth/login \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "login": "<username-atau-email>",
    "password": "<password>",
    "device_name": "Local Development"
  }'
```

Gunakan token dari response sebagai bearer token:

```bash
curl http://127.0.0.1:8000/api/v1/auth/me \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <token>'
```

Referensi API:

- UI OpenAPI: `/docs/api`
- Spesifikasi JSON: `/docs/api.json`
- Route source: [`routes/api.php`](routes/api.php)
- Postman collection: [`dnd_auth_api_postman_collection.json`](dnd_auth_api_postman_collection.json) — koleksi parsial; metadata lama masih menyebut “Modul 1” dan isinya belum mencakup seluruh route API

Dokumentasi Scramble otomatis dapat diakses pada `APP_ENV=local`. Pada production, route dokumentasi saat ini mengembalikan 403 karena belum ada Gate `viewApiDocs`; konfigurasi akses harus dibuat secara eksplisit jika dokumentasi production memang dibutuhkan.

Untuk melihat daftar endpoint aktual:

```bash
php artisan route:list --path=api/v1
```

Untuk membuat atau menimpa `api.json` di root repository dari route aktual:

```bash
php artisan scramble:export
```

Response API umumnya menggunakan bentuk berikut:

```json
{
  "success": true,
  "message": "Operasi berhasil.",
  "data": {},
  "meta": {}
}
```

Endpoint update menggunakan method `PUT`; saat ini tidak ada route `PATCH`.

## Scheduler dan reminder KPI

Scheduler menjalankan `kpi:send-reminders` setiap hari pukul **08:00 WIB**.

### Verifikasi aman tanpa mengirim pesan

```bash
php artisan kpi:send-reminders --dry-run
```

Untuk menguji satu aturan:

```bash
php artisan kpi:send-reminders --setting-id=<id> --dry-run
```

Hapus `--dry-run` hanya setelah mail, WhatsApp, recipient, template, dan tanggal trigger sudah diverifikasi.

### Production cron

Laravel scheduler harus dipanggil setiap menit:

```cron
* * * * * cd /absolute/path/to/dnd-web && /absolute/path/to/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Temukan dan verifikasi path binary PHP dengan `command -v php` pada server. Pastikan log scheduler dipantau dan dirotasi, atau arahkan stdout/stderr ke sistem logging deployment. Jangan membuang error scheduler tanpa monitoring.

Pada deployment multi-server, `KPI_REMINDER_CACHE_STORE` harus menunjuk cache store bersama yang mendukung atomic lock. Migration repository sudah menyediakan tabel cache khusus untuk default store `kpi_reminders`.

Command operasional lain:

| Command | Fungsi |
|---|---|
| `php artisan kpi:clear-cache` | Menghapus cache KPI; saat ini juga melakukan global cache flush |
| `php artisan kpi:clean-duplicates --dry-run` | Menganalisis duplikasi deskripsi KPI yang digunakan pada 2025 tanpa mengubah data |
| `php artisan optimize:clear` | Menghapus cache config, route, event, dan view Laravel |

Jangan menjalankan `kpi:clean-duplicates` tanpa `--dry-run` sebelum backup dan review hasil, karena mode aktual mengubah relasi serta melakukan soft delete data.

## Workflow development

Konvensi branch repository saat README ini diperbarui:

- `dev-azka`: branch integrasi development aktif.
- `main`: branch tujuan promosi/release setelah review.
- Branch pekerjaan: buat dari `dev-azka` dengan pola `feat/<scope>`, `fix/<scope>`, atau `docs/<scope>`.

### Memulai pekerjaan

```bash
git switch dev-azka
git pull --ff-only origin dev-azka
git switch -c feat/<nama-fitur>
```

Gunakan scope kecil dan satu tujuan per branch. Hindari menggabungkan refactor luas, perubahan schema, dan fitur bisnis yang tidak berkaitan dalam satu PR.

### Lokasi perubahan berdasarkan jenis fitur

| Kebutuhan | Lokasi umum |
|---|---|
| Tambah/ubah tabel | `database/migrations` dan `app/Models` |
| Logika bisnis reusable | `app/Services` |
| Hak akses panel | `app/Policies` dan scoped query Filament |
| Fitur panel web | `app/Filament/Resources`, `Pages`, atau `Widgets` |
| Endpoint API | Controller + FormRequest + API Resource + `routes/api.php` |
| Background operation | `app/Console/Commands` dan `routes/console.php` |
| Import/export | `app/Imports`, `app/Exports`, atau `app/Filament/Exports` |
| Verifikasi | `tests/Unit` atau `tests/Feature` |

### Aturan implementasi

- Jangan mengubah schema melalui migration yang sudah pernah berjalan di shared environment; tambahkan migration baru yang reversible dan uji pada database fresh maupun database existing.
- Gunakan `ApprovalScopeService` untuk hierarchy; jangan membuat ulang query bawahan di banyak tempat.
- Ikuti pipeline canonical `LeaderboardKPI` untuk scoring sampai agregasinya benar-benar disentralisasi. `calculateKpiScore()` menghasilkan rasio berbobot, sehingga normalisasikan ke unit persen sebelum meneruskannya ke `calculateFinalKpiScore()`; jangan membuat formula baru di controller atau export.
- Endpoint API baru wajib memanggil policy/Gate atau melakukan ownership check yang setara.
- Validasi input API ditempatkan pada FormRequest dan response publik dibentuk melalui API Resource.
- Perubahan pada scoring harus diuji pada checklist, leaderboard web, API, dan export.
- Perubahan konfigurasi wajib diikuti update `.env.example` dan README tanpa memasukkan secret.
- Fitur scheduler/integrasi harus memiliki mode dry-run atau fake pada test dan harus idempotent.
- Tambahkan test regresi untuk setiap perbaikan bug.

### Definition of Done

Sebelum membuka PR ke `dev-azka`, pastikan:

- Scope bisnis dan aktor yang boleh mengakses sudah jelas.
- Migration memiliki `up()` dan `down()` yang aman.
- Policy web dan authorization API sudah diperiksa.
- Validasi error dan empty state sudah ditangani.
- Test terkait ditambahkan dan full test suite berhasil dijalankan.
- `npm run build` berhasil bila ada perubahan frontend/Filament asset.
- Dokumentasi API/Postman diperbarui bila kontrak API berubah.
- Tidak ada `.env`, token, dump database, data pribadi, atau credential di commit.

## Testing dan quality check

`phpunit.xml` mengunci test ke SQLite in-memory (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), cache/session array, queue sync, dan mail array. Full test suite tidak menggunakan database development dari `.env`.

Jalankan test berikut:

```bash
# Semua test
php artisan test

# Test tertentu
php artisan test --filter=NamaTest
```

Quality check proyek:

```bash
# Laravel formatter
vendor/bin/pint --test

# Static analysis level proyek
vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# Preview refactor Laravel 12; tidak mengubah file
vendor/bin/rector process --dry-run --no-progress-bar

# Validasi dependency metadata
composer validate --strict
composer audit

# Production frontend bundle
npm run build
npm audit
```

Konfigurasi Rector memakai level Laravel 12 (`UP_TO_LARAVEL_120`). Tetap review diff sebelum menerapkan refactor otomatis secara massal.

## Deployment checklist

Repository belum menyediakan pipeline deployment. Minimum checklist untuk server:

1. Gunakan PHP 8.3 atau lebih baru, aktifkan extension yang disyaratkan termasuk `ext-intl` dan `ext-iconv`, lalu jalankan preflight `composer check-platform-reqs --lock --no-dev`.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, database, mail, WhatsApp, dan secret lain dengan benar. Gunakan `CACHE_STORE` serta `FILESYSTEM_DISK`, bukan nama environment lama `CACHE_DRIVER`/`FILESYSTEM_DRIVER`.
3. Jadikan folder `public/` sebagai document root web server.
4. Pastikan `storage/` dan `bootstrap/cache/` writable oleh user web server.
5. Backup database dan file storage, periksa `php artisan migrate:status`, lalu review migration pending. Fresh database maupun database existing didukung.
6. Install dan build:

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader
   npm ci --include=dev
   npm run build
   php artisan migrate --force
   php artisan storage:link
   php artisan optimize
   php artisan filament:optimize
   ```

   Jalankan `php artisan storage:link` pada first deploy atau verifikasi link yang sudah ada sebelum melewati langkah ini.

7. Pasang cron Laravel scheduler setiap menit dan hubungkan error ke monitoring.
8. Verifikasi `/up`, login panel, asset, export, dan reminder dengan dry-run.
9. Review `config/cors.php`; konfigurasi saat ini mengizinkan origin `*`.
10. `bootstrap/app.php` mempercayai forwarded headers dari semua proxy. Pastikan origin hanya dapat diakses melalui reverse proxy/load balancer tepercaya, atau batasi daftar proxy sebelum production.
11. Jangan menjalankan seeder data contoh di production.
12. Jangan membuka `/api/v1` ke klien tak tepercaya sebelum authorization audit selesai.

### Catatan upgrade deployment existing

- Migration kompatibilitas mengganti nama tabel `password_resets` menjadi `password_reset_tokens`, menetapkan `email` sebagai primary key, dan menyelaraskan kolom nama token Sanctum dengan schema Laravel 12/Sanctum 4. Karena reset password dinonaktifkan, token reset lama dibersihkan saat constraint primary key diterapkan.
- Migration baru menyediakan tabel cache (`cache` dan `cache_locks`), `sessions`, serta `jobs` sesuai default Laravel 12. Pastikan tabel bernama sama belum dibuat manual sebelum migration dijalankan.
- Ganti environment `CACHE_DRIVER` menjadi `CACHE_STORE` dan `FILESYSTEM_DRIVER` menjadi `FILESYSTEM_DISK` sebelum menjalankan cache konfigurasi. Verifikasi juga `SESSION_DRIVER=database` dan `QUEUE_CONNECTION=database` beserta worker queue.
- Default prefix cache/Redis dan nama cookie session sekarang mengikuti skeleton Laravel 12. Pengguna akan login ulang dan cache lama tidak lagi terbaca setelah deploy, kecuali nilai lama dipertahankan eksplisit melalui `CACHE_PREFIX`, `REDIS_PREFIX`, dan `SESSION_COOKIE`.
- Root disk `local` sekarang `storage/app/private`. Inventarisasi dan pindahkan file private lama dari `storage/app` ke `storage/app/private` dengan backup terlebih dahulu; jangan memindahkan isi `storage/app/public`.
- Setelah migration dan pemindahan storage, jalankan `php artisan optimize:clear`, lalu ulangi smoke test login, upload/import, export, queue, scheduler, dan reminder dry-run.

## Batasan dan technical debt

Daftar ini adalah batas perilaku aktual, bukan fitur yang dijanjikan:

1. **Authorization API belum setara dengan panel.** Mayoritas controller API hanya bergantung pada token Sanctum. Mutasi jurnal, request approval, KPI, dan domain lain belum konsisten memanggil policy/ownership check; request approval juga belum memverifikasi assigned approver atau transisi dari `PENDING`.
2. **Guard custom panel belum seragam.** Widget `ChecklistKPI` belum meng-authorize ID objek ketika mutasi. Form user memungkinkan `MANAGER`/`COORDINATOR` membuat user atau mengubah bawahan ke role berprivilege termasuk `ADMIN`; import Excel dapat memperbarui/restore user secara luas dan membuat master organisasi. Nested create pada form KPI/User juga dapat membuat indikator/posisi tanpa mengikuti policy resource master. UI visibility bukan pengganti authorization action.
3. **Scoring belum memiliki satu implementasi lintas surface.** Dashboard web, beberapa endpoint API, dan export masih memiliki perbedaan formula, unit, dan grade; cutpoint juga belum selalu dikurangi di API/export. Jadikan pipeline `LeaderboardKPI` sebagai baseline perilaku saat ini sambil memusatkan agregasi ke service.
4. **API KPI hanya mendukung sebagian workflow.** API belum membuat relasi `parent_id` untuk roll-up extra task dan belum menegakkan lock periode pada endpoint mutasi.
5. **API import JSON belum menjadi jalur operasional.** `UserController::importJson()` masih memanggil method service yang tidak tersedia. Gunakan import JSON dari panel admin sampai diperbaiki dan diberi authorization.
6. **Scope export belum konsisten.** Export kehadiran mengambil seluruh tabel, sedangkan export review non-admin hanya mengambil bawahan langsung, bukan seluruh approval scope. Audit sebagai potensi kebocoran data.
7. **Constraint keunikan sebagian masih di application layer.** Contohnya jurnal per user/tanggal dan sejumlah data per user/periode belum semuanya memakai unique constraint database.
8. **Format rekap jurnal bukan file office native.** “PDF” adalah printable HTML dan Word adalah HTML-compatible `.doc`.
9. **Ada rule bisnis berbasis ID/username.** Beberapa policy dan master KPI masih bergantung pada ID atau username tertentu. Jangan mengurutkan ulang seed/master ID tanpa audit.
10. **Role tidak otomatis berarti permission.** Beberapa role organisasi belum dipetakan ke kemampuan manajerial pada policy.
11. **Queue dan infra belum dipaketkan penuh.** Default queue database dan migration tabel `jobs` tersedia, tetapi supervisor/monitoring worker, Docker setup, dan CI pipeline belum tersedia.
12. **API docs production belum dikonfigurasi.** Scramble membatasi docs di luar environment local sampai Gate akses dibuat.
13. **Target reminder belum diselaraskan dengan permission.** Reminder pembuatan KPI dapat dikirim kepada user yang menjadi approver tetapi tidak diizinkan oleh `KpiPolicy` untuk membuat KPI.

Gunakan bagian ini sebagai checklist pertama ketika mengerjakan hardening dan onboarding teknis.

## Troubleshooting

### Composer menolak versi PHP

Pastikan CLI dan FPM sama-sama memakai PHP 8.3 atau lebih baru:

```bash
php -v
composer check-platform-reqs
```

### Composer melaporkan `ext-intl` atau `ext-iconv` tidak tersedia

Install/aktifkan extension yang hilang untuk versi PHP yang digunakan, lalu ulangi `composer install`. Jangan memakai `--ignore-platform-reqs` sebagai solusi environment normal.

### `Vite manifest not found`

```bash
npm ci
npm run build
php artisan optimize:clear
```

### Perubahan config atau route tidak terbaca

```bash
php artisan optimize:clear
```

### `/docs/api` mengembalikan 403

Pastikan development memakai `APP_ENV=local`. Untuk production, buat Gate `viewApiDocs` sebelum membuka dokumentasi.

### Reminder tidak terkirim

1. Jalankan `php artisan kpi:send-reminders --dry-run`.
2. Periksa rule aktif, tanggal tenggat, offset hari, dan tipe reminder.
3. Periksa email/nomor HP user.
4. Periksa `MAIL_*`, `WA_API_URL`, dan `WA_API_KEY`.
5. Periksa `storage/logs/laravel.log` dan tabel log reminder.
6. Pastikan scheduler aktif dan `APP_URL` menghasilkan link yang benar.

### Rekap jurnal tidak memakai AI

Pastikan `OPENAI_API_KEY` valid dan config cache sudah dibersihkan. Tanpa key atau saat provider gagal, fallback lokal memang digunakan dan proses rekap tetap dilanjutkan.

---

README ini mendokumentasikan perilaku pada branch `dev-azka`. Bila implementasi, formula, authorization, atau alur deployment berubah, perbarui README pada PR yang sama.
