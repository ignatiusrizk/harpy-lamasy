<?php
// ══════════════════════════════════════════════════════
// core/TrialNurture.php — Trial Nurturing Sequence (Brief 4)
//
// State machine per OUTLET (trial = konsep outlet-level):
//   trial (H1-7) → grace (H8-10) → suspended (H11+, data 30 hari)
//
// Tiap hari, tiap outlet dievaluasi → dipilih SATU touchpoint yang sesuai
// (berdasarkan hari trial + perilaku: sudah first_order? ada transaksi?).
// Pengiriman & anti-spam via Notifier::notifyOwner (email + in-app, dedup
// di hl_notif_log). Guard "pernah terkirim" memastikan tiap touchpoint
// maksimal 1x per outlet. Log ringkas juga ditulis ke saas_sa_notif_log
// untuk visibilitas Super Admin.
//
// Dipanggil dari:
//   - cron/trial_nurture.php  (harian, semua outlet — jalur utama)
//   - tenant_guard pseudo-cron (opportunistic, outlet aktif — cadangan)
//
// CATATAN WA: belum ada gateway WA (di-hold) → touchpoint dikirim via EMAIL +
// IN-APP saja. Kolom "WA" di brief butuh gateway; di-skip sampai tersedia.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notifier.php';

class TrialNurture
{
    const APP_URL = 'https://lamasy.harpy.id';
    const CS_WA   = '6285121519302';

    // ── KILL-SWITCH (Brief 4) ───────────────────────────
    // ENABLED=false → nurturing MATI untuk semua tenant nyata (tidak kirim email).
    // Selama MATI, HANYA tenant di TEST_TENANT_IDS yang tetap diproses — untuk uji
    // coba ke tenant test dulu. Kalau sudah yakin: set ENABLED=true (dan kosongkan
    // TEST_TENANT_IDS), lalu push.
    const ENABLED         = false;
    const TEST_TENANT_IDS = [];   // mis. [21] saat mau tes ke tenant test tertentu

    /** Boleh kirim nurturing utk tenant ini? (global ON, atau tenant ada di test allowlist) */
    private static function allowed(int $tenantId): bool
    {
        return self::ENABLED || in_array($tenantId, self::TEST_TENANT_IDS, true);
    }

    /**
     * Proses semua outlet yang relevan (trial / grace / suspended baru).
     * @return array{processed:int, sent:int, byType:array<string,int>}
     */
    public static function runAll(): array
    {
        $summary = ['processed' => 0, 'sent' => 0, 'byType' => []];
        // Kalau MATI total & tak ada tenant test → tidak usah query apa pun.
        if (!self::ENABLED && empty(self::TEST_TENANT_IDS)) return $summary;
        try {
            $rows = Database::get()->query(
                "SELECT id FROM outlets
                  WHERE status IN ('trial','grace','suspended')
                  ORDER BY id"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log('[TrialNurture::runAll] '.$e->getMessage());
            return $summary;
        }
        foreach ($rows as $oid) {
            $summary['processed']++;
            $type = self::runOutlet((int)$oid);
            if ($type) {
                $summary['sent']++;
                $summary['byType'][$type] = ($summary['byType'][$type] ?? 0) + 1;
            }
        }
        return $summary;
    }

    /**
     * Evaluasi + kirim touchpoint untuk 1 outlet. Return type yang dikirim, atau null.
     */
    public static function runOutlet(int $outletId): ?string
    {
        try {
            $db = Database::get();
            $o = $db->prepare(
                "SELECT o.*, t.email owner_email, t.owner_name, t.owner_wa,
                        t.nama_perusahaan, t.onboarding_step
                   FROM outlets o
                   JOIN tenants t ON t.id = o.tenant_id
                  WHERE o.id = ? LIMIT 1"
            );
            $o->execute([$outletId]);
            $row = $o->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            // Kill-switch: skip kecuali nurturing ON atau tenant ada di test allowlist.
            if (!self::allowed((int)$row['tenant_id'])) return null;

            $tp = self::pickTouchpoint($row);
            if (!$tp) return null;

            $tid = (int)$row['tenant_id'];
            $oid = (int)$row['id'];

            // Anti-spam: touchpoint ini pernah terkirim untuk outlet ini? → skip.
            if (self::alreadySent($tid, $oid, $tp['type'])) return null;

            $res = Notifier::notifyOwner($tid, $oid, [
                'type'         => $tp['type'],
                'subject'      => $tp['subject'],
                'body_html'    => $tp['html'],
                'body_summary' => $tp['summary'],
                'channels'     => ['email', 'inapp'],
                // gratis (platform → owner), tanpa coin_feature.
            ]);

            if (!empty($res['ok'])) {
                self::logSa($tp['type'], $tid, $tp['subject'], (string)($row['owner_email'] ?? ''));
                return $tp['type'];
            }
            return null;
        } catch (Throwable $e) {
            error_log('[TrialNurture::runOutlet] o='.$outletId.' '.$e->getMessage());
            return null;
        }
    }

    // ── Pemilihan touchpoint berdasarkan state + perilaku ──
    private static function pickTouchpoint(array $o): ?array
    {
        $status   = $o['status'] ?? '';
        $owner     = trim((string)($o['owner_name'] ?? '')) ?: 'Kak';
        $namaUsaha = (string)($o['nama_perusahaan'] ?? 'usaha kamu');
        $namaOutlet = (string)($o['nama_outlet'] ?? $namaUsaha);
        [$nTrx, $nCust] = self::counts((int)$o['tenant_id'], (int)$o['id']);
        $hasOrder = $nTrx > 0 || in_array($o['onboarding_step'] ?? '', ['first_order','activated'], true);

        if ($status === 'suspended') {
            // H11+ — sekali saja: data masih ada 30 hari.
            return self::tp('trial_suspended', "Outlet kamu ditangguhkan — datanya masih aman 30 hari",
                self::body($owner,
                    "<p>Outlet <strong>".self::e($namaOutlet)."</strong> sekarang ditangguhkan karena trial &amp; masa tenggang berakhir.</p>
                     <p><strong>Kabar baiknya:</strong> semua data kamu — ".self::dataPhrase($nTrx,$nCust)." — <strong>masih kami simpan 30 hari</strong>. Aktifkan kapan saja dan semuanya kembali utuh.</p>",
                    "Aktifkan &amp; pulihkan data", "/billing.php"));
        }

        if ($status === 'grace') {
            $graceLeft = self::daysLeft($o['grace_ends_at'] ?? null);
            if ($graceLeft <= 1) {
                return self::tp('trial_h10_final', "Peringatan terakhir — outlet ditangguhkan tengah malam",
                    self::body($owner,
                        "<p>Ini pemberitahuan <strong>terakhir</strong>. Masa tenggang outlet <strong>".self::e($namaOutlet)."</strong> habis hari ini.</p>
                         <p>Kalau belum diaktifkan malam ini, outlet ditangguhkan dan ".self::dataPhrase($nTrx,$nCust)." tidak bisa diakses (masih tersimpan 30 hari, tapi terkunci).</p>",
                        "Aktifkan sekarang", "/billing.php"));
            }
            return self::tp('trial_h8_grace', "Masih ada waktu — data outlet kamu belum hilang",
                self::body($owner,
                    "<p>Trial <strong>".self::e($namaOutlet)."</strong> sudah berakhir, tapi kami beri <strong>masa tenggang $graceLeft hari</strong> lagi.</p>
                     <p>".self::dataPhrase($nTrx,$nCust)." masih aman. Aktifkan sebelum masa tenggang habis supaya tidak terkunci.</p>",
                    "Aktifkan outlet", "/billing.php"));
        }

        if ($status !== 'trial') return null;

        $day = self::trialDay($o);

        if ($day >= 7) {
            return self::tp('trial_h7_final', "Trial berakhir hari ini — aktifkan sebelum tengah malam",
                self::body($owner,
                    "<p>Hari ini <strong>hari terakhir</strong> trial <strong>".self::e($namaOutlet)."</strong>.</p>
                     <p>Setelah tengah malam, yang terkunci: ".self::dataPhrase($nTrx,$nCust).", laporan, dan portal tracking pelanggan.</p>
                     <p>Masih ada masa tenggang 3 hari sebagai jaring pengaman — tapi paling aman aktifkan <strong>sekarang</strong>.</p>",
                    "Aktifkan sekarang", "/billing.php"));
        }
        if ($day >= 5) {
            return self::tp('trial_h5_lossaversion', "2 hari lagi — data outlet kamu akan terkunci ⚠️",
                self::body($owner,
                    "<p>Trial <strong>".self::e($namaOutlet)."</strong> tinggal <strong>2 hari</strong> lagi.</p>
                     <p>Kamu sudah menginput ".self::dataPhrase($nTrx,$nCust).". <strong>Sayang kalau terkunci</strong> begitu trial habis.</p>
                     <p>Aktifkan outlet supaya semua data &amp; fitur tetap jalan tanpa putus.</p>",
                    "Aktifkan sekarang", "/billing.php"));
        }
        if ($day >= 3) {
            if ($hasOrder) {
                return self::tp('trial_h3_praise', "Mantap, $owner! Sudah mulai jalan 🎉",
                    self::body($owner,
                        "<p>Keren — <strong>".self::e($namaOutlet)."</strong> sudah punya <strong>$nTrx transaksi</strong>. Kamu di jalur yang benar!</p>
                         <p>Sudah coba <strong>AI Briefing</strong> di dashboard? Selama trial <strong>gratis</strong> — dia rangkum performa outlet tiap hari otomatis. Coba sekarang, cuma 1 klik.</p>",
                        "Buka Dashboard", "/dashboard"));
            }
            return self::tp('trial_h3_help', "Butuh bantuan setup outlet? Kami siap bantu",
                self::body($owner,
                    "<p>Kami lihat <strong>".self::e($namaOutlet)."</strong> belum sempat buat transaksi pertama. Nggak apa-apa — kadang mulai itu yang paling susah.</p>
                     <p>Tim kami siap bantu setup manual lewat WhatsApp. Balas email ini atau chat kami, kami pandu sampai order pertama jadi.</p>",
                    "Chat CS via WhatsApp", 'wa'));
        }
        if ($day >= 1 && !$hasOrder) {
            return self::tp('trial_h1_setup', "Setup cuma 3 menit — yuk buat order pertama",
                self::body($owner,
                    "<p>Selamat datang di LaMaSy, $owner! Outlet <strong>".self::e($namaOutlet)."</strong> sudah aktif dalam masa trial.</p>
                     <p>Tinggal 3 langkah cepat biar langsung bisa terima order: tambah layanan → tambah/​pakai customer umum → buat order pertama di kasir. Total ± 3 menit.</p>",
                    "Mulai sekarang", "/onboarding.php"));
        }
        return null;
    }

    // ── Builders ──
    private static function tp(string $type, string $subject, string $html, ?string $summary = null): array
    {
        return ['type'=>$type, 'subject'=>$subject, 'html'=>$html,
                'summary'=>$summary ?? strip_tags($subject)];
    }

    /** Bungkus body dengan template email + tombol CTA. $href 'wa' → link WhatsApp CS. */
    private static function body(string $owner, string $inner, string $ctaLabel, string $href): string
    {
        $url = $href === 'wa'
            ? 'https://wa.me/'.self::CS_WA.'?text='.rawurlencode('Halo Tim LaMaSy, saya butuh bantuan setup outlet trial.')
            : self::APP_URL . $href;
        $content =
            "<p style='font-size:15px'>Halo <strong>".self::e($owner)."</strong>,</p>"
            . $inner
            . "<p style='text-align:center;margin:28px 0'>
                 <a href='".self::e($url)."' style='display:inline-block;background:#14b8a6;color:#fff;
                    text-decoration:none;font-weight:700;padding:13px 28px;border-radius:10px;font-size:15px'>"
                 .self::e($ctaLabel)."</a>
               </p>
               <p style='color:#64748b;font-size:12px'>Email ini dikirim otomatis oleh sistem LaMaSy karena outlet kamu sedang dalam masa trial.</p>";
        return Mailer::baseTemplate('LaMaSy', $content);
    }

    private static function dataPhrase(int $nTrx, int $nCust): string
    {
        $parts = [];
        if ($nTrx  > 0) $parts[] = "<strong>$nTrx transaksi</strong>";
        if ($nCust > 0) $parts[] = "<strong>$nCust pelanggan</strong>";
        return $parts ? 'data '.implode(' &amp; ', $parts) : 'semua data outlet';
    }

    // ── Data helpers ──
    private static function counts(int $tid, int $oid): array
    {
        try {
            $db = Database::get();
            $t = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND outlet_id=?");
            $t->execute([$tid, $oid]);
            $c = $db->prepare("SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=?");
            $c->execute([$tid]);
            return [(int)$t->fetchColumn(), (int)$c->fetchColumn()];
        } catch (Throwable) { return [0, 0]; }
    }

    /** Hari ke-berapa trial berjalan (1-based) dari outlet row. */
    private static function trialDay(array $o): int
    {
        $start = $o['trial_starts_at'] ?? null;
        if (empty($start)) {
            $ends = $o['trial_ends_at'] ?? null;
            if (empty($ends)) return 1;
            $start = date('Y-m-d H:i:s', strtotime($ends) - 7 * 86400);
        }
        return max(1, (int)floor((time() - strtotime($start)) / 86400) + 1);
    }

    private static function daysLeft(?string $ts): int
    {
        if (empty($ts)) return 0;
        return max(0, (int)ceil((strtotime($ts) - time()) / 86400));
    }

    /** Touchpoint ini pernah terkirim (channel apa pun, status sent) untuk outlet ini? */
    private static function alreadySent(int $tid, int $oid, string $type): bool
    {
        try {
            $s = Database::get()->prepare(
                "SELECT 1 FROM hl_notif_log
                  WHERE tenant_id=? AND outlet_id=? AND type=? AND status='sent' LIMIT 1"
            );
            $s->execute([$tid, $oid, $type]);
            return (bool)$s->fetchColumn();
        } catch (Throwable) { return false; }
    }

    /** Log ringkas ke saas_sa_notif_log untuk visibilitas Super Admin (tanpa email SA). */
    private static function logSa(string $type, int $tid, string $subject, string $email): void
    {
        try {
            Database::get()->prepare(
                "INSERT INTO saas_sa_notif_log (event_type, ref_id, subject, recipients)
                 VALUES (?, ?, ?, ?)"
            )->execute(['trial_nurture:'.$type, (string)$tid, substr($subject, 0, 255), $email]);
        } catch (Throwable $e) { error_log('[TrialNurture::logSa] '.$e->getMessage()); }
    }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
