-- ══════════════════════════════════════════════════════════════════
-- DIAGNOSE: Cari tenant/outlet DUPLIKAT akibat bug aktivasi self-reg
--
-- Konteks: tenant self-service yang sudah daftar (punya email +
-- password_hash + source='self_service') terkadang dibuatkan tenant
-- KEDUA oleh wizard provisioning (tanpa email, tanpa password_hash).
--
-- SEMUA query di bawah READ-ONLY (SELECT). Tidak mengubah data.
-- Review dulu hasilnya sebelum jalankan bagian CLEANUP (paling bawah).
-- ══════════════════════════════════════════════════════════════════

-- ──────────────────────────────────────────────────────────────────
-- 1. Tenant duplikat berdasarkan NOMOR WA OWNER (indikator terkuat)
--    Menampilkan grup yang punya >1 tenant dengan owner_wa sama.
-- ──────────────────────────────────────────────────────────────────
SELECT
    t.owner_wa,
    COUNT(*)                              AS jumlah_tenant,
    GROUP_CONCAT(t.id ORDER BY t.id)      AS tenant_ids,
    GROUP_CONCAT(t.nama_perusahaan SEPARATOR ' | ') AS nama_perusahaan
FROM tenants t
WHERE t.owner_wa IS NOT NULL AND t.owner_wa <> ''
GROUP BY t.owner_wa
HAVING COUNT(*) > 1
ORDER BY jumlah_tenant DESC;


-- ──────────────────────────────────────────────────────────────────
-- 2. DETAIL tiap tenant dalam grup duplikat — bandingkan mana yang ASLI
--    (self-service: ada email + password_hash) vs DUPLIKAT (kosong).
--    Plus aktivitas: jumlah outlet, transaksi, pelanggan.
-- ──────────────────────────────────────────────────────────────────
SELECT
    t.id                       AS tenant_id,
    t.nama_perusahaan,
    t.owner_name,
    t.owner_wa,
    t.email,
    t.status,
    t.registration_source,
    CASE WHEN t.password_hash IS NOT NULL AND t.password_hash <> '' THEN 'YA' ELSE '—' END AS punya_password,
    t.created_at,
    t.provisioned_at,
    (SELECT COUNT(*) FROM outlets o      WHERE o.tenant_id = t.id) AS jml_outlet,
    (SELECT COUNT(*) FROM hl_transaksi x WHERE x.tenant_id = t.id) AS jml_transaksi,
    (SELECT COUNT(*) FROM hl_pelanggan p WHERE p.tenant_id = t.id) AS jml_pelanggan,
    (SELECT COUNT(*) FROM hl_users u     WHERE u.tenant_id = t.id) AS jml_user,
    -- Skor "keaslian": makin tinggi makin layak DIPERTAHANKAN
    (
        (CASE WHEN t.email IS NOT NULL AND t.email <> '' THEN 2 ELSE 0 END) +
        (CASE WHEN t.password_hash IS NOT NULL AND t.password_hash <> '' THEN 2 ELSE 0 END) +
        (CASE WHEN t.registration_source = 'self_service' THEN 1 ELSE 0 END) +
        (SELECT COUNT(*) FROM hl_transaksi x WHERE x.tenant_id = t.id) +
        (SELECT COUNT(*) FROM hl_pelanggan p WHERE p.tenant_id = t.id)
    ) AS skor_keaslian
FROM tenants t
WHERE t.owner_wa IN (
    SELECT owner_wa FROM tenants
    WHERE owner_wa IS NOT NULL AND owner_wa <> ''
    GROUP BY owner_wa HAVING COUNT(*) > 1
)
ORDER BY t.owner_wa, skor_keaslian DESC, t.id;


-- ──────────────────────────────────────────────────────────────────
-- 3. KANDIDAT DUPLIKAT untuk DIHAPUS (tenant "kosong" hasil wizard)
--    Kriteria: owner_wa-nya muncul >1x, TIDAK punya email & password,
--    dan TIDAK punya transaksi/pelanggan (benar-benar kosong).
--    ⚠️ Tetap review manual sebelum hapus.
-- ──────────────────────────────────────────────────────────────────
SELECT
    t.id AS tenant_id_kandidat_hapus,
    t.nama_perusahaan, t.owner_wa, t.status, t.registration_source,
    t.created_at,
    (SELECT GROUP_CONCAT(o.id) FROM outlets o WHERE o.tenant_id = t.id) AS outlet_ids,
    -- tenant ASLI (yang harus dipertahankan) untuk owner_wa yang sama
    (SELECT t2.id FROM tenants t2
       WHERE t2.owner_wa = t.owner_wa
         AND t2.id <> t.id
         AND (t2.email IS NOT NULL OR t2.password_hash IS NOT NULL)
       ORDER BY t2.id LIMIT 1) AS tenant_asli_id
FROM tenants t
WHERE t.owner_wa IN (
        SELECT owner_wa FROM tenants
        WHERE owner_wa IS NOT NULL AND owner_wa <> ''
        GROUP BY owner_wa HAVING COUNT(*) > 1
      )
  AND (t.email IS NULL OR t.email = '')
  AND (t.password_hash IS NULL OR t.password_hash = '')
  AND NOT EXISTS (SELECT 1 FROM hl_transaksi x WHERE x.tenant_id = t.id)
  AND NOT EXISTS (SELECT 1 FROM hl_pelanggan p WHERE p.tenant_id = t.id)
ORDER BY t.owner_wa, t.id;


-- ──────────────────────────────────────────────────────────────────
-- 4. Registration_requests ganda per tenant (email_sent + payment_pending)
--    Cek apakah ada >1 request untuk owner/tenant yang sama.
-- ──────────────────────────────────────────────────────────────────
SELECT
    rr.tenant_id,
    COUNT(*) AS jml_request,
    GROUP_CONCAT(CONCAT(rr.id, ':', rr.status, IFNULL(CONCAT(':out', rr.outlet_id),'')) SEPARATOR '  ') AS requests
FROM registration_requests rr
WHERE rr.tenant_id IS NOT NULL
GROUP BY rr.tenant_id
HAVING COUNT(*) > 1
ORDER BY jml_request DESC;


-- ══════════════════════════════════════════════════════════════════
-- 5. CLEANUP (OPSIONAL — JANGAN jalankan sebelum review query 1-3)
--    Hapus tenant duplikat kosong + outlet-nya. Ganti ID sesuai hasil
--    query #3 (kolom tenant_id_kandidat_hapus). JANGAN pakai blind.
-- ══════════════════════════════════════════════════════════════════
-- Contoh (GANTI angka dengan ID hasil review, hapus komentar -- untuk eksekusi):
--
-- SET @dup := 999;  -- tenant_id duplikat yang mau dihapus (dari query #3)
--
-- -- Pastikan dulu tenant ini benar kosong:
-- SELECT
--   (SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=@dup) AS trx,
--   (SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=@dup) AS pel,
--   (SELECT COUNT(*) FROM hl_kas       WHERE tenant_id=@dup) AS kas;
-- -- Kalau ketiganya 0, aman dihapus:
--
-- DELETE FROM hl_layanan            WHERE tenant_id=@dup;
-- DELETE FROM hl_users              WHERE tenant_id=@dup;
-- DELETE FROM hl_karyawan_outlet    WHERE tenant_id=@dup;
-- DELETE FROM saas_manual_payments  WHERE tenant_id=@dup;
-- DELETE FROM coin_ledger           WHERE tenant_id=@dup;
-- DELETE FROM registration_requests WHERE tenant_id=@dup;
-- DELETE FROM outlets               WHERE tenant_id=@dup;
-- DELETE FROM tenants               WHERE id=@dup;
