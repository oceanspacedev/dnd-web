<p align="center">
  <img src="public/icon.svg" alt="Logo DnD" width="150">
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
> DnD adalah aplikasi internal. API memakai autentikasi Sanctum dan endpoint domain utama menerapkan pemeriksaan role/scope untuk resource yang dapat mengubah atau menampilkan data per karyawan. Resource Filament umumnya memakai policy dan scoped query, tetapi custom widget, action, import, dan export belum semuanya memiliki guard yang setara. Jangan membuka aplikasi ke klien yang tidak tepercaya sebelum audit authorization selesai.

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
- [Deployment](#deployment)
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
- SSO, registrasi publik, dan reset password mandiri. Registrasi serta reset password pada panel sengaja dinonaktifkan.
- Approval khusus jurnal harian. Jurnal tidak memiliki status `PENDING`, `APPROVED`, atau `REJECTED`.
- File PDF/DOCX native untuk rekap jurnal. Format saat ini dijelaskan pada bagian [Jurnal harian](#4-jurnal-harian).
- Pipeline CI/CD penuh (quality gate otomatis sebelum deploy); saat ini GitHub Actions hanya menjaga alur PR `staging -> main`, dan deploy dijalankan VitoDeploy.

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
- `roles.level` adalah label grade jabatan (Staff=10, TL=20, SPV/Coord=30, Manager=40, Chief=50, BOD=60, Admin=100). Level tidak memilih atasan dan tidak mengubah scope approval.
- `divisis.manager_id` mendefinisikan Kepala Divisi / Manager default untuk setiap divisi.
- `approval_rules` (Matriks Aturan Approval) dan [`app/Services/ApprovalResolverService.php`](app/Services/ApprovalResolverService.php) menyarankan Approval Line dari kombinasi Area, Divisi, Posisi, dan Role. Aturan tanpa kriteria diabaikan. Jika beberapa aturan cocok, aturan dengan lebih banyak kriteria menang; bila jumlah kriteria sama, prioritas tertinggi menang; bila masih sama, aturan dengan id lebih kecil dipakai. Kandidat yang membentuk siklus approval dilewati.
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

User dapat dibuat manual, di-import dari Excel, atau disinkronkan dari JSON Talenta melalui panel admin. Endpoint `POST /api/v1/users/import-json` menyediakan alur JSON yang sama khusus untuk user ber-role `ADMIN`. Import JSON memiliki perilaku khusus:

- User yang sudah ada hanya diperbarui pada email dan nomor HP yang dikirim eksplisit.
- User baru wajib memiliki employee ID, nama, posisi, dan password awal minimal 12 karakter serta maksimal 72 byte. Password hanya diwajibkan unik antar-user baru dalam file import yang sama, bukan terhadap seluruh user di database.
- Role, area, divisi, posisi, dan flag operasional user baru diwarisi dari peer aktif dengan profil posisi/area/divisi yang sama. Approval Line ikut peer selama atasan peer masih aktif. Jika peer tidak punya atasan aktif, sistem memakai matriks aturan atau Kepala Divisi.
- Import ditolak bila profil peer ambigu, peer menunjuk atasan nonaktif tanpa pengganti dari matriks, identifier konflik, atau nomor kontak tidak valid.
- Nomor HP dinormalisasi ke format WhatsApp Indonesia `08...`.

Baik panel maupun endpoint API memproses JSON langsung dari upload sementara tanpa menyimpan file bisnis pada filesystem container.

#### Manajemen dan mutasi posisi karyawan di panel

- **Pencarian instan**: Kolom nama lengkap, ID karyawan, kontak, posisi, divisi, area, jabatan, dan approval dapat dicari langsung dari kotak pencarian utama (*global search* tabel).
- **Filter dropdown searchable**: Filter Area, Divisi, Jabatan, Posisi, dan Approval pada tabel dilengkapi kotak pencarian teks langsung di dalam dropdown tanpa perlu menggulir manual.
- **Ubah Posisi Massal (*Bulk Action*)**: Admin dan Atasan dapat mencentang beberapa karyawan sekaligus di tabel, lalu memilih menu **"Ubah Posisi Massal"** untuk memindahkan posisi mereka secara serentak melalui panel slide-over samping (`md`).
- **Ubah Approval Line Massal (*Bulk Action*)**: Admin dapat mencentang beberapa karyawan sekaligus dan memindahkan Approval Line / Atasan mereka ke atasan baru secara serentak.
- **Sinkronisasi Matriks Aturan (*Bulk Action*)**: Admin dapat mengevaluasi dan menyinkronkan kembali atasan karyawan terpilih secara otomatis berdasarkan aturan pada matriks approval dan Kepala Divisi.
- **Izin Atasan Berdasarkan Scope**: Atasan di setiap posisi/level (memiliki bawahan tercatat via `approval_id` / `ApprovalScopeService` atau ber-role `TEAM LEADER`, `COORDINATOR`, `MANAGER`, `CHIEF`, `BOD`) memiliki izin untuk melihat dan mengedit profil serta posisi karyawan bawahannya di menu *"Tim Saya"*.
- **Panduan interaktif web**: Panduan operasional dapat dibaca langsung melalui menu sidebar kiri di `/admin/panduan-ubah-posisi` maupun melalui tombol slide-over panduan di header tabel Karyawan.

### 2. Siklus KPI

1. Admin memelihara master kategori dan indikator KPI melalui resource khusus.
2. `ADMIN`, `MANAGER`, atau `COORDINATOR` membuat KPI bulanan berdasarkan posisi dan scope user.
3. Bobot seluruh kategori KPI dalam satu paket harus berjumlah 100%.
4. User panel mengisi indikator miliknya atau indikator user yang muncul dalam approval scope melalui Checklist KPI.
5. Pada widget panel, non-admin dapat mengubah checklist sampai akhir bulan ditambah grace period `KPI_CHECKLIST_LOCK_DAYS`.
6. Nilai KPI, kehadiran, review, dan cutpoint dirangkum pada leaderboard web.

Deadline tersebut belum ditegakkan oleh endpoint mutasi KPI API. Widget panel juga belum melakukan authorization ulang terhadap ID objek saat mutasi, sehingga keduanya tetap perlu di-hardening sebelum dianggap sebagai boundary keamanan.

Jenis indikator KPI:

- `NON`: checklist selesai/belum selesai dengan hasil 1 atau 0.
- `RESULT` positif: pencapaian dihitung dari `actual / plan`.
- `RESULT` negatif atau *lower is better*: pencapaian dihitung dari `plan / max(actual, 1)`.
- `Extra Task`: pekerjaan pengganti/child KPI detail yang dapat memperbarui hasil indikator induk. Field ini berbeda dari field teks `subtasks` pada KPI detail.

#### Rumus nilai kinerja dan aturan penilaian berversi

```text
Skor total       = Skor KPI + Skor Kehadiran + Skor Penilaian      (bobot KPI + Kehadiran + Penilaian = 100)
                   - Cutpoint                                        (dikurangi di leaderboard web)

Skor KPI         = min(bobot KPI, nilai KPI mentah (%) × bobot KPI / 100)
Skor Penilaian   = total poin penilaian / poin maksimal penilaian × bobot Penilaian
Rumus dasar (%)  = (hari kerja − Σ jumlah komponen yang "mengurangi rumus dasar") / hari kerja × 100
Penalti (poin)   = Σ (jumlah komponen × penalti per hari/kejadian)
Pencapaian akhir = max(0, rumus dasar − penalti)
Skor Kehadiran   = pencapaian akhir / 100 × bobot Kehadiran           (hari kerja 0 → skor 0)
```

Bobot, poin maksimal penilaian, dan aturan setiap komponen kehadiran tidak ditulis di kode, tetapi disimpan sebagai **versi aturan** di tabel `scoring_rules`. Setiap versi berlaku mulai bulan `effective_from` (`Y-m`). Satu periode memakai versi terakhir dengan `effective_from <= periode`, dan versi itu dicari lewat `ScoringRuleService::forPeriode()`. Setiap baris memakai aturan periodenya sendiri, bukan aturan hari ini; export tahunan juga dihitung per bulan. Satu set aturan berlaku untuk semua badan usaha. Cara agregasi KPI, pemotongan cutpoint, dan grade tetap mengikuti masing-masing permukaan (leaderboard web, API, export) seperti sebelumnya.

Komponen kehadiran adalah master data di tabel `attendance_components`. Hitungan komponen bawaan disimpan di kolom lama `attendances.late_less_30`, `late_more_30`, dan `sick_days`. Hitungan komponen lain disimpan di `attendances.component_counts[kode]`, sehingga komponen baru tidak memerlukan perubahan kode.

Versi bawaan dari migration `2026_10_18_090100_create_scoring_rules_table.php`:

| Parameter | v1 "Aturan Lama" (mulai 2000-01) | v2 "Aturan Kehadiran November 2026" (mulai 2026-11) |
| --- | --- | --- |
| Bobot KPI / Kehadiran / Penilaian | 70 / 15 / 15 | 70 / 15 / 15 |
| Poin maksimal penilaian | 20 | 20 |
| Terlambat ≤ 30 menit | 1 poin per kejadian, mengurangi rumus dasar | 1 poin per kejadian (yang dihitung hanya terlambat > 5 menit), mengurangi rumus dasar |
| Terlambat > 30 menit | 3 poin per kejadian, mengurangi rumus dasar | 3 poin per kejadian, mengurangi rumus dasar |
| Sakit | 5 poin per hari, mengurangi rumus dasar (kolom lama berisi sakit + izin) | 0 poin, mengurangi rumus dasar |
| Izin | tidak dihitung | 5 poin per hari, tidak mengurangi rumus dasar |
| Mangkir | tidak dihitung | 5 poin per hari, tidak mengurangi rumus dasar |
| Cuti tahunan, cuti lainnya | tidak dihitung | 0 poin, tidak mengurangi rumus dasar |

Deploy branch ini sebelum data kehadiran November 2026 diisi (sekitar 1 Desember 2026). Bila migration berjalan setelah data kehadiran periode 2026-11 atau sesudahnya sudah diisi dengan kode lama (kolom Sakit = sakit + izin, tanpa izin/mangkir sendiri), v2 otomatis mulai bulan sesudah periode data terakhir itu, dan namanya ikut menyesuaikan (mis. "Aturan Kehadiran Desember 2026"). Dengan begitu data tersebut tidak dinilai ulang diam-diam. Periode sesudah bulan deploy (salah ketik) dan data yang sudah dihapus tidak dihitung. Selama versi itu belum terkunci, super admin masih bisa mengubah bulan berlakunya.

Contoh v2 untuk hari kerja 22, terlambat ≤ 30 menit 2 kali, terlambat > 30 menit 1 kali, sakit 1 hari, dan izin 1 hari:

- Rumus dasar = (22 − 4) / 22 × 100 = 81,82%.
- Penalti = 2×1 + 1×3 + 1×0 + 1×5 = 10 poin.
- Pencapaian akhir = 81,82% − 10 = 71,82%.
- Skor Kehadiran = 71,82% × 15 = 10,77.

Cara mengubah aturan (khusus super admin, menu **Settings**):

1. Jika perlu komponen baru, buka **Komponen Kehadiran** lalu tambah komponen dengan kode huruf kecil, nama, dan satuan hari atau kejadian. Komponen yang sudah dipakai versi aturan atau data kehadiran tidak bisa dihapus dan satuannya tidak bisa diubah. Nonaktifkan saja, karena versi lama yang memakainya tetap berlaku. Komponen yang masih dinilai (penalti > 0 atau mengurangi rumus dasar) oleh versi yang dipakai bulan lalu, bulan berjalan, atau versi terjadwal belum bisa dinonaktifkan; buat dulu versi baru yang tidak menilainya. Nama komponen juga tampil sebagai judul kolom template/export Excel; unduh ulang template setelah mengganti nama agar judulnya ikut berubah.
2. Buka **Aturan Penilaian**, lalu pilih **Duplikat jadi versi baru** pada versi terakhir atau **Buat Versi Baru**. Komponen yang sudah nonaktif tidak ikut disalin ke versi baru (form menampilkan peringatan).
3. Pilih bulan berlaku. Paling awal adalah bulan berjalan, dan defaultnya bulan kosong berikutnya.
4. Atur bobot (totalnya harus 100), poin maksimal penilaian (paling kecil 20 = 4 aspek × nilai 5, agar skor Penilaian tidak melebihi bobotnya), serta untuk setiap komponen: apakah mengurangi rumus dasar dan berapa penalti per hari atau kejadian.
5. Periksa bagian **Simulasi**. Bagian ini membandingkan isian form yang belum disimpan dengan versi yang berlaku bulan ini, dan menandai skor yang melebihi bobotnya. Setelah itu simpan.

Setelah versi baru disimpan:

- Bulan sebelum `effective_from` tetap memakai versi lamanya, sehingga nilai lama tidak berubah.
- Versi yang terjadwal atau berlaku mulai bulan berjalan masih bisa diubah atau dihapus.
- Setelah bulan pertamanya berakhir, versi menjadi **terkunci**. Hanya nama dan catatannya yang bisa diubah, dan versi tidak bisa dihapus. Untuk mengubah aturan, buat versi baru.
- Penjaga ini juga ada di model, jadi berlaku untuk panel, tinker, dan seeder: `ScoringRule` mengunci versi; `AttendanceComponent` menolak perubahan kode, kolom lama, dan satuan komponen yang sudah dipakai, penghapusan komponen yang sudah dipakai, serta penonaktifan komponen yang masih dinilai versi bulan lalu, bulan berjalan, atau terjadwal. Penulisan langsung lewat query builder atau SQL tidak dijaga dan harus diikuti `ScoringRuleService::clearCache()`.

Aplikasi mobile membaca aturan lewat endpoint baca saja `GET /api/v1/master/scoring-rules`, `GET /api/v1/master/scoring-rules/resolve?periode=Y-m`, dan `GET /api/v1/master/attendance-components`. Catatan versi (`notes`) bersifat internal dan hanya dikirim ke super admin.

`POST` dan `PUT /api/v1/attendances` menerima field lama (`late_less_30`, `late_more_30`, `sick_days`) dan objek opsional `components` (`{kode: jumlah}`). Sejak November 2026 `sick_days` hanya berisi sakit; izin dikirim di `components.izin`. Respons sukses memuat `warnings` (array, kosong bila tidak ada) yang menyebut komponen yang dinilai aturan periode data tetapi tidak dikirim dan bernilai 0, misalnya klien lama yang hanya mengirim field lama untuk periode November 2026. Data tetap disimpan. `PUT` menolak (422) pemindahan periode data yang menyimpan `sick_days` > 0 dari aturan lama ke periode yang mencatat izin sendiri, kecuali `sick_days` (sakit saja) dan `components.izin` ikut dikirim.

`GET /api/v1/evaluations/user/{id}/score?periode=` mencocokkan periode bulanan yang terbaca (`2026-1`, `2026-01`, `2026-01-15`) persis dengan bentuk `Y-m`-nya, sama seperti leaderboard. Periode berupa tahun saja tetap dicocokkan dengan prefix. `penalty_points` di respons kehadiran dan evaluasi dibulatkan 2 desimal (bilangan bulat tetap dikirim sebagai integer).

Aturan dan komponen di-cache dengan kunci berversi. Observer menaikkan versinya setiap kali ada perubahan lewat Eloquent. Penulisan langsung lewat query builder atau SQL harus diikuti `ScoringRuleService::clearCache()`.

Review karyawan terdiri dari `responsiveness`, `problem_solver`, `helpfulness`, dan `initiative`, masing-masing bernilai 0–5. Lokasi kode terkait:

- Rumus: [`app/Services/PerformanceScoreCalculator.php`](app/Services/PerformanceScoreCalculator.php).
- Resolusi versi: [`app/Services/ScoringRuleService.php`](app/Services/ScoringRuleService.php).
- Rumus KPI bersama: [`app/Services/KpiScoringService.php`](app/Services/KpiScoringService.php).
- Leaderboard web: [`app/Filament/Widgets/LeaderboardKPI.php`](app/Filament/Widgets/LeaderboardKPI.php).

Panduan visual tersedia di panel:

- `/admin/panduan-kpi-bawahan` untuk operasional karyawan.
- `/admin/panduan-kpi-atasan` untuk alur manajerial.
- `/admin/panduan-ubah-posisi` untuk alur pencarian dan mutasi posisi karyawan.
- `/admin/panduan-akses-data` untuk akses data per badan usaha, menu Karyawan, dan menu Tim Saya (admin dan atasan).
- `/admin/panduan-aturan-penilaian` untuk cara nilai kinerja dihitung, pengisian/import Kehadiran, dan pengelolaan versi aturan penilaian (semua pengguna; bagian pengaturan khusus super admin).

### 3. Kehadiran, review, cutpoint, dan overopen

- Kehadiran menyimpan periode, hari kerja, dan hitungan per komponen kehadiran (master **Komponen Kehadiran**). Terlambat ≤ 30 menit, terlambat > 30 menit, dan sakit memakai kolom lama; izin, mangkir, cuti, dan komponen baru disimpan di `attendances.component_counts`. Sebelum November 2026 kolom sakit berisi sakit + izin. Bila periode data yang menyimpan Sakit/Izin diganti ke periode yang mencatat izin sendiri (mis. Oktober ke November 2026), form Kehadiran meminta HR mengisi Sakit dan Izin secara terpisah lalu mencentang konfirmasi, agar penalti izin tidak hilang.
- Review menyimpan empat dimensi perilaku dengan skala 0–5. Bobotnya mengikuti versi aturan penilaian periode tersebut (bawaan 15%).
- Cutpoint adalah potongan poin manual per periode dan dikurangi dari leaderboard web.
- Overopen adalah counter mingguan yang diisi manual untuk keterlambatan daily, weekly, atau monthly; record-nya tidak berelasi langsung ke record activity. Overopen tidak otomatis menghasilkan cutpoint.
- CRUD dan import kehadiran/review pada panel memakai pembatasan scope.
- Export, template, dan import kehadiran/review mengikuti cakupan yang sama dengan daftarnya di menu (super admin semua, admin badan usaha sesuai badan usahanya, atasan timnya). File export tetap data sensitif.

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

API jurnal kini membatasi list/detail/mutasi ke pemilik atau scope atasan dan mengabaikan `user_id` yang mencoba mengubah pemilik saat create. Validasi satu jurnal per tanggal masih perlu constraint database serta pengecekan ulang pada jalur update.

### 5. Request approval

Request API secara normal dibuat dengan status `PENDING` dan approver default dari `approval_id` user yang sedang login, bukan dari user lain yang mungkin dikirim melalui payload. Endpoint approve/reject mencatat status `APPROVED` atau `REJECTED`, user pemroses, dan waktu pemrosesan.

Endpoint tersebut ditujukan untuk approver. API kini memaksa requester/approver dari user terautentikasi, memastikan hanya approver assigned (atau admin) yang dapat approve/reject, dan membatasi perubahan/penghapusan pada pemilik/admin serta status `PENDING`.

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
    Filament --> Files[(Filesystem disk: local, public, atau S3)]
    Controllers --> Files
    Worker[Queue worker] --> Files
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
| Database | MySQL (default `.env.example`) atau MariaDB; SQLite in-memory untuk test |
| Filesystem | Disk Laravel `local` / `public` (default) atau Flysystem S3-compatible |
| API auth | Laravel Sanctum 4 |
| API docs | Dedoc Scramble / OpenAPI |
| Import/export | Laravel Excel 4 dan PhpSpreadsheet 5 |
| AI opsional | Laravel AI dengan provider default OpenAI |
| Queue | Laravel Horizon 5; worker production `php artisan horizon` |
| Logs | [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer) di `/log-viewer` |
| Deployment | VitoDeploy (Nginx + PHP-FPM), PHP 8.3; Redis untuk Horizon |
| Test | PHPUnit 11 / Laravel test runner |
| Static analysis | Larastan / PHPStan |

## Persiapan development

### Prasyarat

- Git.
- PHP 8.3 atau lebih baru.
- Composer 2.
- Node.js 24 LTS direkomendasikan; Node.js 22 LTS masih didukung. Jangan memakai Node.js 20 yang sudah EOL.
- MySQL 8+ (atau MariaDB yang kompatibel) untuk development dan deployment.
- Extension PHP: `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, dan `zip`.

SMTP, WhatsApp gateway, dan OpenAI bersifat opsional untuk development dasar.

### Clone dan dependency

```bash
git clone https://github.com/oceanspacedev/dnd-web.git
cd dnd-web
git switch staging

composer install
cp .env.example .env
php artisan key:generate
npm ci
```

Jangan menjalankan `composer update` hanya untuk setup; gunakan versi dependency yang dikunci oleh `composer.lock`.

### Konfigurasi database

`.env.example` memakai MySQL. Buat database kosong lalu sesuaikan koneksi:

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
| `DB_CONNECTION` / `DB_*` | Ya | Driver dan koneksi database; `DB_HOST` dan credential hanya diperlukan untuk database server |
| `CACHE_STORE` | Ya | Cache default Laravel; default proyek `database` |
| `FILESYSTEM_DISK` | Ya | Disk filesystem default; `local` (private), `public` (`/storage`), atau `s3` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | Untuk S3 | Credential runtime dengan akses minimum ke bucket aplikasi |
| `AWS_BUCKET` / `AWS_DEFAULT_REGION` | Untuk S3 | Bucket dan region object storage |
| `AWS_ENDPOINT` / `AWS_URL` | Untuk S3-compatible | Endpoint API dan base URL bucket |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Untuk S3-compatible | Aktifkan bila provider memerlukan URL path-style |
| `AWS_*_CHECKSUM_*` | Tidak | Mode `when_required` untuk kompatibilitas provider S3 non-AWS |
| `SESSION_DRIVER` | Ya | Penyimpanan session; default proyek `database` |
| `QUEUE_CONNECTION` | Ya | Backend queue; default proyek `redis`. Worker: `php artisan horizon` |
| `HORIZON_PATH` | Tidak | UI Horizon; default `horizon` |
| `LOG_VIEWER_ENABLED` / `LOG_VIEWER_PATH` | Tidak | UI [Log Viewer](https://github.com/opcodesio/log-viewer); default `/log-viewer` |
| `REDIS_PASSWORD` | Jika Redis dijalankan | Password Redis; kosong hanya aman bila Redis hanya terbuka di localhost |
| `KPI_CHECKLIST_LOCK_DAYS` | Tidak | Grace period pengisian KPI setelah akhir bulan; default `5` |
| `KPI_CACHE_TTL_*` | Tidak | TTL cache master kategori/deskripsi/posisi (`300` dtk), skor leaderboard (`60` dtk), dan aturan penilaian/komponen kehadiran (`KPI_CACHE_TTL_SCORING_RULES_SECONDS`, `3600` dtk) |
| `MAIL_*` | Untuk email | SMTP dan identitas pengirim; tombol login **Email** (kode OTP + magic link) di `/admin/login` hanya muncul bila `MAIL_MAILER` bukan `log`/`array` |
| `EMAIL_LOGIN_EXPIRES_IN` | Tidak | Masa berlaku kode dan magic link login email; default `600` detik |
| `WAG_URL` | Untuk WhatsApp | Endpoint gateway WhatsApp |
| `WAG_TOKEN` | Untuk WhatsApp | Bearer credential gateway WhatsApp |
| `WA_CONNECT_TIMEOUT` | Tidak | Timeout koneksi gateway; default `5` detik |
| `WA_API_TIMEOUT` | Tidak | Timeout request gateway; default `15` detik |
| `KPI_REMINDER_CACHE_STORE` | Untuk reminder | Store lock command reminder manual maupun terjadwal; default `kpi_reminders` berbasis database |
| `OPENAI_API_KEY` | Tidak | Mengaktifkan ringkasan AI pada rekap jurnal |
| `OPENAI_URL` | Tidak | Endpoint OpenAI-compatible untuk provider OpenAI |
| `API_VERSION` | Tidak | Versi yang tampil pada OpenAPI; default `0.0.1` |
| `SCRAMBLE_DEV_TOOLS` | Tidak | Menyalakan developer tools pada halaman dokumentasi API |

Blok inti `.env.example` mengikuti skeleton Laravel 12, dengan `DB_CONNECTION=mysql` sebagai default proyek. Nama lama `CACHE_DRIVER`, `FILESYSTEM_DRIVER`, dan `BROADCAST_DRIVER` tidak digunakan oleh konfigurasi proyek.

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

Queue default adalah `redis`. Jalankan Horizon saat fitur yang mengantrekan job digunakan:

```bash
php artisan horizon
```

Sebagai alternatif, `composer run dev` menjalankan server Laravel, Horizon, log viewer, dan Vite bersama-sama.

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

Error validasi FormRequest memakai bentuk `{"success": false, "message": "Validasi gagal.", "errors": {...}}` dengan status 422, juga tanpa header `Accept: application/json`.

### Perubahan kontrak API untuk aplikasi mobile (akses data dan aturan penilaian)

- **Login dan `/auth/me`**: ringkasan akses data (`scope`, `area_ids`, `can_access_employees`, `can_access_team`) ada di kunci tingkat atas `data_access` pada kedua respons. `user.data_access` (di login: `data.user.data_access`; di `/auth/me`: `data.data_access`) adalah nilai mentah `all`, `selected`, atau `null`.
- **`scoring_rule`**: semua endpoint (presensi, review, evaluasi, ringkasan KPI, analytics) mengirim ringkasan dengan bentuk yang sama: `id`, `name`, `effective_from`, `kpi_weight`, `attendance_weight`, `review_weight`, `review_max_points`. Resource lengkap `/master/scoring-rules` dan `/resolve` juga memuat semua kunci itu. Review tetap mengirim `scoring_rule_id`. `/resolve` untuk versi tersimpan sama persis dengan item `/master/scoring-rules` untuk id yang sama.
- **`POST`/`PUT /attendances`**: `periode` harus terbaca sebagai tahun-bulan (`2026-11`, `2026-1`, `2026-11-15`, atau tanggal-jam ISO-8601; disimpan sebagai `Y-m`) dengan tahun 2000–2100, `work_days` 0–31, dan setiap hitungan 0–999. Respons GET mengirim `components` sebagai `{kode: {name, unit, count}}`, sedangkan input `components` adalah `{kode: jumlah}`. Entri input berbentuk objek dari respons GET diabaikan, sehingga body GET bisa diubah lalu dikirim ulang utuh. Bila `count` di salinan itu diubah, nilainya tidak dipakai dan disebut di `warnings`. `PUT` menerima periode tersimpan yang dikirim balik tanpa diubah, termasuk data lama di luar rentang (mis. `1900-01`).
- **`POST /kpis`**: `details[].parent_id` ditolak (422, `errors.details.N.parent_id`) karena KPI baru belum punya detail. Tambahkan sub-detail lewat `POST /kpis/{id}/details`.
- **`GET /kpis/user/{id}/summary`**: selain super admin, id karyawan yang tidak ada atau sudah dihapus mendapat 403 seperti karyawan di luar cakupan, bukan 404.
- **`GET /master/attendance-components?active=`** menerima `1`/`0` dan `true`/`false`. `GET /master/scoring-rules/resolve?periode=` tetap memakai format ketat `Y-m`, sama seperti endpoint analytics.

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

### Production scheduler

Laravel scheduler harus dipanggil setiap menit:

```cron
* * * * * cd /absolute/path/to/dnd-web && /absolute/path/to/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Temukan dan verifikasi path binary PHP dengan `command -v php` pada server. Pastikan log scheduler dipantau dan dirotasi, atau arahkan stdout/stderr ke sistem logging deployment. Jangan membuang error scheduler tanpa monitoring.

Pada VitoDeploy, buat cron ini lewat menu Cron Jobs situs; tepat satu entri per server.

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

- `staging`: branch integrasi development aktif; setiap push di-deploy otomatis ke server staging (lihat [Deployment](#deployment)).
- `main`: branch release; setiap merge di-deploy otomatis ke production. `main` diproteksi: tidak menerima push langsung, dan hanya menerima pull request **dari `staging`** (check GitHub Actions `PR dari staging` di [`.github/workflows/pr-to-main.yml`](.github/workflows/pr-to-main.yml) wajib hijau, berlaku juga untuk admin). Fitur tidak pernah masuk `main` tanpa lewat staging dulu.
- Branch pekerjaan: buat dari `staging` dengan pola `feat/<scope>`, `fix/<scope>`, atau `docs/<scope>`; PR ke `staging`, bukan ke `main`.

### Memulai pekerjaan

```bash
git switch staging
git pull --ff-only origin staging
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
- Bobot, skor Kehadiran, dan skor Penilaian selalu dihitung lewat `ScoringRuleService::forPeriode()` + `PerformanceScoreCalculator` dengan periode record itu sendiri, jangan dengan rumus atau bobot yang ditulis langsung. Agregasi KPI masih per permukaan; ikuti pipeline `LeaderboardKPI` sampai agregasinya disentralisasi. `calculateKpiScore()` menghasilkan rasio berbobot, sehingga normalisasikan ke unit persen sebelum meneruskannya ke `calculateFinalKpiScore()`; jangan membuat formula baru di controller atau export.
- Endpoint API baru wajib memanggil policy/Gate atau melakukan ownership check yang setara.
- Validasi input API ditempatkan pada FormRequest dan response publik dibentuk melalui API Resource.
- Perubahan pada scoring harus diuji pada checklist, leaderboard web, API, dan export.
- Perubahan konfigurasi wajib diikuti update `.env.example` dan README tanpa memasukkan secret.
- Fitur scheduler/integrasi harus memiliki mode dry-run atau fake pada test dan harus idempotent.
- Tambahkan test regresi untuk setiap perbaikan bug.

### Definition of Done

Sebelum membuka PR ke `staging`, pastikan:

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

## Deployment

Aplikasi di-deploy sebagai aplikasi Laravel biasa (Nginx + PHP-FPM, MySQL/MariaDB, Redis untuk Horizon) lewat **VitoDeploy**, tanpa Docker. Dua situs Vito dari repository yang sama:

| Situs Vito | Branch | Deploy saat |
|---|---|---|
| staging | `staging` | push/merge ke `staging` |
| production | `main` | merge PR `staging -> main` |

### Deploy script

**Deployment Script** kedua situs di Vito:

```bash
bash deploy.sh
```

[`deploy.sh`](deploy.sh) menarik branch checkout situs itu (`git pull --ff-only`), `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`, lalu `php artisan horizon:terminate` agar worker memuat kode baru. Nyalakan **Auto Deployment** pada kedua situs, sehingga push ke `staging` men-deploy staging dan merge ke `main` men-deploy production. Deploy pertama jalankan manual dari Vito setelah `.env` terisi.

### Kebutuhan server

- PHP 8.3+ dengan extension `intl`, `iconv`, `redis` (`phpredis`), `gd`/`imagick`, dan Node.js 20+ untuk build Vite.
- MySQL 8+ atau MariaDB, Redis (Horizon). `.env` staging dan production harus berbeda: database, `APP_URL`, `APP_KEY`.
- **Worker**: daemon/supervisor Vito untuk `php artisan horizon` (tepat satu per server).
- **Scheduler**: cron setiap menit, lihat [Production scheduler](#production-scheduler).
- Setelah deploy pertama: `/up`, login panel, upload/import, export queue, dan `php artisan kpi:send-reminders --dry-run`. Aktifkan backup database terjadwal dan uji restore. Review `config/cors.php` (saat ini origin `*`).

### Catatan upgrade deployment existing

- Migration kompatibilitas mengganti nama tabel `password_resets` menjadi `password_reset_tokens`, menetapkan `email` sebagai primary key, dan menyelaraskan kolom nama token Sanctum dengan schema Laravel 12/Sanctum 4. Karena reset password dinonaktifkan, token reset lama dibersihkan saat constraint primary key diterapkan.
- Migration baru menyediakan tabel cache (`cache` dan `cache_locks`), `sessions`, serta `jobs` sesuai default Laravel 12. Pastikan tabel bernama sama belum dibuat manual sebelum migration dijalankan.
- Ganti environment `CACHE_DRIVER` menjadi `CACHE_STORE` dan `FILESYSTEM_DRIVER` menjadi `FILESYSTEM_DISK` sebelum menjalankan cache konfigurasi. Default proyek memakai session/cache `database`, queue Redis + Horizon, dan filesystem `local`. Deployment yang sebelumnya mengandalkan Redis+S3 untuk session/cache/disk harus mengisi `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `FILESYSTEM_DISK=s3`, dan `SESSION_CONNECTION=session` secara eksplisit. Pengguna dari session lama akan perlu login kembali satu kali.
- Default prefix cache/Redis dan nama cookie session sekarang mengikuti skeleton Laravel 12. Pengguna akan login ulang dan cache lama tidak lagi terbaca setelah deploy, kecuali nilai lama dipertahankan eksplisit melalui `CACHE_PREFIX`, `REDIS_PREFIX`, dan `SESSION_COOKIE`.
- Root disk `local` Laravel 12 berada di `storage/app/private`, sedangkan instalasi lama dapat menyimpan file langsung di `storage/app`. Bila pindah ke S3, inventarisasi seluruh `storage/app`, lalu salin file bisnis private/public ke bucket dengan prefix serta visibility yang sesuai; verifikasi checksum/jumlah object dan backup sumber terlebih dahulu. File import dan `livewire-tmp` bersifat sementara dan tidak perlu dimigrasikan.
- Bila histori export lama harus tetap dapat diunduh setelah pindah ke S3, pindahkan object `filament_exports/{export-id}` dan ubah `exports.file_disk` dari `local` ke `s3` hanya setelah tiap object terverifikasi. Jika histori tidak dipertahankan, hapus/expire record dan file lama sesuai kebijakan retensi.
- Setelah mengubah disk atau driver session/cache/queue, jalankan `php artisan app:storage-probe` dan `php artisan optimize:clear`, lalu ulangi smoke test login, upload/import, export, queue, scheduler, dan reminder dry-run.
- Migration akses data `2026_10_17_090000_add_data_access_to_users_table` menambah `users.data_access` (default `all`, sehingga admin lama tetap melihat seluruh badan usaha) dan pivot `user_area_access`. Bila migration ini di-rollback, admin dengan akses `selected` beserta badan usahanya disimpan di tabel `user_area_access_rollback_backup` dan dipulihkan otomatis saat `migrate` berikutnya, agar admin badan usaha tertentu tidak diam-diam menjadi super admin. Jangan hapus tabel itu selama rollback. Admin yang dibuat atau dipromosikan selama rollback mendapat default `all` seperti saat deploy pertama; periksa daftar admin setelah migrate ulang.

## Batasan dan technical debt

Daftar ini adalah batas perilaku aktual, bukan fitur yang dijanjikan:

1. **Authorization API belum setara dengan panel.** KPI, kehadiran, review, cutpoint, activity, overopen, jurnal, request approval, user, dan reminder sudah memiliki guard role/scope pada endpoint utama. Residual audit masih diperlukan untuk leaderboard/export analytics, custom widget, import/export, dan endpoint domain lama lainnya.
2. **Guard custom panel belum seragam.** Widget `ChecklistKPI` belum meng-authorize ID objek ketika mutasi. Form user memungkinkan `MANAGER`/`COORDINATOR` membuat user atau mengubah bawahan ke role berprivilege termasuk `ADMIN`; import Excel dapat memperbarui/restore user secara luas dan membuat master organisasi. Nested create pada form KPI/User juga dapat membuat indikator/posisi tanpa mengikuti policy resource master. UI visibility bukan pengganti authorization action.
3. **Scoring belum memiliki satu implementasi lintas surface.** Dashboard web, beberapa endpoint API, dan export masih memiliki perbedaan formula, unit, dan grade; cutpoint juga belum selalu dikurangi di API/export. Jadikan pipeline `LeaderboardKPI` sebagai baseline perilaku saat ini sambil memusatkan agregasi ke service.
4. **API KPI hanya mendukung sebagian workflow.** API belum membuat relasi `parent_id` untuk roll-up extra task dan belum menegakkan lock periode pada endpoint mutasi.
5. **Scope export belum konsisten.** Export kehadiran mengambil seluruh tabel, sedangkan export review non-admin hanya mengambil bawahan langsung, bukan seluruh approval scope. Audit sebagai potensi kebocoran data.
6. **Constraint keunikan sebagian masih di application layer.** Contohnya jurnal per user/tanggal dan sejumlah data per user/periode belum semuanya memakai unique constraint database.
7. **Format rekap jurnal bukan file office native.** “PDF” adalah printable HTML dan Word adalah HTML-compatible `.doc`.
8. **Ada rule bisnis berbasis ID/username.** Beberapa policy dan master KPI masih bergantung pada ID atau username tertentu. Jangan mengurutkan ulang seed/master ID tanpa audit.
9. **Role tidak otomatis berarti permission.** Beberapa role organisasi belum dipetakan ke kemampuan manajerial pada policy.
10. **CI dan monitoring infra belum dipaketkan penuh.** Horizon dan scheduler dijalankan Vito, tetapi automated quality gate sebelum deployment, alert worker, dan backup off-site tetap harus dikonfigurasi pada platform.
11. **API docs production belum dikonfigurasi.** Scramble membatasi docs di luar environment local sampai Gate akses dibuat.
12. **Target reminder belum diselaraskan dengan permission.** Reminder pembuatan KPI dapat dikirim kepada user yang menjadi approver tetapi tidak diizinkan oleh `KpiPolicy` untuk membuat KPI.

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
4. Periksa `MAIL_*`, `WAG_URL`, dan `WAG_TOKEN`.
5. Periksa `storage/logs/laravel.log` dan tabel log reminder.
6. Pastikan scheduler aktif dan `APP_URL` menghasilkan link yang benar.

### Rekap jurnal tidak memakai AI

Pastikan `OPENAI_API_KEY` valid dan config cache sudah dibersihkan. Tanpa key atau saat provider gagal, fallback lokal memang digunakan dan proses rekap tetap dilanjutkan.

---

README ini mendokumentasikan perilaku pada branch `staging`. Bila implementasi, formula, authorization, atau alur deployment berubah, perbarui README pada PR yang sama.
