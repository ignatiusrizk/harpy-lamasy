-- ══════════════════════════════════════════════════════════════════
-- Fix: set email di hl_users untuk owner — biar login pakai email jalan
-- ══════════════════════════════════════════════════════════════════
-- Login HQ (login.php) cari email di hl_users.email, bukan tenants.email
-- Seed sebelumnya hanya set username, tidak set email → email login gagal

UPDATE hl_users SET email='budi.hartono@gmail.com',           email_verified=1 WHERE username='budi.owner';
UPDATE hl_users SET email='maya@freshlaundry.id',             email_verified=1 WHERE username='maya.owner';
UPDATE hl_users SET email='hendra.laundry@gmail.com',         email_verified=1 WHERE username='hendra.owner';
UPDATE hl_users SET email='sari.bunda@bundawangilaundry.com', email_verified=1 WHERE username='sari.bunda';
UPDATE hl_users SET email='deni.quickwash@gmail.com',         email_verified=1 WHERE username='deni.owner';

-- Verifikasi
SELECT id, username, email, nama, role, is_active, tenant_id
  FROM hl_users
 WHERE username IN ('budi.owner','maya.owner','hendra.owner','sari.bunda','deni.owner');
