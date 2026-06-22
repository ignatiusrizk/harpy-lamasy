<?php
// ══════════════════════════════════════════════════════
// api/wa_link.php — Generate wa.me URL untuk order
//
// GET ?order_id=123&t={order_diterima|order_ready|struk_lunas}
//   → 200 {url}
//   → 400 {error: 'bad input'}
//   → 404 {error: 'order not found'}
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/components.php';
require_once ROOT . '/core/TenantResolver.php';

header('Content-Type: application/json');

$orderId  = (int)($_GET['order_id'] ?? 0);
$template = $_GET['t'] ?? '';

if ($orderId <= 0 || !array_key_exists($template, WA_TEMPLATES)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter tidak valid.']);
    exit;
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();

$db = Database::get();
$st = $db->prepare(
    "SELECT t.no_order, t.nama_pelanggan, t.telepon, t.total, t.estimasi_selesai,
            o.nama_outlet
       FROM hl_transaksi t
  LEFT JOIN outlets o ON o.id = t.outlet_id
      WHERE t.id = ? AND t.tenant_id = ? AND t.outlet_id = ?
      LIMIT 1"
);
$st->execute([$orderId, $tid, $oid]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order tidak ditemukan.']);
    exit;
}

$tglAmbil = $order['estimasi_selesai']
    ? date('d M Y', strtotime($order['estimasi_selesai']))
    : '-';

$vars = [
    'nama'       => $order['nama_pelanggan'] ?: 'Pelanggan',
    'kode'       => $order['no_order'],
    'outlet'     => $order['nama_outlet'] ?: 'Laundry',
    'total'      => number_format((float)$order['total'], 0, ',', '.'),
    'tgl_ambil'  => $tglAmbil,
    'link_track' => APP_URL . '/track.php?order=' . urlencode($order['no_order']),
    'link_struk' => APP_URL . '/api/struk.php?action=generate&id=' . $orderId,
];

$url = waLink($order['telepon'], $template, $vars);

if ($url === null) {
    echo json_encode(['error' => 'Nomor WA pelanggan tidak valid atau kosong.']);
    exit;
}

logAudit('wa_link', 'order', "order_id={$orderId} template={$template}");

echo json_encode(['url' => $url]);
