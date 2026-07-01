<?php
/** Validasi alamat lengkap wajib untuk pengiriman welcome kit. Return list pesan error. */
function aoValidateAddress(array $post): array
{
    $errors = [];
    $penerima = trim($post['penerima'] ?? '');
    $telepon  = trim($post['telepon'] ?? '');
    $alamat   = trim($post['alamat'] ?? '');
    $kodePos  = trim($post['kode_pos'] ?? '');
    if (strlen($penerima) < 2)          $errors[] = 'Nama penerima wajib diisi.';
    if (!preg_match('/\d{8,}/', preg_replace('/\D/','',$telepon))) $errors[] = 'No. HP penerima wajib (min 8 digit).';
    if (strlen($alamat) < 8)            $errors[] = 'Alamat jalan wajib diisi (min 8 karakter).';
    // Wilayah: pemanggil baru (add-outlet) kirim kode w_prov/w_kota/w_kec/w_kel;
    // pemanggil lama (wizard SA registrasi) kirim teks `kota`. Dukung keduanya.
    $usesCodes = trim($post['w_prov'] ?? '') !== '' || trim($post['w_kota'] ?? '') !== ''
              || trim($post['w_kec'] ?? '')  !== '' || trim($post['w_kel'] ?? '')  !== '';
    if ($usesCodes) {
        foreach (['w_prov'=>'Provinsi','w_kota'=>'Kota/Kabupaten','w_kec'=>'Kecamatan','w_kel'=>'Kelurahan'] as $k=>$label) {
            if (trim($post[$k] ?? '') === '') $errors[] = $label.' wajib dipilih.';
        }
    } else {
        // Mode teks (legacy, wizard SA): minimal kota terisi.
        if (strlen(trim($post['kota'] ?? '')) < 2) $errors[] = 'Kota/Kabupaten wajib diisi.';
    }
    if (!preg_match('/^\d{5}$/', $kodePos)) $errors[] = 'Kode pos wajib 5 digit.';
    return $errors;
}

/**
 * Validasi & resolve wilayah dari kode POST. Pastikan hierarki benar
 * (kota anak prov, kec anak kota, kel anak kec) via ref_wilayah.
 * Return ['provinsi'=>nama,'kota'=>nama,'kecamatan'=>nama,'kelurahan'=>nama,'wilayah_kode'=>kodeKel]
 * atau null bila tidak valid.
 */
function aoResolveWilayah(PDO $db, array $post): ?array
{
    $prov = trim($post['w_prov'] ?? '');
    $kota = trim($post['w_kota'] ?? '');
    $kec  = trim($post['w_kec']  ?? '');
    $kel  = trim($post['w_kel']  ?? '');
    if ($prov==='' || $kota==='' || $kec==='' || $kel==='') return null;

    $get = function(string $kode, int $level, ?string $parent) use ($db): ?string {
        $st = $db->prepare("SELECT nama FROM ref_wilayah WHERE kode=? AND level=?"
                          . ($parent !== null ? " AND parent_kode=?" : ""));
        $st->execute($parent !== null ? [$kode,$level,$parent] : [$kode,$level]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string)$n;
    };
    $nProv = $get($prov, 1, null);
    $nKota = $get($kota, 2, $prov);
    $nKec  = $get($kec,  3, $kota);
    $nKel  = $get($kel,  4, $kec);
    if ($nProv===null || $nKota===null || $nKec===null || $nKel===null) return null;
    return ['provinsi'=>$nProv,'kota'=>$nKota,'kecamatan'=>$nKec,'kelurahan'=>$nKel,'wilayah_kode'=>$kel];
}
