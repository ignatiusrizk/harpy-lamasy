<?php
// hq/keuangan.php — Input Keuangan (Aset Tetap, Pinjaman, Jurnal, Kas Bank)
//                   + AJAX endpoint untuk laporan keuangan formal

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/FinancialCalculator.php';

// ── HQ compat stubs (hq_guard tidak provide requirePermission/logAudit) ──
// requirePermission/requireNotGrace/logAudit stubs sudah di hq_guard.php

requirePermission('keuangan.view');

$db      = Database::get();
$tid     = (int) TenantResolver::id();
$oidSess = (int) TenantResolver::outletId();
$user    = currentUser();
$uid     = (int) ($user['id'] ?? 0);
$csrf    = getCsrfToken();

// ── AJAX Handler ───────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    verifyCsrf();

    // ── LAPORAN ENDPOINTS ──────────────────────────────────
    if (in_array($action, ['laporan_lr','laporan_neraca','laporan_arus_kas','laporan_rasio','laporan_aset','laporan_buku_besar','laporan_perubahan_modal'], true)) {
        requirePermission('keuangan.view');
        $periode = preg_replace('/[^0-9\-]/', '', $_GET['periode'] ?? date('Y-m'));
        $oid     = (int)($_GET['outlet_id'] ?? 0) ?: null;
        try {
            switch ($action) {
                case 'laporan_lr':
                    echo json_encode(['ok'=>true,'data'=> FinancialCalculator::labaRugi($tid,$oid,$periode)]);
                    break;
                case 'laporan_neraca':
                    echo json_encode(['ok'=>true,'data'=> FinancialCalculator::neraca($tid,$oid,$periode)]);
                    break;
                case 'laporan_arus_kas':
                    echo json_encode(['ok'=>true,'data'=> FinancialCalculator::arusKas($tid,$oid,$periode)]);
                    break;
                case 'laporan_rasio':
                    echo json_encode(['ok'=>true,'data'=> FinancialCalculator::rasioKeuangan($tid,$oid,$periode)]);
                    break;
                case 'laporan_aset':
                    $endDate = date('Y-m-t', strtotime($periode.'-01'));
                    echo json_encode(['ok'=>true,'data'=> FinancialCalculator::hitungNilaiBukuAset($tid,$oid,$endDate)]);
                    break;
                case 'laporan_buku_besar':
                    $coaId = (int)($_GET['coa_id'] ?? 0);
                    $data = FinancialCalculator::bukuBesar($tid, $oid, $periode, $coaId);
                    echo json_encode(['ok'=>true,'data'=> $data]);
                    break;
                case 'laporan_perubahan_modal':
                    $data = FinancialCalculator::perubahanModal($tid, $oid, $periode);
                    echo json_encode(['ok'=>true,'data'=> $data]);
                    break;
            }
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── ASET TETAP ─────────────────────────────────────────
    if ($action === 'list_aset') {
        $oid = (int)($_GET['outlet_id'] ?? $oidSess);
        $rows = $db->prepare("SELECT a.*, c.nama coa_nama FROM hl_aset_tetap a
            LEFT JOIN hl_coa c ON c.id=a.coa_id
            WHERE a.tenant_id=? AND a.outlet_id=? AND a.status='aktif'
            ORDER BY a.tanggal_perolehan");
        $rows->execute([$tid, $oid]);
        echo json_encode(['ok'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'add_aset' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        requireNotGrace();
        $oid      = (int)$_POST['outlet_id'];
        $coaId    = (int)$_POST['coa_id'];
        $nama     = trim($_POST['nama'] ?? '');
        $tglPerol = $_POST['tanggal_perolehan'] ?? '';
        $nilaiPerol = (int)preg_replace('/\D/','',$_POST['nilai_perolehan']??'0');
        $nilaiSisa  = (int)preg_replace('/\D/','',$_POST['nilai_sisa']??'0');
        $umur       = (int)($_POST['umur_ekonomis'] ?? 12);
        $metode     = in_array($_POST['metode_penyusutan']??'',['garis_lurus','saldo_menurun'])
                        ? $_POST['metode_penyusutan'] : 'garis_lurus';
        $ket        = trim($_POST['keterangan'] ?? '');
        if (!$nama || !$tglPerol || !$nilaiPerol || !$umur) {
            echo json_encode(['error'=>'Data tidak lengkap']); exit;
        }
        $db->prepare("INSERT INTO hl_aset_tetap
            (tenant_id,outlet_id,coa_id,nama,tanggal_perolehan,nilai_perolehan,
             nilai_sisa,umur_ekonomis,metode_penyusutan,keterangan,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$oid,$coaId,$nama,$tglPerol,$nilaiPerol,$nilaiSisa,$umur,$metode,$ket,$uid]);
        logAudit('tambah','keuangan_aset',"Aset: $nama Rp ".number_format($nilaiPerol,0,',','.'));
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'dispose_aset' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        $id         = (int)$_POST['id'];
        $statusBaru = in_array($_POST['status']??'',['dijual','rusak','disposed'])
                        ? $_POST['status'] : 'disposed';
        $nilaiJual  = (int)preg_replace('/\D/','',$_POST['nilai_jual']??'0');
        $tglDispose = $_POST['tanggal_dispose'] ?? date('Y-m-d');
        $db->prepare("UPDATE hl_aset_tetap SET status=?,tanggal_dispose=?,nilai_jual=?
            WHERE id=? AND tenant_id=?")
            ->execute([$statusBaru,$tglDispose,$nilaiJual,$id,$tid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── PINJAMAN ───────────────────────────────────────────
    if ($action === 'list_pinjaman') {
        $oid = (int)($_GET['outlet_id'] ?? $oidSess);
        $rows = $db->prepare("SELECT l.*, c.nama coa_nama,
            (l.saldo_awal - l.saldo_terbayar) AS saldo_hutang
            FROM hl_liabilitas l LEFT JOIN hl_coa c ON c.id=l.coa_id
            WHERE l.tenant_id=? AND l.outlet_id=?
            ORDER BY l.status, l.tanggal_mulai DESC");
        $rows->execute([$tid, $oid]);
        echo json_encode(['ok'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'add_pinjaman' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        requireNotGrace();
        $oid     = (int)$_POST['outlet_id'];
        $coaId   = (int)$_POST['coa_id'];
        $nama    = trim($_POST['nama']??'');
        $kredit  = trim($_POST['kreditur']??'');
        $tglMul  = $_POST['tanggal_mulai']??'';
        $tglJt   = $_POST['tanggal_jatuh_tempo']??'';
        $pokok   = (int)preg_replace('/\D/','',$_POST['pokok_pinjaman']??'0');
        $cicilan = (int)preg_replace('/\D/','',$_POST['cicilan_per_bulan']??'0');
        $bunga   = (float)($_POST['bunga_per_bulan']??0);
        $ket     = trim($_POST['keterangan']??'');
        if (!$nama || !$tglMul || !$tglJt || !$pokok || !$cicilan) {
            echo json_encode(['error'=>'Data tidak lengkap']); exit;
        }
        $db->prepare("INSERT INTO hl_liabilitas
            (tenant_id,outlet_id,coa_id,nama,kreditur,tanggal_mulai,tanggal_jatuh_tempo,
             pokok_pinjaman,cicilan_per_bulan,bunga_per_bulan,saldo_awal,keterangan,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$oid,$coaId,$nama,$kredit,$tglMul,$tglJt,$pokok,$cicilan,$bunga,$pokok,$ket,$uid]);
        // Catat penerimaan di jurnal manual
        $db->prepare("INSERT INTO hl_jurnal_manual
            (tenant_id,outlet_id,coa_id,tanggal,periode,keterangan,tipe,jumlah,arah,liabilitas_id,input_by)
            VALUES (?,?,?,?,?,?,?,?,?,LAST_INSERT_ID(),?)")
            ->execute([$tid,$oid,$coaId,$tglMul,substr($tglMul,0,7),"Penerimaan pinjaman: $nama",
                       'penerimaan_pinjaman',$pokok,'kredit',$uid]);
        logAudit('tambah','keuangan_pinjaman',"Pinjaman: $nama Rp ".number_format($pokok,0,',','.'));
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'bayar_pinjaman' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        requireNotGrace();
        $id      = (int)$_POST['id'];
        $jumlah  = (int)preg_replace('/\D/','',$_POST['jumlah']??'0');
        $tgl     = $_POST['tanggal'] ?? date('Y-m-d');
        $periode = substr($tgl,0,7);
        if (!$jumlah) { echo json_encode(['error'=>'Jumlah tidak valid']); exit; }

        $p = $db->prepare("SELECT * FROM hl_liabilitas WHERE id=? AND tenant_id=?");
        $p->execute([$id, $tid]);
        $loan = $p->fetch(PDO::FETCH_ASSOC);
        if (!$loan) { echo json_encode(['error'=>'Pinjaman tidak ditemukan']); exit; }

        $newTerbayar = $loan['saldo_terbayar'] + $jumlah;
        $lunas = $newTerbayar >= $loan['saldo_awal'];
        $db->prepare("UPDATE hl_liabilitas SET saldo_terbayar=?, status=?, lunas_at=? WHERE id=?")
            ->execute([$newTerbayar, $lunas?'lunas':'aktif', $lunas?$tgl:null, $id]);

        // Jurnal
        $db->prepare("INSERT INTO hl_jurnal_manual
            (tenant_id,outlet_id,coa_id,tanggal,periode,keterangan,tipe,jumlah,arah,liabilitas_id,input_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$loan['outlet_id'],$loan['coa_id'],$tgl,$periode,
                       "Cicilan: ".$loan['nama']." ($tgl)",
                       'pembayaran_hutang',$jumlah,'debit',$id,$uid]);
        echo json_encode(['ok'=>true,'lunas'=>$lunas]);
        exit;
    }

    // ── JURNAL MANUAL ──────────────────────────────────────
    if ($action === 'list_jurnal') {
        $oid     = (int)($_GET['outlet_id'] ?? $oidSess);
        $periode = preg_replace('/[^0-9\-]/','',$_GET['periode']??date('Y-m'));
        $rows = $db->prepare("SELECT j.*,c.nama coa_nama,c.kode FROM hl_jurnal_manual j
            JOIN hl_coa c ON c.id=j.coa_id
            WHERE j.tenant_id=? AND j.outlet_id=? AND j.periode=?
            ORDER BY j.tanggal DESC, j.id DESC LIMIT 100");
        $rows->execute([$tid, $oid, $periode]);
        echo json_encode(['ok'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'add_jurnal' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        requireNotGrace();
        $oid    = (int)$_POST['outlet_id'];
        $coaId  = (int)$_POST['coa_id'];
        $tgl    = $_POST['tanggal'] ?? date('Y-m-d');
        $tipe   = $_POST['tipe'] ?? 'lainnya';
        $jumlah = (int)preg_replace('/\D/','',$_POST['jumlah']??'0');
        $arah   = in_array($_POST['arah']??'',['debit','kredit']) ? $_POST['arah'] : 'debit';
        $ket    = trim($_POST['keterangan']??'');
        if (!$jumlah || !$ket) { echo json_encode(['error'=>'Data tidak lengkap']); exit; }

        $db->prepare("INSERT INTO hl_jurnal_manual
            (tenant_id,outlet_id,coa_id,tanggal,periode,keterangan,tipe,jumlah,arah,input_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$oid,$coaId,$tgl,substr($tgl,0,7),$ket,$tipe,$jumlah,$arah,$uid]);
        // Invalidate laporan cache
        $db->prepare("DELETE FROM hl_laporan_cache WHERE tenant_id=? AND periode=?")
            ->execute([$tid, substr($tgl,0,7)]);
        logAudit('tambah','jurnal_manual',"$tipe: $ket Rp ".number_format($jumlah,0,',','.'));
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'delete_jurnal' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        $id = (int)$_POST['id'];
        $row = $db->prepare("SELECT periode FROM hl_jurnal_manual WHERE id=? AND tenant_id=?");
        $row->execute([$id,$tid]);
        $j = $row->fetch();
        if (!$j) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        $db->prepare("DELETE FROM hl_jurnal_manual WHERE id=? AND tenant_id=?")->execute([$id,$tid]);
        $db->prepare("DELETE FROM hl_laporan_cache WHERE tenant_id=? AND periode=?")->execute([$tid,$j['periode']]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── KAS BANK ───────────────────────────────────────────
    if ($action === 'list_kas_bank') {
        $oid = (int)($_GET['outlet_id'] ?? $oidSess);
        $rows = $db->prepare("SELECT * FROM hl_kas_bank WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY is_primary DESC, id");
        $rows->execute([$tid, $oid]);
        echo json_encode(['ok'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'add_kas_bank' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        $oid   = (int)$_POST['outlet_id'];
        $nama  = trim($_POST['nama_rekening']??'');
        $bank  = trim($_POST['bank']??'');
        $noRek = trim($_POST['nomor_rekening']??'');
        $saldo = (int)preg_replace('/\D/','',$_POST['saldo_awal']??'0');
        $tgl   = $_POST['saldo_awal_tanggal'] ?? date('Y-m-d');
        $prim  = (int)($_POST['is_primary']??0);
        if (!$nama || !$bank) { echo json_encode(['error'=>'Nama dan Bank wajib diisi']); exit; }
        if ($prim) $db->prepare("UPDATE hl_kas_bank SET is_primary=0 WHERE tenant_id=? AND outlet_id=?")->execute([$tid,$oid]);
        $db->prepare("INSERT INTO hl_kas_bank
            (tenant_id,outlet_id,nama_rekening,bank,nomor_rekening,saldo_awal,saldo_awal_tanggal,is_primary,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$oid,$nama,$bank,$noRek,$saldo,$tgl,$prim,$uid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'add_mutasi_bank' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        $kasId  = (int)$_POST['kas_bank_id'];
        $oid    = (int)$_POST['outlet_id'];
        $tgl    = $_POST['tanggal'] ?? date('Y-m-d');
        $ket    = trim($_POST['keterangan']??'');
        $tipe   = in_array($_POST['tipe']??'',['masuk','keluar']) ? $_POST['tipe'] : null;
        $jumlah = $tipe ? (int)preg_replace('/\D/','',$_POST['jumlah']??'0') : null;
        $saldoA = isset($_POST['saldo_akhir']) && $_POST['saldo_akhir']!==''
                    ? (int)preg_replace('/\D/','',$_POST['saldo_akhir']) : null;
        if (!$ket || (!$jumlah && $saldoA === null)) { echo json_encode(['error'=>'Data tidak lengkap']); exit; }
        $db->prepare("INSERT INTO hl_kas_bank_mutasi
            (tenant_id,outlet_id,kas_bank_id,tanggal,periode,keterangan,tipe,jumlah,saldo_akhir,input_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$oid,$kasId,$tgl,substr($tgl,0,7),$ket,$tipe,$jumlah,$saldoA,$uid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── COA LIST (untuk dropdown) ──────────────────────────
    if ($action === 'list_coa') {
        $tipe = $_GET['tipe'] ?? '';
        $q = "SELECT id,kode,nama,tipe FROM hl_coa WHERE tenant_id=? AND is_active=1";
        $p = [$tid];
        if ($tipe) { $q .= " AND tipe=?"; $p[] = $tipe; }
        $q .= " ORDER BY urutan,kode";
        $rows = $db->prepare($q);
        $rows->execute($p);
        echo json_encode(['ok'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── COA OPTIONS (untuk dropdown laporan buku besar) ──
    if ($action === 'coa_options') {
        try {
            $s = $db->prepare("SELECT id, kode, nama, tipe FROM hl_coa
                               WHERE tenant_id=? AND is_active=1 ORDER BY kode");
            $s->execute([$tid]);
            echo json_encode(['ok'=>true, 'data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── #10: Tambah akun COA baru (kode auto-generate) ──
    if ($action === 'add_coa' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        $nama = trim($_POST['nama'] ?? '');
        $tipe = $_POST['tipe'] ?? '';
        $prefixMap = [
            'aset_lancar'=>'1-1', 'aset_tetap'=>'1-2',
            'liabilitas_lancar'=>'2-1', 'liabilitas_jangka_panjang'=>'2-2',
            'ekuitas'=>'3-1', 'pendapatan'=>'4-1', 'pendapatan_lain'=>'4-1',
            'beban_pokok'=>'5-1', 'beban_operasional'=>'5-1', 'beban_lain'=>'5-1',
        ];
        if ($nama === '' || !isset($prefixMap[$tipe])) {
            echo json_encode(['error'=>'Nama & tipe akun wajib diisi.']); exit;
        }
        $prefix = $prefixMap[$tipe];
        // Cari kode terakhir dgn prefix sama → increment 3 digit terakhir
        $s = $db->prepare("SELECT kode FROM hl_coa WHERE tenant_id=? AND kode LIKE ? ORDER BY kode DESC LIMIT 1");
        $s->execute([$tid, $prefix.'%']);
        $last = $s->fetchColumn();
        $lastNum = $last ? (int)substr(preg_replace('/\D/','',$last), -3) : 0;
        $kode = $prefix . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);
        try {
            $db->prepare("INSERT INTO hl_coa (tenant_id, outlet_id, kode, nama, tipe, is_auto, is_active, urutan)
                          VALUES (?, NULL, ?, ?, ?, 0, 1, 99)")
               ->execute([$tid, $kode, $nama, $tipe]);
            $newId = (int)$db->lastInsertId();
            logAudit('tambah','keuangan_coa',"Akun COA: $kode $nama ($tipe)");
            echo json_encode(['ok'=>true, 'data'=>['id'=>$newId,'kode'=>$kode,'nama'=>$nama,'tipe'=>$tipe]]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    // ── #11: Import Aset Tetap dari Excel/CSV ──
    if ($action === 'import_aset' && $_SERVER['REQUEST_METHOD']==='POST') {
        requirePermission('keuangan.edit');
        requireNotGrace();
        if (empty($_FILES['file']['tmp_name'])) { echo json_encode(['error'=>'File tidak ada.']); exit; }
        $f   = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xlsx','xls'], true)) { echo json_encode(['error'=>'Format harus CSV/XLSX/XLS.']); exit; }
        if ($f['size'] > 5*1024*1024) { echo json_encode(['error'=>'File maksimal 5 MB.']); exit; }

        require_once ROOT . '/core/MigrationImporter.php';
        try {
            $rows = MigrationImporter::parseFile($f['tmp_name'], $ext);
        } catch (Throwable $e) { echo json_encode(['error'=>'Gagal baca file: '.$e->getMessage()]); exit; }
        if (empty($rows)) { echo json_encode(['error'=>'File kosong.']); exit; }

        // COA aset tetap & outlet untuk matching
        $coaList = $db->prepare("SELECT id,kode,nama FROM hl_coa WHERE tenant_id=? AND tipe='aset_tetap' AND is_active=1");
        $coaList->execute([$tid]);
        $coas = $coaList->fetchAll(PDO::FETCH_ASSOC);
        if (!$coas) { echo json_encode(['error'=>'Belum ada akun COA Aset Tetap. Tambahkan dulu via "+ Akun baru".']); exit; }
        $defaultCoa = (int)$coas[0]['id'];

        $outletList = $db->prepare("SELECT id,nama_outlet,is_main FROM outlets WHERE tenant_id=? AND status!='closed'");
        $outletList->execute([$tid]);
        $outs = $outletList->fetchAll(PDO::FETCH_ASSOC);
        $defaultOutlet = 0;
        foreach ($outs as $o) { if ($o['is_main']) { $defaultOutlet = (int)$o['id']; break; } }
        if (!$defaultOutlet && $outs) $defaultOutlet = (int)$outs[0]['id'];

        $pick = function(array $row, array $aliases) {
            foreach ($row as $k=>$v) { if (in_array(strtolower(trim((string)$k)), $aliases, true)) return trim((string)$v); }
            return '';
        };
        $matchCoa = function(string $kat) use ($coas, $defaultCoa) {
            $kat = strtolower(trim($kat));
            if ($kat === '') return $defaultCoa;
            foreach ($coas as $c) { if (stripos($c['nama'], $kat) !== false || strtolower($c['nama']) === $kat || $c['kode'] === $kat) return (int)$c['id']; }
            return $defaultCoa;
        };
        $matchOutlet = function(string $on) use ($outs, $defaultOutlet) {
            $on = strtolower(trim($on));
            if ($on === '') return $defaultOutlet;
            foreach ($outs as $o) { if (stripos($o['nama_outlet'], $on) !== false) return (int)$o['id']; }
            return $defaultOutlet;
        };
        $toDate = function(string $s) {
            $s = trim($s);
            if ($s === '') return date('Y-m-d');
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s,0,10);
            $t = strtotime($s);
            return $t ? date('Y-m-d', $t) : date('Y-m-d');
        };

        $imported = 0; $errors = [];
        $ins = $db->prepare("INSERT INTO hl_aset_tetap
            (tenant_id,outlet_id,coa_id,nama,tanggal_perolehan,nilai_perolehan,nilai_sisa,umur_ekonomis,metode_penyusutan,keterangan,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $i=>$row) {
            $baris = $i + 2;
            if (!is_array($row)) continue;
            $nama = $pick($row, ['nama','name','aset','nama aset','nama_aset','asset']);
            $perolRaw = $pick($row, ['nilai_perolehan','nilai perolehan','harga','harga perolehan','nilai','perolehan','cost']);
            $perol = (int)preg_replace('/\D/','', $perolRaw);
            if ($nama==='' && $perolRaw==='') continue;
            if ($nama==='') { $errors[]=['baris'=>$baris,'error'=>'Nama aset kosong']; continue; }
            if ($perol<=0) { $errors[]=['baris'=>$baris,'error'=>'Nilai perolehan tidak valid']; continue; }
            $sisa  = (int)preg_replace('/\D/','', $pick($row, ['nilai_sisa','nilai sisa','residu','salvage']));
            $umur  = (int)preg_replace('/\D/','', $pick($row, ['umur_ekonomis','umur','umur ekonomis','umur (bulan)','masa manfaat'])) ?: 60;
            $metode = stripos($pick($row, ['metode','metode_penyusutan','penyusutan']), 'menurun') !== false ? 'saldo_menurun' : 'garis_lurus';
            $coaId  = $matchCoa($pick($row, ['kategori','coa','akun','jenis','category']));
            $oid    = $matchOutlet($pick($row, ['outlet','cabang','lokasi']));
            $tgl    = $toDate($pick($row, ['tanggal_perolehan','tanggal','tgl','tgl_perolehan','date']));
            try {
                $ins->execute([$tid,$oid,$coaId,$nama,$tgl,$perol,$sisa,$umur,$metode,'',$uid]);
                $imported++;
            } catch (Throwable $e) { $errors[]=['baris'=>$baris,'error'=>$e->getMessage()]; }
        }
        logAudit('import','keuangan_aset',"Import $imported aset tetap dari Excel");
        echo json_encode(['ok'=>true,'result'=>['imported'=>$imported,'errors'=>$errors]]);
        exit;
    }

    if ($action === 'template_aset') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="template_aset_tetap.csv"');
        $out = fopen('php://output','w'); fputs($out,"\xEF\xBB\xBF");
        fputcsv($out, ['nama','kategori','outlet','tanggal_perolehan','nilai_perolehan','nilai_sisa','umur_ekonomis','metode']);
        fputcsv($out, ['Mesin Cuci LG 20kg','Mesin Cuci','','2024-01-15','15000000','1500000','60','garis_lurus']);
        fputcsv($out, ['Motor Kurir Honda','Kendaraan','','2023-06-01','18000000','3000000','48','garis_lurus']);
        fclose($out); exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'Action tidak dikenal']);
    exit;
}

// ── GET: Load data untuk render ───────────────────────────────
$allOutlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status!='closed' ORDER BY is_main DESC,nama_outlet");
$allOutlets->execute([$tid]);
$outlets = $allOutlets->fetchAll(PDO::FETCH_ASSOC);

$coaAsetTetap = $db->prepare("SELECT id,kode,nama FROM hl_coa WHERE tenant_id=? AND tipe='aset_tetap' AND is_active=1 ORDER BY kode");
$coaAsetTetap->execute([$tid]);
$coaAset = $coaAsetTetap->fetchAll(PDO::FETCH_ASSOC);

$coaLiab = $db->prepare("SELECT id,kode,nama FROM hl_coa WHERE tenant_id=? AND tipe IN ('liabilitas_lancar','liabilitas_jangka_panjang') AND is_active=1 ORDER BY kode");
$coaLiab->execute([$tid]);
$coaPinjaman = $coaLiab->fetchAll(PDO::FETCH_ASSOC);

$coaAll = $db->prepare("SELECT id,kode,nama,tipe FROM hl_coa WHERE tenant_id=? AND is_active=1 ORDER BY urutan,kode");
$coaAll->execute([$tid]);
$coaJurnal = $coaAll->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'hq-keuangan';
$pageTitle  = 'Data Keuangan';
require __DIR__ . '/_layout_open.php';
?>
<style>
  h1{font-size:1.3rem;font-weight:800;color:#0F1C3A;margin-bottom:18px}
  .keu-tabs{display:flex;gap:4px;margin-bottom:22px;border-bottom:2px solid #E5E7EB}
  .keu-tab{padding:9px 18px;font-size:13px;font-weight:600;color:#6B7280;border:none;background:none;
           cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;border-radius:6px 6px 0 0;font-family:inherit}
  .keu-tab.active{color:#0F1C3A;border-bottom-color:#35E8D5;background:#F0FDFB}
  .keu-panel{display:none}.keu-panel.active{display:block}

  .keu-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:18px}
  .keu-card h3{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px}

  table.keu-tbl{width:100%;border-collapse:collapse;font-size:13px}
  table.keu-tbl th{background:#F9FAFB;padding:9px 12px;text-align:left;font-size:11px;
                   color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
  table.keu-tbl th.num,table.keu-tbl td.num{text-align:right}
  table.keu-tbl td{padding:11px 12px;border-bottom:1px solid #F3F4F6;color:#1F2937}
  table.keu-tbl tr:last-child td{border-bottom:none}
  table.keu-tbl tr:hover td{background:#F9FAFB}
  .badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-aktif{background:#D1FAE5;color:#065F46}
  .badge-lunas{background:#E0F2FE;color:#0369A1}
  .badge-rusak,.badge-disposed{background:#FEE2E2;color:#991B1B}

  .keu-form{display:grid;gap:14px}
  .keu-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .keu-form .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  .keu-form label{font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;display:block}
  .keu-form input,.keu-form select,.keu-form textarea{
    width:100%;padding:9px 11px;border:1.5px solid #E5E7EB;border-radius:8px;
    font-size:13px;font-family:inherit;background:#fff;box-sizing:border-box}
  .keu-form input:focus,.keu-form select:focus{outline:none;border-color:#35E8D5}
  .keu-form textarea{resize:vertical;min-height:60px}

  .btn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;
       font-family:inherit;transition:.15s}
  .btn-primary{background:#0F1C3A;color:#fff}.btn-primary:hover{opacity:.85}
  .btn-teal{background:#35E8D5;color:#0F1C3A}.btn-teal:hover{background:#2dd4c4}
  .btn-red{background:#EF4444;color:#fff}.btn-red:hover{background:#DC2626}
  .btn-outline{background:#fff;border:1.5px solid #E5E7EB;color:#374151}
  .btn-outline:hover{background:#F9FAFB}
  .btn-sm{padding:5px 12px;font-size:12px}

  .outlet-sel{padding:7px 12px;border:1.5px solid #E5E7EB;border-radius:8px;
              font-size:13px;font-family:inherit;background:#fff;margin-bottom:16px}
  .bar-between{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
  .bar-between h3{margin:0}

  .persen-bar{height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden;margin-top:6px}
  .persen-fill{height:100%;background:#35E8D5;border-radius:3px;transition:width .4s}
  .persen-fill.warn{background:#F59E0B}
  .persen-fill.danger{background:#EF4444}

  #keuToast{position:fixed;bottom:24px;right:24px;background:#0F1C3A;color:#fff;padding:12px 20px;
            border-radius:10px;font-size:13px;display:none;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.2)}

  .modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;align-items:center;justify-content:center}
  .modal-bg.open{display:flex}
  .modal{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
  .modal h4{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:20px}
  .modal-footer{display:flex;gap:10px;margin-top:20px;justify-content:flex-end}

  /* Layout dua-kolom (form 360px + tabel, atau dua kartu 1fr 1fr) menumpuk
     jauh sebelum 640px — di HP besar/phablet pun tetap muat & tabel bisa discroll. */
  @media(max-width:900px){
    div[style*="360px 1fr"],div[style*="1fr 1fr"]{grid-template-columns:1fr!important}
    /* Izinkan track 1fr menyusut < konten (select opsi panjang) → form tak meluber ke kanan */
    div[style*="360px 1fr"] > *,div[style*="1fr 1fr"] > *{min-width:0}
    .keu-card{min-width:0}
    .keu-form select,.keu-form input,.keu-form textarea{min-width:0;max-width:100%}
    table.keu-tbl{display:block;overflow-x:auto;white-space:nowrap;-webkit-overflow-scrolling:touch}
  }
  @media(max-width:640px){
    h1{font-size:1.15rem}
    .keu-card{padding:14px}
    /* Tab bar bisa digeser mendatar, tak terpotong */
    .keu-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;gap:0}
    .keu-tab{white-space:nowrap;flex:0 0 auto;padding:9px 13px;font-size:12.5px}
    /* Grid form 2/3 kolom menumpuk di HP */
    .keu-form .row2,.keu-form .row3{grid-template-columns:1fr}
    /* Toolbar judul + kontrol: judul kiri, kontrol full-width di bawah */
    .bar-between{flex-wrap:wrap;gap:10px}
    .bar-between > div{flex-wrap:wrap;width:100%}
    .bar-between .outlet-sel{flex:1 1 100%;margin-bottom:0}
  }
</style>

<h1>💰 Data Keuangan</h1>

<div class="keu-tabs">
  <button class="keu-tab active" onclick="keuTab('aset',this)">🏭 Aset Tetap</button>
  <button class="keu-tab" onclick="keuTab('pinjaman',this)">🏦 Pinjaman</button>
  <button class="keu-tab" onclick="keuTab('jurnal',this)">📝 Jurnal Manual</button>
  <button class="keu-tab" onclick="keuTab('kasbank',this)">💳 Kas Bank</button>
</div>

<?php
// Outlet selector shared
$outletOptHtml = '';
foreach ($outlets as $o) {
    $sel = $o['id'] == $oidSess ? 'selected' : '';
    $outletOptHtml .= "<option value=\"{$o['id']}\" $sel>" . htmlspecialchars($o['nama_outlet']) . "</option>";
}
$coaAsetOpts = '';
foreach ($coaAset as $c) {
    $coaAsetOpts .= "<option value=\"{$c['id']}\">[{$c['kode']}] " . htmlspecialchars($c['nama']) . "</option>";
}
$coaPinjOpts = '';
foreach ($coaPinjaman as $c) {
    $coaPinjOpts .= "<option value=\"{$c['id']}\">[{$c['kode']}] " . htmlspecialchars($c['nama']) . "</option>";
}
$coaJurnalOpts = '';
foreach ($coaJurnal as $c) {
    $coaJurnalOpts .= "<option value=\"{$c['id']}\">[{$c['kode']}] " . htmlspecialchars($c['nama']) . "</option>";
}
?>

<!-- ══════════════ TAB: ASET TETAP ══════════════ -->
<div class="keu-panel active" id="tab-aset">
  <div class="keu-card">
    <div class="bar-between">
      <h3>🏭 Daftar Aset Tetap</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <select class="outlet-sel" id="asetOutlet" style="margin-bottom:0" onchange="loadAset()">
          <?= $outletOptHtml ?>
        </select>
        <button class="btn btn-light btn-sm" onclick="openImportAset()">📥 Import</button>
        <button class="btn btn-teal btn-sm" onclick="openModal('modalAset')">+ Tambah Aset</button>
      </div>
    </div>
    <table class="keu-tbl">
      <thead><tr>
        <th>Nama Aset</th><th>COA</th><th class="num">Perolehan</th>
        <th class="num">Nilai Buku</th><th class="num">Penyusutan/bln</th>
        <th>Umur</th><th>Aksi</th>
      </tr></thead>
      <tbody id="asetBody"><tr><td colspan="7" style="text-align:center;padding:20px;color:#9CA3AF">Memuat...</td></tr></tbody>
    </table>
    <div id="asetSummary" style="margin-top:12px;font-size:13px;color:#6B7280"></div>
  </div>
</div>

<!-- ══════════════ TAB: PINJAMAN ══════════════ -->
<div class="keu-panel" id="tab-pinjaman">
  <div class="keu-card">
    <div class="bar-between">
      <h3>🏦 Pinjaman & Cicilan</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <select class="outlet-sel" id="pinjOutlet" style="margin-bottom:0" onchange="loadPinjaman()">
          <?= $outletOptHtml ?>
        </select>
        <button class="btn btn-teal btn-sm" onclick="openModal('modalPinjaman')">+ Tambah Pinjaman</button>
      </div>
    </div>
    <table class="keu-tbl">
      <thead><tr>
        <th>Nama Pinjaman</th><th>Kreditur</th>
        <th class="num">Pokok</th><th class="num">Sisa Hutang</th>
        <th class="num">Cicilan/bln</th><th>Status</th><th>Aksi</th>
      </tr></thead>
      <tbody id="pinjBody"><tr><td colspan="7" style="text-align:center;padding:20px;color:#9CA3AF">Memuat...</td></tr></tbody>
    </table>
  </div>
</div>

<!-- ══════════════ TAB: JURNAL MANUAL ══════════════ -->
<div class="keu-panel" id="tab-jurnal">
  <div style="display:grid;grid-template-columns:360px 1fr;gap:16px">
    <div class="keu-card">
      <h3>📝 Input Jurnal</h3>
      <div class="keu-form" id="jurnalForm">
        <div>
          <label>Outlet</label>
          <select id="jurnalOutlet" class="outlet-sel" style="width:100%"><?= $outletOptHtml ?></select>
        </div>
        <div class="row2">
          <div><label>Tanggal</label><input type="date" id="jTgl" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Tipe Transaksi</label>
            <select id="jTipe">
              <option value="modal_disetor">Modal Disetor</option>
              <option value="prive">Prive / Penarikan Owner</option>
              <option value="persediaan">Persediaan Bahan</option>
              <option value="biaya_dimuka">Biaya Dibayar Dimuka</option>
              <option value="beban_manual">Beban Manual (Sewa/Utilitas)</option>
              <option value="pembayaran_hutang">Pembayaran Hutang</option>
              <option value="penerimaan_pinjaman">Penerimaan Pinjaman</option>
              <option value="koreksi">Koreksi</option>
              <option value="lainnya">Lainnya</option>
            </select>
          </div>
        </div>
        <div><label>Akun COA</label><select id="jCoa"><?= $coaJurnalOpts ?></select></div>
        <div class="row2">
          <div><label>Jumlah (Rp)</label><input type="text" id="jJumlah" placeholder="0" oninput="fmtInput(this)"></div>
          <div><label>Debit / Kredit</label>
            <select id="jArah">
              <option value="debit">Debit (keluar/tambah beban)</option>
              <option value="kredit">Kredit (masuk/tambah pendapatan)</option>
            </select>
          </div>
        </div>
        <div><label>Keterangan</label><input type="text" id="jKet" placeholder="Mis: Bayar sewa toko Okt 2025"></div>
        <button class="btn btn-primary" onclick="submitJurnal()">Simpan Jurnal</button>
      </div>
    </div>
    <div class="keu-card">
      <div class="bar-between">
        <h3>📋 Riwayat Jurnal</h3>
        <input type="month" id="jurnalPeriode" value="<?= date('Y-m') ?>" onchange="loadJurnal()" style="padding:6px 10px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px">
      </div>
      <table class="keu-tbl">
        <thead><tr>
          <th>Tanggal</th><th>Tipe</th><th>Akun</th>
          <th class="num">Jumlah</th><th>D/K</th><th>Keterangan</th><th></th>
        </tr></thead>
        <tbody id="jurnalBody"><tr><td colspan="7" style="text-align:center;padding:20px;color:#9CA3AF">Pilih periode</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════ TAB: KAS BANK ══════════════ -->
<div class="keu-panel" id="tab-kasbank">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="keu-card">
      <div class="bar-between">
        <h3>🏦 Rekening Bank</h3>
        <button class="btn btn-teal btn-sm" onclick="openModal('modalKasBank')">+ Tambah Rekening</button>
      </div>
      <div id="kasBankList">
        <div style="text-align:center;padding:20px;color:#9CA3AF">Memuat...</div>
      </div>
    </div>
    <div class="keu-card">
      <h3>📊 Input Mutasi Bulanan</h3>
      <div class="keu-form">
        <div><label>Rekening</label><select id="mutRekening"></select></div>
        <div><label>Tanggal</label><input type="date" id="mutTgl" value="<?= date('Y-m-d') ?>"></div>
        <div class="row2">
          <div><label>Tipe</label>
            <select id="mutTipe" onchange="toggleMutTipe()">
              <option value="masuk">Masuk</option>
              <option value="keluar">Keluar</option>
              <option value="saldo_akhir">Input Saldo Akhir Bulan</option>
            </select>
          </div>
          <div><label id="mutJumlahLabel">Jumlah (Rp)</label>
            <input type="text" id="mutJumlah" placeholder="0" oninput="fmtInput(this)">
          </div>
        </div>
        <div><label>Keterangan</label><input type="text" id="mutKet" placeholder="Mis: Transfer dari POS, Saldo akhir Okt"></div>
        <button class="btn btn-primary" onclick="submitMutasi()">Simpan Mutasi</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════ MODALS ══════════════ -->

<!-- Modal: Tambah Akun COA (#10) -->
<div class="modal-bg" id="modalAddCoa">
  <div class="modal" style="max-width:380px">
    <h4>➕ Tambah Akun COA</h4>
    <div class="keu-form">
      <div>
        <label>Nama Akun</label>
        <input type="text" id="addCoaNama" placeholder="Mis: Mesin Cuci Samsung / Pinjaman Koperasi">
      </div>
      <div>
        <label>Tipe Akun</label>
        <select id="addCoaTipe">
          <option value="aset_tetap">Aset Tetap</option>
          <option value="aset_lancar">Aset Lancar</option>
          <option value="liabilitas_jangka_panjang">Pinjaman Jangka Panjang</option>
          <option value="liabilitas_lancar">Liabilitas Lancar</option>
          <option value="ekuitas">Ekuitas</option>
          <option value="pendapatan">Pendapatan</option>
          <option value="beban_operasional">Beban Operasional</option>
        </select>
        <div style="font-size:11px;color:#9CA3AF;margin-top:4px">Kode akun dibuat otomatis sesuai tipe.</div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeModal('modalAddCoa')">Batal</button>
      <button class="btn btn-teal" onclick="submitAddCoa()">Simpan Akun</button>
    </div>
  </div>
</div>

<!-- Modal: Import Aset Tetap (#11) -->
<div class="modal-bg" id="modalImportAset">
  <div class="modal" style="max-width:440px">
    <h4>📥 Import Aset Tetap dari Excel</h4>
    <p style="font-size:13px;color:#6B7280;margin:6px 0 12px">
      Kolom dikenali: <strong>nama, kategori, outlet, tanggal_perolehan, nilai_perolehan,
      nilai_sisa, umur_ekonomis, metode</strong>. Kategori dicocokkan ke Akun COA Aset Tetap;
      outlet dicocokkan ke nama outlet (default: outlet utama).
    </p>
    <a href="keuangan.php?action=template_aset" class="btn btn-light btn-sm" style="margin-bottom:12px;display:inline-block">⬇️ Download Template</a>
    <div class="keu-form">
      <input type="file" id="importAsetFile" accept=".xlsx,.xls,.csv"
             style="width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-size:13px">
    </div>
    <div id="importAsetResult" style="margin-top:10px"></div>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeModal('modalImportAset')">Tutup</button>
      <button class="btn btn-teal" id="importAsetBtn" onclick="doImportAset()">📥 Import</button>
    </div>
  </div>
</div>

<!-- Modal: Tambah Aset Tetap -->
<div class="modal-bg" id="modalAset">
  <div class="modal">
    <h4>🏭 Tambah Aset Tetap</h4>
    <div class="keu-form">
      <div class="row2">
        <div><label>Outlet</label><select id="mAsetOutlet"><?= $outletOptHtml ?></select></div>
        <div><label>Akun COA <a href="javascript:" onclick="openAddCoa('aset_tetap','mAsetCoa')" style="font-size:11px;color:#0891B2;font-weight:600">+ Akun baru</a></label><select id="mAsetCoa"><?= $coaAsetOpts ?></select></div>
      </div>
      <div><label>Nama Aset</label><input type="text" id="mAsetNama" placeholder="Mis: Mesin Cuci LG 20kg #1"></div>
      <div class="row2">
        <div><label>Tanggal Perolehan</label><input type="date" id="mAsetTgl" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Metode Penyusutan</label>
          <select id="mAsetMetode">
            <option value="garis_lurus">Garis Lurus (Straight-Line)</option>
            <option value="saldo_menurun">Saldo Menurun</option>
          </select>
        </div>
      </div>
      <div class="row3">
        <div><label>Nilai Perolehan (Rp)</label><input type="text" id="mAsetPerol" placeholder="0" oninput="fmtInput(this)"></div>
        <div><label>Nilai Sisa (Rp)</label><input type="text" id="mAsetSisa" placeholder="0" oninput="fmtInput(this)"></div>
        <div><label>Umur Ekonomis (bulan)</label><input type="number" id="mAsetUmur" value="60" min="1"></div>
      </div>
      <div><label>Keterangan (opsional)</label><textarea id="mAsetKet"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalAset')">Batal</button>
      <button class="btn btn-teal" onclick="submitAset()">Simpan Aset</button>
    </div>
  </div>
</div>

<!-- Modal: Tambah Pinjaman -->
<div class="modal-bg" id="modalPinjaman">
  <div class="modal">
    <h4>🏦 Tambah Pinjaman</h4>
    <div class="keu-form">
      <div class="row2">
        <div><label>Outlet</label><select id="mPinjOutlet"><?= $outletOptHtml ?></select></div>
        <div><label>Akun COA <a href="javascript:" onclick="openAddCoa('liabilitas_jangka_panjang','mPinjCoa')" style="font-size:11px;color:#0891B2;font-weight:600">+ Akun baru</a></label><select id="mPinjCoa"><?= $coaPinjOpts ?></select></div>
      </div>
      <div class="row2">
        <div><label>Nama Pinjaman</label><input type="text" id="mPinjNama" placeholder="Mis: KUR BRI 2025"></div>
        <div><label>Kreditur (Bank/Leasing)</label><input type="text" id="mPinjKreditur" placeholder="Mis: BRI"></div>
      </div>
      <div class="row3">
        <div><label>Pokok Pinjaman (Rp)</label><input type="text" id="mPinjPokok" placeholder="0" oninput="fmtInput(this)"></div>
        <div><label>Cicilan/Bulan (Rp)</label><input type="text" id="mPinjCicilan" placeholder="0" oninput="fmtInput(this)"></div>
        <div><label>Bunga/Bulan (%)</label><input type="number" id="mPinjBunga" value="0" step="0.01" min="0"></div>
      </div>
      <div class="row2">
        <div><label>Tanggal Mulai</label><input type="date" id="mPinjMulai" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Jatuh Tempo</label><input type="date" id="mPinjJt"></div>
      </div>
      <div><label>Keterangan</label><input type="text" id="mPinjKet"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalPinjaman')">Batal</button>
      <button class="btn btn-teal" onclick="submitPinjaman()">Simpan Pinjaman</button>
    </div>
  </div>
</div>

<!-- Modal: Catat Pembayaran -->
<div class="modal-bg" id="modalBayar">
  <div class="modal" style="max-width:380px">
    <h4>💳 Catat Pembayaran Cicilan</h4>
    <input type="hidden" id="bayarId">
    <div class="keu-form">
      <div><label>Pinjaman</label><div id="bayarNama" style="font-weight:700;color:#0F1C3A;padding:8px 0"></div></div>
      <div class="row2">
        <div><label>Tanggal Bayar</label><input type="date" id="bayarTgl" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Jumlah (Rp)</label><input type="text" id="bayarJumlah" placeholder="0" oninput="fmtInput(this)"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalBayar')">Batal</button>
      <button class="btn btn-primary" onclick="submitBayar()">Simpan Pembayaran</button>
    </div>
  </div>
</div>

<!-- Modal: Tambah Rekening Bank -->
<div class="modal-bg" id="modalKasBank">
  <div class="modal" style="max-width:420px">
    <h4>🏦 Tambah Rekening Bank</h4>
    <div class="keu-form">
      <div><label>Outlet</label><select id="mBankOutlet"><?= $outletOptHtml ?></select></div>
      <div class="row2">
        <div><label>Nama Rekening</label><input type="text" id="mBankNama" placeholder="Mis: BCA - Operasional"></div>
        <div><label>Bank</label><input type="text" id="mBankBank" placeholder="BCA / BRI / Mandiri"></div>
      </div>
      <div><label>Nomor Rekening</label><input type="text" id="mBankNoRek"></div>
      <div class="row2">
        <div><label>Saldo Awal (Rp)</label><input type="text" id="mBankSaldo" placeholder="0" oninput="fmtInput(this)"></div>
        <div><label>Per Tanggal</label><input type="date" id="mBankTgl" value="<?= date('Y-m-01') ?>"></div>
      </div>
      <div>
        <label><input type="checkbox" id="mBankPrimary"> Jadikan rekening utama</label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modalKasBank')">Batal</button>
      <button class="btn btn-teal" onclick="submitKasBank()">Simpan</button>
    </div>
  </div>
</div>

<div id="keuToast"></div>

<?php require __DIR__ . '/_layout_close.php'; ?>
<script>
const CSRF   = '<?= htmlspecialchars($csrf) ?>';
const OID    = <?= $oidSess ?>;

function keuTab(name, btn) {
    document.querySelectorAll('.keu-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.keu-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
    if (name === 'aset')    loadAset();
    if (name === 'pinjaman') loadPinjaman();
    if (name === 'jurnal')   loadJurnal();
    if (name === 'kasbank')  loadKasBank();
}

function toast(msg, ok = true) {
    const t = document.getElementById('keuToast');
    t.textContent = (ok ? '✓ ' : '✗ ') + msg;
    t.style.display = 'block';
    t.style.borderLeft = '3px solid ' + (ok ? '#35E8D5' : '#EF4444');
    clearTimeout(t._to);
    t._to = setTimeout(() => t.style.display = 'none', 3500);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── #10: Tambah akun COA baru on-the-fly ──
let _addCoaTarget = null; // id select tujuan
function openAddCoa(tipe, targetSelectId){
  _addCoaTarget = targetSelectId;
  document.getElementById('addCoaTipe').value = tipe;
  document.getElementById('addCoaNama').value = '';
  openModal('modalAddCoa');
  setTimeout(()=>document.getElementById('addCoaNama').focus(), 100);
}
async function submitAddCoa(){
  const nama = document.getElementById('addCoaNama').value.trim();
  const tipe = document.getElementById('addCoaTipe').value;
  if (!nama) { alert('Nama akun wajib diisi'); return; }
  const d = await api('add_coa', { nama, tipe }, 'POST');
  if (d.error) { alert('⚠️ ' + d.error); return; }
  // Tambahkan opsi baru ke select tujuan + pilih otomatis
  const c = d.data;
  if (_addCoaTarget) {
    const sel = document.getElementById(_addCoaTarget);
    const opt = new Option(`[${c.kode}] ${c.nama}`, c.id, true, true);
    sel.add(opt);
  }
  closeModal('modalAddCoa');
}

// ── #11: Import Aset Tetap ──
function openImportAset(){
  document.getElementById('importAsetFile').value = '';
  document.getElementById('importAsetResult').innerHTML = '';
  document.getElementById('importAsetBtn').disabled = false;
  openModal('modalImportAset');
}
async function doImportAset(){
  const file = document.getElementById('importAsetFile').files[0];
  const box  = document.getElementById('importAsetResult');
  if (!file) { alert('Pilih file dulu'); return; }
  document.getElementById('importAsetBtn').disabled = true;
  box.innerHTML = '<div style="color:#6B7280;font-size:13px">⏳ Memproses…</div>';
  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('file', file);
  try {
    const r = await fetch('keuangan.php?action=import_aset', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d = await r.json();
    if (d.error) { box.innerHTML = `<div style="color:#991B1B;font-size:13px">⚠️ ${d.error}</div>`; document.getElementById('importAsetBtn').disabled=false; return; }
    const res = d.result;
    let html = `<div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;padding:10px 12px;font-size:13px;color:#065F46">✅ <strong>${res.imported} aset</strong> berhasil di-import.</div>`;
    if (res.errors && res.errors.length){
      html += `<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 12px;font-size:12px;color:#991B1B;margin-top:8px">⚠️ ${res.errors.length} baris dilewati:<ul style="margin:6px 0 0 16px">${res.errors.slice(0,8).map(e=>`<li>Baris ${e.baris}: ${e.error}</li>`).join('')}${res.errors.length>8?`<li>…dan ${res.errors.length-8} lainnya</li>`:''}</ul></div>`;
    }
    box.innerHTML = html;
    loadAset();
  } catch(e){
    box.innerHTML = `<div style="color:#991B1B;font-size:13px">Gagal: ${e.message}</div>`;
    document.getElementById('importAsetBtn').disabled = false;
  }
}

function fmtInput(el) {
    const raw = el.value.replace(/\D/g, '');
    el.value = raw ? Number(raw).toLocaleString('id-ID') : '';
}

function unFmt(val) { return parseInt((val||'0').replace(/\D/g,'')) || 0; }
function rp(n) { return 'Rp ' + Number(n||0).toLocaleString('id-ID'); }

async function api(action, data = {}, method = 'GET') {
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const url = 'keuangan.php?action=' + action;
    const r = await fetch(url, method === 'POST'
        ? { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        : { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } }
    );
    return r.json();
}

// ── ASET TETAP ─────────────────────────────────────────────────
async function loadAset() {
    const oid = document.getElementById('asetOutlet').value;
    const d = await (await fetch(`keuangan.php?action=list_aset&outlet_id=${oid}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } })).json();
    if (!d.ok) { document.getElementById('asetBody').innerHTML = `<tr><td colspan="7" style="color:#EF4444;padding:12px">${d.error}</td></tr>`; return; }
    const rows = d.data;
    const today = new Date().toISOString().slice(0, 7);
    let totalBuku = 0;
    const tbody = rows.map(a => {
        const perBulan = a['umur_ekonomis'] > 0
            ? Math.round((a['nilai_perolehan'] - a['nilai_sisa']) / a['umur_ekonomis']) : 0;
        // Nilai buku estimasi
        const bulanPakai = Math.floor(
            (new Date(today + '-01') - new Date(a['tanggal_perolehan'])) / (30.44 * 86400 * 1000)
        );
        const akum = Math.min(bulanPakai, a['umur_ekonomis']) * perBulan;
        const buku = Math.max(a['nilai_sisa'], a['nilai_perolehan'] - akum);
        totalBuku += buku;
        const pct = a['umur_ekonomis'] > 0 ? Math.min(100, Math.round(bulanPakai / a['umur_ekonomis'] * 100)) : 100;
        const clr = pct >= 90 ? 'danger' : pct >= 60 ? 'warn' : '';
        return `<tr>
          <td><strong>${esc(a.nama)}</strong><br><span style="font-size:11px;color:#9CA3AF">${a.tanggal_perolehan}</span></td>
          <td style="font-size:12px;color:#6B7280">${esc(a.coa_nama||'-')}</td>
          <td class="num">${rp(a.nilai_perolehan)}</td>
          <td class="num"><strong>${rp(buku)}</strong></td>
          <td class="num">${rp(perBulan)}</td>
          <td style="min-width:90px">
            <span style="font-size:11px">${bulanPakai}/${a.umur_ekonomis} bln</span>
            <div class="persen-bar"><div class="persen-fill ${clr}" style="width:${pct}%"></div></div>
          </td>
          <td>
            <button class="btn btn-outline btn-sm" onclick="disposeAset(${a.id},'${esc(a.nama)}')">Lepas Aset</button>
          </td>
        </tr>`;
    }).join('');
    document.getElementById('asetBody').innerHTML = tbody || '<tr><td colspan="7" style="text-align:center;padding:20px;color:#9CA3AF">Belum ada aset tetap</td></tr>';
    document.getElementById('asetSummary').textContent = `Total ${rows.length} aset aktif · Nilai Buku Total: ${rp(totalBuku)}`;
}

async function submitAset() {
    const d = await api('add_aset', {
        outlet_id: document.getElementById('mAsetOutlet').value,
        coa_id: document.getElementById('mAsetCoa').value,
        nama: document.getElementById('mAsetNama').value,
        tanggal_perolehan: document.getElementById('mAsetTgl').value,
        nilai_perolehan: unFmt(document.getElementById('mAsetPerol').value),
        nilai_sisa: unFmt(document.getElementById('mAsetSisa').value),
        umur_ekonomis: document.getElementById('mAsetUmur').value,
        metode_penyusutan: document.getElementById('mAsetMetode').value,
        keterangan: document.getElementById('mAsetKet').value,
    }, 'POST');
    if (d.ok) { closeModal('modalAset'); toast('Aset berhasil disimpan'); loadAset(); }
    else toast(d.error || 'Gagal', false);
}

async function disposeAset(id, nama) {
    // Pilihan: dijual / rusak / dilepas (disimpan ke DB sbg dijual/rusak/disposed)
    const pilihan = prompt(`Pelepasan aset "${nama}".\nKetik salah satu:\n• jual  — aset dijual\n• rusak — aset rusak/tidak terpakai\n• lepas — dihapuskan dari pembukuan`, 'lepas');
    if (!pilihan) return;
    const map = { jual: 'dijual', rusak: 'rusak', lepas: 'disposed' };
    const status = map[pilihan.trim().toLowerCase()] || 'disposed';
    const nilaiJual = status === 'dijual' ? prompt('Nilai jual (Rp)?', '0') : '0';
    const d = await api('dispose_aset', { id, status, nilai_jual: unFmt(nilaiJual), tanggal_dispose: new Date().toISOString().slice(0,10) }, 'POST');
    if (d.ok) { toast('Aset berhasil dilepas'); loadAset(); }
    else toast(d.error || 'Gagal', false);
}

// ── PINJAMAN ───────────────────────────────────────────────────
async function loadPinjaman() {
    const oid = document.getElementById('pinjOutlet').value;
    const d = await (await fetch(`keuangan.php?action=list_pinjaman&outlet_id=${oid}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } })).json();
    if (!d.ok) return;
    const tbody = d.data.map(p => {
        const saldo = p['saldo_awal'] - p['saldo_terbayar'];
        const pct = p['saldo_awal'] > 0 ? Math.round(p['saldo_terbayar'] / p['saldo_awal'] * 100) : 100;
        const badgeClass = p.status === 'lunas' ? 'badge-lunas' : 'badge-aktif';
        return `<tr>
          <td><strong>${esc(p.nama)}</strong>
            <div class="persen-bar" style="width:120px;margin-top:4px"><div class="persen-fill" style="width:${pct}%"></div></div>
          </td>
          <td style="font-size:12px;color:#6B7280">${esc(p.kreditur||'-')}</td>
          <td class="num">${rp(p.pokok_pinjaman)}</td>
          <td class="num"><strong style="color:${saldo > 0 ? '#EF4444' : '#34D399'}">${rp(saldo)}</strong></td>
          <td class="num">${rp(p.cicilan_per_bulan)}</td>
          <td><span class="badge ${badgeClass}">${p.status}</span></td>
          <td>${p.status === 'aktif' ? `<button class="btn btn-outline btn-sm" onclick="openBayar(${p.id},'${esc(p.nama)}',${p.cicilan_per_bulan})">Bayar</button>` : ''}</td>
        </tr>`;
    }).join('');
    document.getElementById('pinjBody').innerHTML = tbody || '<tr><td colspan="7" style="text-align:center;padding:20px;color:#9CA3AF">Belum ada pinjaman</td></tr>';
}

async function submitPinjaman() {
    const d = await api('add_pinjaman', {
        outlet_id: document.getElementById('mPinjOutlet').value,
        coa_id: document.getElementById('mPinjCoa').value,
        nama: document.getElementById('mPinjNama').value,
        kreditur: document.getElementById('mPinjKreditur').value,
        pokok_pinjaman: unFmt(document.getElementById('mPinjPokok').value),
        cicilan_per_bulan: unFmt(document.getElementById('mPinjCicilan').value),
        bunga_per_bulan: document.getElementById('mPinjBunga').value,
        tanggal_mulai: document.getElementById('mPinjMulai').value,
        tanggal_jatuh_tempo: document.getElementById('mPinjJt').value,
        keterangan: document.getElementById('mPinjKet').value,
    }, 'POST');
    if (d.ok) { closeModal('modalPinjaman'); toast('Pinjaman tersimpan'); loadPinjaman(); }
    else toast(d.error || 'Gagal', false);
}

function openBayar(id, nama, cicilan) {
    document.getElementById('bayarId').value = id;
    document.getElementById('bayarNama').textContent = nama;
    document.getElementById('bayarJumlah').value = cicilan.toLocaleString('id-ID');
    openModal('modalBayar');
}

async function submitBayar() {
    const d = await api('bayar_pinjaman', {
        id: document.getElementById('bayarId').value,
        jumlah: unFmt(document.getElementById('bayarJumlah').value),
        tanggal: document.getElementById('bayarTgl').value,
    }, 'POST');
    if (d.ok) {
        closeModal('modalBayar');
        toast(d.lunas ? 'Pinjaman LUNAS! 🎉' : 'Pembayaran dicatat');
        loadPinjaman();
    } else toast(d.error || 'Gagal', false);
}

// ── JURNAL MANUAL ──────────────────────────────────────────────
async function loadJurnal() {
    const oid     = document.getElementById('jurnalOutlet').value;
    const periode = document.getElementById('jurnalPeriode').value;
    const d = await (await fetch(`keuangan.php?action=list_jurnal&outlet_id=${oid}&periode=${periode}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } })).json();
    if (!d.ok) return;
    const tipeLabel = { modal_disetor:'Modal', prive:'Prive', persediaan:'Persediaan',
        biaya_dimuka:'Biaya Dimuka', beban_manual:'Beban', pembayaran_hutang:'Bayar Hutang',
        penerimaan_pinjaman:'Pinjaman', koreksi:'Koreksi', lainnya:'Lain' };
    const tbody = d.data.map(j =>
        `<tr>
          <td style="font-size:12px">${j.tanggal}</td>
          <td><span class="badge" style="background:#E0F2FE;color:#0369A1">${tipeLabel[j.tipe]||j.tipe}</span></td>
          <td style="font-size:11px;color:#6B7280">[${j.kode}] ${esc(j.coa_nama)}</td>
          <td class="num">${rp(j.jumlah)}</td>
          <td style="font-size:12px;font-weight:700;color:${j.arah==='kredit'?'#34D399':'#EF4444'}">${j.arah.toUpperCase()}</td>
          <td style="font-size:12px">${esc(j.keterangan)}</td>
          <td><button class="btn btn-outline btn-sm" style="color:#EF4444" onclick="deleteJurnal(${j.id})">✕</button></td>
        </tr>`
    ).join('');
    document.getElementById('jurnalBody').innerHTML = tbody || '<tr><td colspan="7" style="text-align:center;padding:20px;color:#9CA3AF">Belum ada jurnal</td></tr>';
}

async function submitJurnal() {
    const d = await api('add_jurnal', {
        outlet_id: document.getElementById('jurnalOutlet').value,
        coa_id: document.getElementById('jCoa').value,
        tanggal: document.getElementById('jTgl').value,
        tipe: document.getElementById('jTipe').value,
        jumlah: unFmt(document.getElementById('jJumlah').value),
        arah: document.getElementById('jArah').value,
        keterangan: document.getElementById('jKet').value,
    }, 'POST');
    if (d.ok) {
        toast('Jurnal tersimpan');
        document.getElementById('jJumlah').value = '';
        document.getElementById('jKet').value = '';
        loadJurnal();
    } else toast(d.error || 'Gagal', false);
}

async function deleteJurnal(id) {
    if (!confirm('Hapus jurnal ini?')) return;
    const d = await api('delete_jurnal', { id }, 'POST');
    if (d.ok) { toast('Jurnal dihapus'); loadJurnal(); }
    else toast(d.error || 'Gagal', false);
}

// ── KAS BANK ───────────────────────────────────────────────────
async function loadKasBank() {
    const oid = OID;
    const d = await (await fetch(`keuangan.php?action=list_kas_bank&outlet_id=${oid}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } })).json();
    if (!d.ok) return;
    const selMut = document.getElementById('mutRekening');
    selMut.innerHTML = '';
    const html = d.data.map(b => {
        selMut.innerHTML += `<option value="${b.id}">${esc(b.nama_rekening)}</option>`;
        return `<div style="padding:14px 0;border-bottom:1px solid #F3F4F6">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
              <strong>${esc(b.nama_rekening)}</strong>
              ${b.is_primary ? '<span class="badge badge-aktif" style="margin-left:6px">Utama</span>' : ''}
              <div style="font-size:12px;color:#9CA3AF">${esc(b.bank)} · ${b.nomor_rekening||'-'}</div>
            </div>
            <div style="text-align:right">
              <div style="font-size:11px;color:#9CA3AF">Saldo awal ${rp(b.saldo_awal)}</div>
              <div style="font-size:11px;color:#9CA3AF">per ${b.saldo_awal_tanggal}</div>
            </div>
          </div>
        </div>`;
    }).join('');
    document.getElementById('kasBankList').innerHTML = html || '<div style="color:#9CA3AF;padding:20px;text-align:center">Belum ada rekening bank</div>';
}

async function submitKasBank() {
    const d = await api('add_kas_bank', {
        outlet_id: document.getElementById('mBankOutlet').value,
        nama_rekening: document.getElementById('mBankNama').value,
        bank: document.getElementById('mBankBank').value,
        nomor_rekening: document.getElementById('mBankNoRek').value,
        saldo_awal: unFmt(document.getElementById('mBankSaldo').value),
        saldo_awal_tanggal: document.getElementById('mBankTgl').value,
        is_primary: document.getElementById('mBankPrimary').checked ? 1 : 0,
    }, 'POST');
    if (d.ok) { closeModal('modalKasBank'); toast('Rekening tersimpan'); loadKasBank(); }
    else toast(d.error || 'Gagal', false);
}

function toggleMutTipe() {
    const t = document.getElementById('mutTipe').value;
    document.getElementById('mutJumlahLabel').textContent =
        t === 'saldo_akhir' ? 'Saldo Akhir Bulan (Rp)' : 'Jumlah (Rp)';
}

async function submitMutasi() {
    const tipe = document.getElementById('mutTipe').value;
    const isSaldoAkhir = tipe === 'saldo_akhir';
    const d = await api('add_mutasi_bank', {
        kas_bank_id: document.getElementById('mutRekening').value,
        outlet_id: OID,
        tanggal: document.getElementById('mutTgl').value,
        keterangan: document.getElementById('mutKet').value,
        tipe: isSaldoAkhir ? '' : tipe,
        jumlah: isSaldoAkhir ? '' : unFmt(document.getElementById('mutJumlah').value),
        saldo_akhir: isSaldoAkhir ? unFmt(document.getElementById('mutJumlah').value) : '',
    }, 'POST');
    if (d.ok) { toast('Mutasi tersimpan'); document.getElementById('mutKet').value=''; document.getElementById('mutJumlah').value=''; }
    else toast(d.error || 'Gagal', false);
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

// Init
loadAset();
</script>
</body>
</html>
