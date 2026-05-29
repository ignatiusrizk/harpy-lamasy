<?php
// ══════════════════════════════════════════════════════
// api/struk.php — Generate struk & invoice endpoint
//
// GET  ?action=generate&id=123&tipe=retail&format=thermal_80
//      → HTML struk, potong coin
// GET  ?action=generate&id=123&preview=1
//      → HTML struk, TIDAK potong coin (untuk preview)
// GET  ?action=check_coin&tipe=retail
//      → JSON {can_afford: bool, balance: int, cost: int}
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/StrukGenerator.php';
require_once ROOT . '/core/CoinLedger.php';

$action = $_GET['action'] ?? '';

// ── Cek saldo coin ────────────────────────────────────
if ($action === 'check_coin') {
    header('Content-Type: application/json');
    $tipe    = $_GET['tipe'] ?? 'retail';
    $feature = $tipe === 'b2b' ? 'generate_invoice' : 'generate_nota';
    $cost    = CoinLedger::COSTS[$feature] ?? 0;
    echo json_encode([
        'can_afford' => CoinLedger::canAfford($feature),
        'balance'    => CoinLedger::getBalance(),
        'cost'       => $cost,
        'feature'    => $feature,
    ]);
    exit;
}

// ── Generate struk ────────────────────────────────────
if ($action === 'generate') {
    $trxId    = (int)($_GET['id'] ?? 0);
    $tipe     = in_array($_GET['tipe'] ?? '', ['retail','b2b']) ? $_GET['tipe'] : 'retail';
    $format   = $_GET['format'] ?? null;
    $isPreview = !empty($_GET['preview']);

    if (!$trxId) {
        http_response_code(400);
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => 'ID transaksi tidak valid']);
        exit;
    }

    // Cek coin jika bukan preview
    if (!$isPreview) {
        $feature = $tipe === 'b2b' ? 'generate_invoice' : 'generate_nota';
        if (!CoinLedger::canAfford($feature)) {
            http_response_code(402);
            header('Content-Type: application/json');
            $cost = CoinLedger::COSTS[$feature] ?? 0;
            echo json_encode([
                'error'   => 'Coin tidak cukup untuk generate ' . ($tipe === 'b2b' ? 'invoice' : 'nota') . ". Dibutuhkan {$cost} coin.",
                'code'    => 'insufficient_coin',
                'balance' => CoinLedger::getBalance(),
                'cost'    => $cost,
            ]);
            exit;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    try {
        echo StrukGenerator::generate($trxId, $tipe, $format, !$isPreview);
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px;color:#991B1B">';
        echo '<strong>Gagal generate struk:</strong> ' . htmlspecialchars($e->getMessage());
        echo '</body></html>';
        error_log('[api/struk.php] ' . $e->getMessage());
    }
    exit;
}

// ── Generate invoice B2B dari piutang ────────────────
if ($action === 'generate_invoice') {
    $piutangId = (int)($_GET['id'] ?? 0);
    $isPreview = !empty($_GET['preview']);

    if (!$piutangId) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ID piutang tidak valid']);
        exit;
    }

    if (!$isPreview && !CoinLedger::canAfford('generate_invoice')) {
        http_response_code(402);
        header('Content-Type: application/json');
        $cost = CoinLedger::COSTS['generate_invoice'] ?? 200;
        echo json_encode([
            'error'   => "Coin tidak cukup. Dibutuhkan {$cost} coin untuk generate invoice.",
            'code'    => 'insufficient_coin',
            'balance' => CoinLedger::getBalance(),
            'cost'    => $cost,
        ]);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    try {
        echo StrukGenerator::generateInvoice($piutangId, !$isPreview);
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px;color:#991B1B">';
        echo '<strong>Gagal generate invoice:</strong> ' . htmlspecialchars($e->getMessage());
        echo '</body></html>';
        error_log('[api/struk.php generate_invoice] ' . $e->getMessage());
    }
    exit;
}

// ── Fallback ──────────────────────────────────────────
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'Action tidak dikenal. Gunakan: generate, generate_invoice, check_coin']);
