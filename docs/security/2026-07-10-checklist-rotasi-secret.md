# Checklist Rotasi Secret — LAMASY

**Tanggal:** 10 Juli 2026 · **Untuk:** owner (Ignatius)
**Kenapa:** beberapa secret sempat tercetak sebagian di terminal/chat selama sesi kerja. Ganti dengan nilai baru supaya nilai lama tak berlaku lagi.

> ⚠️ **Aturan emas:** semua nilai secret ada di **`master/config/db.php` DI SERVER** (file ini *gitignored* — tak pernah masuk repo). Edit lewat **hPanel → File Manager** atau SSH. Simpan **backup db.php lama** dulu sebelum ubah apa pun, supaya bisa balik kalau ada yang salah.

> ⚠️ **Urutan penting.** DB_PASS & SMTP_PASS bikin bagian situs **langsung mati** kalau nilai di provider sudah diganti tapi db.php belum di-update. Untuk dua itu: **ganti di provider → SEGERA update db.php** dalam satu tarikan napas. Lakukan saat sepi (bukan jam ramai).

---

## Persiapan (5 menit)
- [ ] hPanel → File Manager → buka `.../public_html/lamasy/master/config/db.php`
- [ ] **Download / salin isinya** sebagai backup (`db.php.bak`) ke tempat aman di luar server
- [ ] Siapkan generator password kuat (mis. bitwarden.com/password-generator) — pakai 20+ karakter acak

---

## 1. ANTHROPIC_API_KEY (paling mudah, nol downtime) — kerjakan DULUAN
Fungsi: kunci AI Claude. Kalau bocor → tagihan API atas namamu.
- [ ] Buka **console.anthropic.com → API Keys**
- [ ] **Create Key** baru → salin nilainya
- [ ] Di server `db.php`: ganti nilai `ANTHROPIC_API_KEY` → key baru → **Save**
- [ ] Tes: buka POS → pilih pelanggan → AI Assistant harus jalan (atau buka AI Briefing di dashboard)
- [ ] Kalau AI jalan → kembali ke console → **Revoke/Delete** key LAMA
- [ ] ✅ Selesai

## 2. SMTP_PASS (email pengirim) — ada downtime email sesaat
Fungsi: password `noreply@harpy.id` untuk kirim email (verifikasi, nurture).
- [ ] hPanel → **Emails → Email Accounts** → cari `noreply@harpy.id` → **Change Password**
- [ ] Set password baru → salin
- [ ] **SEGERA** di server `db.php`: ganti `SMTP_PASS` → password baru → **Save**
- [ ] Tes kirim: SuperAdmin → **Test Nurturing** → Kirim tes ke outlet trial → cek log `channel=email status=sent` + inbox
- [ ] ✅ Selesai (kalau `status=failed`, berarti password di db.php ≠ hPanel — cek ulang)

## 3. SA_BASIC_PASS (gerbang SuperAdmin) — nol downtime kalau hati-hati
Fungsi: password Basic-Auth yang muncul sebelum halaman login SuperAdmin.
- [ ] Di server `db.php`: ganti nilai `SA_BASIC_PASS` → password baru (nilai bebas, kuat) → **Save**
      (opsional: ganti `SA_BASIC_USER` juga)
- [ ] Tes: buka `lamasy.harpy.id/superadmin/` di **jendela incognito** → dialog Basic Auth harus terima user+pass BARU
- [ ] Simpan kredensial baru di password manager
- [ ] ✅ Selesai

## 4. DB_PASS (PALING KRITIS) — kerjakan TERAKHIR, saat sepi
Fungsi: password database. Salah langkah = **seluruh situs 500 error** sampai db.php benar.
- [ ] Pastikan sudah punya **backup db.php** (Persiapan di atas)
- [ ] hPanel → **Databases → MySQL Databases** → cari user `u269895997_...` → **Change Password**
- [ ] Set password baru → salin
- [ ] **SEGERA** (detik itu juga) di server `db.php`: ganti `DB_PASS` → password baru → **Save**
- [ ] Tes: refresh `lamasy.harpy.id/login` → harus muncul normal (bukan 500). Login demo/tenant → dashboard tampil.
- [ ] Kalau 500: kembalikan `db.php` dari backup ATAU pastikan password persis sama (tanpa spasi/typo)
- [ ] Update juga `~/.my.cnf` di laptopmu (kalau masih mau akses DB dari lokal) — ganti `password=` di sana
- [ ] ✅ Selesai

## 5. (Opsional) cron_secret & Midtrans key
- [ ] **cron_secret** — di DB `saas_billing_config` (key_name `cron_secret`). Cron sekarang jalan via CLI-path (bukan URL), jadi secret ini praktis tak dipakai — rotasi opsional. Kalau mau: SuperAdmin → Billing Config, atau update langsung di DB.
- [ ] **Midtrans key** — kunci production baru saja dipasang & hanya prefix-nya yang sempat terlihat (nilai penuh tak pernah tercetak) → **tidak mendesak**. Kalau ragu: dashboard.midtrans.com (mode Production) → Settings → Access Keys → **Regenerate**, lalu update di SuperAdmin → Billing Config (env production + 2 key baru).

---

## Setelah semua selesai
- [ ] Hapus file backup `db.php.bak` dari tempat yang mudah diakses (setelah yakin semua jalan)
- [ ] Konfirmasi cepat: login tenant jalan ✓, AI jalan ✓, email nurture `sent` ✓, SuperAdmin bisa masuk ✓
- [ ] Catat tanggal rotasi terakhir di sini: `Rotasi terakhir: ____________`

## Prinsip ke depan
- Jangan pernah menempel nilai secret penuh ke chat/screenshot/commit.
- `db.php` tetap **gitignored** — jangan pernah commit nilai aslinya.
- Rotasi ulang kalau ada indikasi kebocoran, atau rutin (mis. tiap 6–12 bulan).
