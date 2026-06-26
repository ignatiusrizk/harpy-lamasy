<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/PushSender.php';

// Generate keypair RSA sementara untuk test signing.
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $priv);

$sa = [
    'client_email' => 'svc@proj.iam.gserviceaccount.com',
    'private_key'   => $priv,
    'token_uri'     => 'https://oauth2.googleapis.com/token',
];
$now = 1750000000;
$jwt = PushSender::buildJwt($sa, $now);

$parts = explode('.', $jwt);
eqv(count($parts), 3, "JWT punya 3 segmen");

$b64 = fn($s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
$header  = $b64($parts[0]);
$payload = $b64($parts[1]);
eqv($header['alg'], 'RS256', "header alg RS256");
eqv($payload['iss'], 'svc@proj.iam.gserviceaccount.com', "iss = client_email");
eqv($payload['aud'], 'https://oauth2.googleapis.com/token', "aud = token_uri");
eqv($payload['iat'], $now, "iat = now");
eqv($payload['exp'], $now + 3600, "exp = now + 3600");
ok(strpos($payload['scope'], 'firebase.messaging') !== false, "scope mengandung firebase.messaging");

// Verifikasi tanda tangan valid dengan public key.
$pub  = openssl_pkey_get_details($res)['key'];
$data = $parts[0] . '.' . $parts[1];
$sig  = base64_decode(strtr($parts[2], '-_', '+/'));
eqv(openssl_verify($data, $sig, $pub, OPENSSL_ALGO_SHA256), 1, "tanda tangan RS256 valid");

echo "ALL OK\n";
