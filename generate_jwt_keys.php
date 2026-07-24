<?php
$privateKeyPath = 'config/jwt/private.pem';
$publicKeyPath = 'config/jwt/public.pem';
$passphrase = 'f7ea54720c4f4793d904177d7db68eb2cd001e2d64e3d6beee2f91ee3c2db116';

$config = [
    "digest_alg" => "sha256",
    "private_key_bits" => 4096,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

$res = openssl_pkey_new($config);
if ($res === false) {
    echo "Failed to generate private key: " . openssl_error_string() . "\n";
    exit(1);
}

if (!openssl_pkey_export($res, $privateKeyPem, $passphrase)) {
    echo "Failed to export private key: " . openssl_error_string() . "\n";
    exit(1);
}

$publicKeyPem = openssl_pkey_get_details($res)['key'];

file_put_contents($privateKeyPath, $privateKeyPem);
file_put_contents($publicKeyPath, $publicKeyPem);

chmod($privateKeyPath, 0600);
chmod($publicKeyPath, 0644);

echo "JWT keys generated successfully.\n";
echo "Private key: $privateKeyPath\n";
echo "Public key: $publicKeyPath\n";
