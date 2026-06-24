<?php
// core/AffiliateAuth.php — Auth affiliate (session terpisah dari tenant/SA)
require_once __DIR__ . '/Database.php';

class AffiliateAuth
{
    public static function signup(array $d): array
    {
        $nama  = trim($d['nama'] ?? '');
        $email = strtolower(trim($d['email'] ?? ''));
        $telp  = trim($d['telepon'] ?? '');
        $pass  = (string)($d['password'] ?? '');
        if ($nama === '' || $email === '' || $pass === '') return ['ok'=>false,'error'=>'Nama, email, password wajib'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'error'=>'Email tidak valid'];
        if (strlen($pass) < 6) return ['ok'=>false,'error'=>'Password min 6 karakter'];

        $db = Database::get();
        $c = $db->prepare("SELECT id FROM hl_affiliate WHERE email=?");
        $c->execute([$email]);
        if ($c->fetchColumn()) return ['ok'=>false,'error'=>'Email sudah terdaftar'];

        $kode = self::generateKode();
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO hl_affiliate
            (nama, email, telepon, password_hash, kode, rekening_bank, rekening_nomor, rekening_atas_nama)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$nama, $email, $telp, $hash, $kode,
                      trim($d['rekening_bank'] ?? '') ?: null,
                      trim($d['rekening_nomor'] ?? '') ?: null,
                      trim($d['rekening_atas_nama'] ?? '') ?: null]);
        $id = (int)$db->lastInsertId();
        $_SESSION['affiliate_id'] = $id;   // auto-login
        return ['ok'=>true, 'id'=>$id];
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $db = Database::get();
        $s = $db->prepare("SELECT id, password_hash, status FROM hl_affiliate WHERE email=?");
        $s->execute([$email]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return ['ok'=>false,'error'=>'Email atau password salah'];
        }
        if ($row['status'] === 'suspended') return ['ok'=>false,'error'=>'Akun ditangguhkan'];
        $_SESSION['affiliate_id'] = (int)$row['id'];
        return ['ok'=>true];
    }

    public static function current(): ?array
    {
        $id = (int)($_SESSION['affiliate_id'] ?? 0);
        if (!$id) return null;
        $s = Database::get()->prepare("SELECT * FROM hl_affiliate WHERE id=? AND status='active'");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function requireLogin(): array
    {
        $aff = self::current();
        if (!$aff) { header('Location: /affiliate/login'); exit; }
        return $aff;
    }

    public static function logout(): void
    {
        unset($_SESSION['affiliate_id']);
    }

    private static function generateKode(): string
    {
        $db = Database::get();
        do {
            $kode = 'AFF' . strtoupper(substr(base_convert((string)random_int(100000, PHP_INT_MAX), 10, 36), 0, 6));
            $c = $db->prepare("SELECT 1 FROM hl_affiliate WHERE kode=?");
            $c->execute([$kode]);
        } while ($c->fetchColumn());
        return $kode;
    }
}
