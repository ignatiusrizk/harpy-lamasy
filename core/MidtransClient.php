<?php
// ══════════════════════════════════════════════════════
// core/MidtransClient.php
// Midtrans REST API wrapper — Charge API + signature verification.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/BillingConfig.php';

class MidtransClient
{
    private static function baseUrl(): string
    {
        $env = BillingConfig::get('midtrans_env', 'sandbox');
        return $env === 'production'
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    private static function serverKey(): string
    {
        $key = BillingConfig::get('midtrans_server_key', '');
        if (!$key) {
            throw new RuntimeException('Midtrans server_key belum di-set. Set di /superadmin/billing-config.php');
        }
        return $key;
    }

    /**
     * Snap checkout (halaman bayar hosted Midtrans). Dipakai sejak akun production
     * baru TIDAK bisa charge langsung per-channel via /v2/charge ("Payment channel
     * is not activated") — semua channel aktif otomatis tampil di halaman Snap,
     * termasuk BCA VA/QRIS begitu disetujui, tanpa perubahan kode. Webhook
     * notification-nya berformat sama dengan Core API → PaymentSettler tak berubah.
     */
    public static function createSnap(string $orderId, int $amount, array $customer, string $finishUrl = ''): array
    {
        $env  = BillingConfig::get('midtrans_env', 'sandbox');
        $base = $env === 'production' ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
        $payload = [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
            'customer_details'    => $customer,
            'item_details'        => [[ 'id' => $orderId, 'price' => $amount, 'quantity' => 1,
                                        'name' => 'LAMASY Payment - ' . $orderId ]],
            'expiry' => ['unit' => 'minutes',
                         'duration' => max(15, BillingConfig::getInt('payment_expiry_minutes', 15))],
        ];
        if ($finishUrl !== '') $payload['callbacks'] = ['finish' => $finishUrl];

        $ch = curl_init($base . '/snap/v1/transactions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json',
                                       'Authorization: ' . self::authHeader()],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($res === false) return ['ok' => false, 'error' => 'Koneksi Midtrans gagal: ' . $err];
        $j = json_decode($res, true) ?: [];
        if ($code === 201 && !empty($j['token']) && !empty($j['redirect_url'])) {
            return ['ok' => true, 'token' => $j['token'], 'redirect_url' => $j['redirect_url']];
        }
        return ['ok' => false,
            'error' => implode('; ', (array)($j['error_messages'] ?? ["HTTP $code"]))];
    }

    private static function authHeader(): string
    {
        return 'Basic ' . base64_encode(self::serverKey() . ':');
    }

    /**
     * Charge API — generate transaction (QRIS or VA).
     * @param string $orderId Unique order ID (LAM-...)
     * @param int $amount IDR
     * @param string $method 'qris' | 'bank_transfer'
     * @param array $customer ['first_name' => ..., 'email' => ..., 'phone' => ...]
     * @return array ['ok' => bool, 'data' => Midtrans response, 'error' => ?string]
     */
    public static function charge(string $orderId, int $amount, string $method, array $customer): array
    {
        $payload = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => $customer,
            'item_details' => [[
                'id'       => $orderId,
                'price'    => $amount,
                'quantity' => 1,
                'name'     => 'LAMASY Payment - ' . $orderId,
            ]],
        ];

        if ($method === 'qris') {
            $payload['payment_type'] = 'qris';
            $payload['qris'] = ['acquirer' => 'gopay'];
        } elseif ($method === 'bank_transfer') {
            // Default ke BCA. Frontend bisa let tenant pick bank later.
            $payload['payment_type'] = 'bank_transfer';
            $payload['bank_transfer'] = ['bank' => 'bca'];
        } else {
            return ['ok' => false, 'error' => "Method tidak didukung: $method"];
        }

        // Expiry config
        $expMin = BillingConfig::getInt('payment_expiry_minutes', 15);
        $payload['custom_expiry'] = [
            'expiry_duration' => $expMin,
            'unit'            => 'minute',
        ];

        $res = self::callApi('/v2/charge', $payload);

        // Midtrans bisa balas HTTP 2xx TAPI body status_code 4xx/5xx (mis. 402
        // "Payment channel is not activated"). callApi cuma cek HTTP → di sini
        // cek status_code body supaya charge gagal terdeteksi (bukan QR kosong).
        if (!empty($res['ok'])) {
            $sc = (int)($res['data']['status_code'] ?? 0);
            if ($sc >= 400) {
                return ['ok' => false,
                    'error' => $res['data']['status_message'] ?? "Charge ditolak (status $sc)"];
            }
        }
        return $res;
    }

    /**
     * Verify webhook signature (SHA-512).
     */
    public static function verifySignature(array $body): bool
    {
        if (empty($body['signature_key']) || empty($body['order_id']) ||
            empty($body['status_code']) || !isset($body['gross_amount'])) {
            return false;
        }
        $expected = hash('sha512',
            $body['order_id'] .
            $body['status_code'] .
            $body['gross_amount'] .
            self::serverKey()
        );
        return hash_equals($expected, $body['signature_key']);
    }

    /**
     * GET status untuk order_id.
     */
    public static function getStatus(string $orderId): array
    {
        return self::callApi('/v2/' . urlencode($orderId) . '/status', null, 'GET');
    }

    /**
     * Refund full atau partial.
     */
    public static function refund(string $orderId, int $amount, string $reason): array
    {
        return self::callApi('/v2/' . urlencode($orderId) . '/refund', [
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    /**
     * Internal cURL helper.
     */
    private static function callApi(string $path, ?array $payload = null, string $method = 'POST'): array
    {
        $url = self::baseUrl() . $path;
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . self::authHeader(),
        ];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => "cURL error: $err"];
        }
        $data = json_decode($resp, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'data' => $data];
        }
        return [
            'ok' => false,
            'error' => $data['error_messages'][0] ?? $data['status_message'] ?? "HTTP $httpCode",
            'data' => $data,
        ];
    }

    /**
     * Generate unique order_id.
     */
    public static function generateOrderId(string $type, int $tenantId): string
    {
        $typeShort = match ($type) {
            'topup_coin'        => 'TOPUP',
            'setup_fee'         => 'SETUP',
            'outlet_activation' => 'OUTLET',
            default             => 'GEN',
        };
        return sprintf('LAM-%s-%d-%d-%s', $typeShort, $tenantId, time(), bin2hex(random_bytes(3)));
    }
}
