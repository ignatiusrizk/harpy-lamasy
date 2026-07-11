<?php
// ══════════════════════════════════════════════════════
// core/SaNotifier.php — Notif email ke Super Admin saat ada
// activity tenant penting.
//
// Strategy:
//   - Recipients: hybrid — SA_NOTIFY_EMAILS constant + super_admins.email
//     yang notify_enabled = 1
//   - Throttle: dedup 1 menit per event-type (anti-burst flood)
//   - Best-effort: failure di-swallow, app flow gak ke-block
//
// Usage:
//   SaNotifier::tenantRegistered($tenantId);
//   SaNotifier::emailVerified($userId);
//   SaNotifier::outletActivated($outletId);
//   SaNotifier::supportTicketCreated($ticketId);
//   SaNotifier::trialExpiring($outletId, $daysLeft);
//   SaNotifier::outletSuspended($outletId);
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';

class SaNotifier
{
    const THROTTLE_SECONDS = 60;   // dedup window per event-type
    const APP_URL_DEFAULT  = 'https://lamasy.harpy.id';

    /** Core sender — used by event-specific helpers below */
    public static function notify(string $eventType, string $subject, string $bodyHtml, ?string $refId = null): void
    {
        try {
            // ── Throttle check: kalau event-type sama dalam THROTTLE_SECONDS, skip ──
            $db = Database::get();
            $chk = $db->prepare(
                "SELECT id FROM saas_sa_notif_log
                 WHERE event_type = ? AND sent_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                 LIMIT 1"
            );
            $chk->execute([$eventType, self::THROTTLE_SECONDS]);
            if ($chk->fetchColumn()) {
                return; // throttled — skip
            }

            // ── Resolve recipients ──
            $recipients = self::resolveRecipients($eventType);
            if (empty($recipients)) return;

            // ── Send email ──
            $sentCount = 0;
            foreach ($recipients as $email) {
                $ok = Mailer::send($email, 'Super Admin', $subject, $bodyHtml);
                if ($ok) $sentCount++;
            }

            // ── Log untuk throttle + audit ──
            $db->prepare(
                "INSERT INTO saas_sa_notif_log (event_type, ref_id, subject, recipients)
                 VALUES (?, ?, ?, ?)"
            )->execute([
                $eventType, $refId, substr($subject, 0, 255), implode(',', $recipients),
            ]);
        } catch (Throwable $e) {
            error_log('[SaNotifier] ' . $eventType . ' fail: ' . $e->getMessage());
        }
    }

    /**
     * Resolve recipient list for a given event type.
     * Recipients = constant SA_NOTIFY_EMAILS + SA accounts whose role
     * has a permission with notif_events containing $eventType.
     */
    private static function resolveRecipients(string $eventType): array
    {
        $out = [];

        // 1) Constant default (always included)
        if (defined('SA_NOTIFY_EMAILS') && is_array(SA_NOTIFY_EMAILS)) {
            foreach (SA_NOTIFY_EMAILS as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
        }

        // 2) SA accounts whose role permission covers this event_type
        try {
            $s = Database::get()->prepare(
                "SELECT DISTINCT sa.email FROM super_admins sa
                 JOIN sa_role_permissions rp ON rp.role_id = sa.role_id
                 JOIN sa_permissions p ON p.id = rp.permission_id
                 WHERE sa.notify_enabled = 1 AND sa.is_active = 1 AND sa.email IS NOT NULL
                   AND FIND_IN_SET(?, p.notif_events) > 0"
            );
            $s->execute([$eventType]);
            $rows = $s->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
        } catch (Throwable $ex) {
            // Tables not yet migrated — fall back to opt-in list
            try {
                $rows = Database::get()->query(
                    "SELECT email FROM super_admins WHERE notify_enabled=1 AND email IS NOT NULL AND is_active=1"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $e) {
                    if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
                }
            } catch (Throwable) {}
        }

        return array_values(array_unique($out));
    }

    // ──────────────────────────────────────────────────────
    // EVENT HELPERS — dipanggil dari hook points
    // ──────────────────────────────────────────────────────

    public static function tenantRegistered(int $tenantId): void
    {
        try {
            $t = Database::get()->prepare(
                "SELECT t.nama_perusahaan, t.owner_name, t.owner_wa, t.email, t.created_at
                 FROM tenants t WHERE t.id = ? LIMIT 1"
            );
            $t->execute([$tenantId]);
            $row = $t->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $subject = '[LAMASY] 🎉 Tenant baru: ' . ($row['nama_perusahaan'] ?: $row['owner_name'] ?: 'Unknown');
            $body = self::layout('Tenant Baru Daftar', [
                ['Nama Perusahaan', $row['nama_perusahaan'] ?: '-'],
                ['Owner',           $row['owner_name'] ?: '-'],
                ['Email',           $row['email'] ?: '-'],
                ['WhatsApp',        $row['owner_wa'] ?: '-'],
                ['Tanggal Daftar',  $row['created_at']],
            ], '/superadmin/client_detail.php?id=' . $tenantId, 'Lihat Detail Tenant');

            self::notify('tenant_registered', $subject, $body, (string)$tenantId);
        } catch (Throwable $e) {
            error_log('[SaNotifier::tenantRegistered] ' . $e->getMessage());
        }
    }

    public static function emailVerified(int $tenantId): void
    {
        try {
            $s = Database::get()->prepare(
                "SELECT nama_perusahaan, owner_name, email FROM tenants WHERE id = ? LIMIT 1"
            );
            $s->execute([$tenantId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $subject = '[LAMASY] ✓ Email verified: ' . ($row['nama_perusahaan'] ?: $row['owner_name'] ?: $row['email']);
            $body = self::layout('Email Tenant Terverifikasi', [
                ['Perusahaan',   $row['nama_perusahaan'] ?: '-'],
                ['Owner',        $row['owner_name'] ?: '-'],
                ['Email',        $row['email']],
            ], '/superadmin/client_detail.php?id=' . $tenantId, 'Lihat Detail');

            self::notify('email_verified', $subject, $body, (string)$tenantId);
        } catch (Throwable $e) {
            error_log('[SaNotifier::emailVerified] ' . $e->getMessage());
        }
    }

    public static function outletActivated(int $outletId, bool $isPaid = false): void
    {
        try {
            $s = Database::get()->prepare(
                "SELECT o.nama_outlet, o.status, o.kota, o.tenant_id,
                        t.nama_perusahaan, t.owner_name
                 FROM outlets o LEFT JOIN tenants t ON t.id = o.tenant_id
                 WHERE o.id = ? LIMIT 1"
            );
            $s->execute([$outletId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $mode = $isPaid ? '💰 PAID Activation' : '🆓 Trial';
            $subject = '[LAMASY] ' . $mode . ': ' . ($row['nama_outlet'] ?: 'Outlet') . ' (' . ($row['nama_perusahaan'] ?: '-') . ')';
            $body = self::layout($isPaid ? 'Outlet Aktivasi PAID 🎉' : 'Outlet Baru (Trial)', [
                ['Outlet',        $row['nama_outlet'] ?: '-'],
                ['Kota',          $row['kota'] ?: '-'],
                ['Status',        strtoupper($row['status'])],
                ['Tenant',        $row['nama_perusahaan'] ?: '-'],
                ['Owner',         $row['owner_name'] ?: '-'],
                ['Mode',          $isPaid ? 'PAID — revenue captured' : 'Trial 7 hari'],
            ], '/superadmin/client_detail.php?id=' . ((int)$row['tenant_id']), 'Lihat Tenant');

            self::notify($isPaid ? 'outlet_paid' : 'outlet_trial', $subject, $body, (string)$outletId);
        } catch (Throwable $e) {
            error_log('[SaNotifier::outletActivated] ' . $e->getMessage());
        }
    }

    public static function supportTicketCreated(int $ticketId): void
    {
        try {
            $s = Database::get()->prepare(
                "SELECT st.subject, st.message, st.type, st.created_at,
                        t.nama_perusahaan, t.owner_name, t.owner_wa
                 FROM support_tickets st
                 LEFT JOIN tenants t ON t.id = st.tenant_id
                 WHERE st.id = ? LIMIT 1"
            );
            $s->execute([$ticketId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $subject = '[LAMASY] 🎫 Ticket baru: ' . substr($row['subject'] ?: 'No subject', 0, 80);
            $msgPreview = substr(strip_tags($row['message'] ?? ''), 0, 240);
            $body = self::layout('Support Ticket Baru', [
                ['Subject',     $row['subject'] ?: '-'],
                ['Type',        strtoupper($row['type'] ?? '-')],
                ['Tenant',      $row['nama_perusahaan'] ?: '-'],
                ['Owner',       $row['owner_name'] ?: '-'],
                ['WhatsApp',    $row['owner_wa'] ?: '-'],
                ['Pesan',       $msgPreview . (strlen($row['message'] ?? '') > 240 ? '…' : '')],
                ['Waktu',       $row['created_at']],
            ], '/superadmin/support.php', 'Buka Tiket');

            self::notify('support_ticket', $subject, $body, (string)$ticketId);
        } catch (Throwable $e) {
            error_log('[SaNotifier::supportTicketCreated] ' . $e->getMessage());
        }
    }

    public static function trialExpiring(int $outletId, int $daysLeft): void
    {
        try {
            $s = Database::get()->prepare(
                "SELECT o.nama_outlet, o.trial_ends_at, t.nama_perusahaan, t.owner_name, t.owner_wa, t.id AS tid
                 FROM outlets o LEFT JOIN tenants t ON t.id = o.tenant_id
                 WHERE o.id = ? LIMIT 1"
            );
            $s->execute([$outletId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $subject = '[LAMASY] ⏰ Trial ' . $daysLeft . ' hari lagi: ' . ($row['nama_outlet'] ?: 'Outlet');
            $body = self::layout('Trial Hampir Habis — Sales Follow-up', [
                ['Outlet',          $row['nama_outlet'] ?: '-'],
                ['Sisa Trial',      $daysLeft . ' hari'],
                ['Trial Berakhir',  $row['trial_ends_at']],
                ['Tenant',          $row['nama_perusahaan'] ?: '-'],
                ['Owner',           $row['owner_name'] ?: '-'],
                ['WhatsApp',        $row['owner_wa'] ?: '-'],
            ], '/superadmin/client_detail.php?id=' . ((int)$row['tid']), 'Lihat Tenant');

            self::notify('trial_expiring_' . $daysLeft, $subject, $body, (string)$outletId);
        } catch (Throwable $e) {
            error_log('[SaNotifier::trialExpiring] ' . $e->getMessage());
        }
    }

    public static function outletSuspended(int $outletId): void
    {
        try {
            $s = Database::get()->prepare(
                "SELECT o.nama_outlet, o.trial_ends_at, t.nama_perusahaan, t.owner_name, t.owner_wa, t.id AS tid
                 FROM outlets o LEFT JOIN tenants t ON t.id = o.tenant_id
                 WHERE o.id = ? LIMIT 1"
            );
            $s->execute([$outletId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;

            $subject = '[LAMASY] ⚠️ Outlet SUSPENDED: ' . ($row['nama_outlet'] ?: 'Outlet') . ' — churn risk';
            $body = self::layout('Outlet Suspended (Auto)', [
                ['Outlet',          $row['nama_outlet'] ?: '-'],
                ['Trial Habis',     $row['trial_ends_at']],
                ['Tenant',          $row['nama_perusahaan'] ?: '-'],
                ['Owner',           $row['owner_name'] ?: '-'],
                ['WhatsApp',        $row['owner_wa'] ?: '-'],
                ['Action',          'Grace period habis, outlet auto-suspended. Hubungi owner untuk recovery atau churn analysis.'],
            ], '/superadmin/client_detail.php?id=' . ((int)$row['tid']), 'Lihat Tenant');

            self::notify('outlet_suspended', $subject, $body, (string)$outletId);
        } catch (Throwable $e) {
            error_log('[SaNotifier::outletSuspended] ' . $e->getMessage());
        }
    }

    /** Owner menandai sudah transfer manual — minta SA cek & konfirmasi. */
    public static function manualPaymentSubmitted(int $paymentId): void
    {
        try {
            $db = Database::get();
            $st = $db->prepare(
                "SELECT sp.order_id, sp.type, sp.amount, t.nama_perusahaan, t.owner_name
                 FROM saas_payments sp LEFT JOIN tenants t ON t.id=sp.tenant_id
                 WHERE sp.id=?"
            );
            $st->execute([$paymentId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return;

            $rp   = 'Rp ' . number_format((int)$p['amount'], 0, ',', '.');
            $body = self::layout('Transfer Manual Masuk', [
                ['Tenant',   ($p['nama_perusahaan'] ?? '—') . ' (' . ($p['owner_name'] ?? '—') . ')'],
                ['Tipe',     $p['type']],
                ['Nominal',  $rp . ' (termasuk kode unik — cocokkan persis di mutasi)'],
                ['Order ID', $p['order_id']],
            ], '/superadmin/payments.php', 'Buka & Konfirmasi');

            self::notify('manual_payment', 'Transfer manual masuk — ' . $rp, $body, $p['order_id']);
        } catch (Throwable $e) {
            error_log('[SaNotifier manualPaymentSubmitted] ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────
    // HTML LAYOUT — consistent template buat semua event
    // ──────────────────────────────────────────────────────
    private static function layout(string $title, array $rows, ?string $ctaPath = null, ?string $ctaText = null): string
    {
        $appUrl = defined('APP_URL') ? APP_URL : self::APP_URL_DEFAULT;
        $rowsHtml = '';
        foreach ($rows as [$label, $val]) {
            $rowsHtml .= '<tr>'
                . '<td style="padding:8px 12px;background:#F9FAFB;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;font-weight:600;width:140px">'
                . htmlspecialchars($label) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #E5E7EB;font-size:13.5px;color:#0F1C3A">'
                . nl2br(htmlspecialchars((string)$val))
                . '</td></tr>';
        }
        $ctaHtml = '';
        if ($ctaPath && $ctaText) {
            $ctaHtml = '<div style="margin-top:24px;text-align:center">'
                . '<a href="' . htmlspecialchars($appUrl . $ctaPath) . '" '
                . 'style="display:inline-block;background:#6366F1;color:#fff;text-decoration:none;padding:11px 22px;border-radius:9px;font-weight:700;font-size:14px">'
                . htmlspecialchars($ctaText) . '</a></div>';
        }

        return '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#F4F7FB;font-family:Arial,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #E5E7EB">'
            . '<div style="background:linear-gradient(135deg,#0F1C3A,#1a2d52);padding:24px;color:#fff">'
            . '<div style="font-size:11px;font-weight:700;letter-spacing:.1em;color:#6366F1;margin-bottom:4px">LAMASY SUPER ADMIN</div>'
            . '<div style="font-size:18px;font-weight:800">' . htmlspecialchars($title) . '</div>'
            . '</div>'
            . '<div style="padding:20px">'
            . '<table style="width:100%;border-collapse:collapse">' . $rowsHtml . '</table>'
            . $ctaHtml
            . '<div style="margin-top:24px;font-size:11px;color:#9CA3AF;text-align:center;border-top:1px solid #F3F4F6;padding-top:14px">'
            . 'Automated notification dari LAMASY admin system. Disable via /superadmin/settings.'
            . '</div></div></div></body></html>';
    }
}
