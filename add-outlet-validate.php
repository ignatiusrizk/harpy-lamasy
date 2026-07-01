<?php
/** Validasi alamat lengkap wajib untuk pengiriman welcome kit. Return list pesan error. */
function aoValidateAddress(array $post): array
{
    $errors = [];
    $penerima = trim($post['penerima'] ?? '');
    $telepon  = trim($post['telepon'] ?? '');
    $alamat   = trim($post['alamat'] ?? '');
    $kota     = trim($post['kota'] ?? '');
    $kodePos  = trim($post['kode_pos'] ?? '');
    if (strlen($penerima) < 2)          $errors[] = 'Nama penerima wajib diisi.';
    if (!preg_match('/\d{8,}/', preg_replace('/\D/','',$telepon))) $errors[] = 'No. HP penerima wajib (min 8 digit).';
    if (strlen($alamat) < 8)            $errors[] = 'Alamat lengkap wajib diisi (min 8 karakter).';
    if (strlen($kota) < 2)              $errors[] = 'Kota/Kabupaten wajib diisi.';
    if (!preg_match('/^\d{5}$/', $kodePos)) $errors[] = 'Kode pos wajib 5 digit.';
    return $errors;
}
