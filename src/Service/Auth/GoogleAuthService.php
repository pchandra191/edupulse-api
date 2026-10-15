<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Google\Auth\OAuth2;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class GoogleAuthService
{
    public function __construct(
        private string $clientId,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function verifyIdToken(string $idToken): array
    {
        $this->logger->info('Verifying Google ID token');

        $publicKeys = $this->fetchGooglePublicKeys();
        $payload = $this->verifyWithOAuth2($idToken, $publicKeys);

        if ($payload === null) {
            $this->logger->warning('Google ID token verification failed: invalid token');
            throw new \InvalidArgumentException('Invalid Google ID token');
        }

        if (!isset($payload['email'])) {
            $this->logger->warning('Google ID token missing email claim');
            throw new \InvalidArgumentException('Google ID token missing email claim');
        }

        if (!isset($payload['email_verified']) || !$payload['email_verified']) {
            $this->logger->warning('Google ID token email not verified', ['email' => $payload['email']]);
            throw new \InvalidArgumentException('Google account email is not verified');
        }

        if (!isset($payload['iss']) || !in_array($payload['iss'], ['accounts.google.com', 'https://accounts.google.com'], true)) {
            $this->logger->warning('Google ID token invalid issuer', ['iss' => $payload['iss'] ?? 'missing']);
            throw new \InvalidArgumentException('Invalid token issuer');
        }

        if (!isset($payload['aud']) || $payload['aud'] !== $this->clientId) {
            $this->logger->warning('Google ID token audience mismatch', ['aud' => $payload['aud'] ?? 'missing']);
            throw new \InvalidArgumentException('Invalid token audience');
        }

        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            $this->logger->warning('Google ID token expired', ['exp' => $payload['exp'] ?? 'missing']);
            throw new \InvalidArgumentException('Google ID token has expired');
        }

        $this->logger->info('Google ID token verified successfully', ['email' => $payload['email']]);

        return $payload;
    }

    private function fetchGooglePublicKeys(): array
    {
        $response = $this->httpClient->request('GET', 'https://www.googleapis.com/oauth2/v3/certs');
        $keysData = $response->toArray(false);

        if (!isset($keysData['keys']) || !is_array($keysData['keys'])) {
            throw new \RuntimeException('Invalid Google public keys response');
        }

        $keys = [];
        foreach ($keysData['keys'] as $keyData) {
            if (isset($keyData['kid'], $keyData['kty'], $keyData['n'], $keyData['e']) && $keyData['kty'] === 'RSA') {
                $keys[$keyData['kid']] = $keyData;
            }
        }

        return $keys;
    }

    private function verifyWithOAuth2(string $idToken, array $publicKeys): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        if (!isset($header['kid']) || !isset($publicKeys[$header['kid']])) {
            return null;
        }

        $keyData = $publicKeys[$header['kid']];
        $publicKeyPem = $this->buildPublicKey($keyData);

        $oauth2 = new OAuth2(['client_id' => $this->clientId]);
        $oauth2->setIdToken($idToken);
        $oauth2->setAudience($this->clientId);

        try {
            $verified = $oauth2->verifyIdToken($publicKeyPem, 'RS256');
            return (array) $verified;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildPublicKey(array $keyData): string
    {
        $n = $this->base64UrlDecode($keyData['n']);
        $e = $this->base64UrlDecode($keyData['e']);

        // Build RSAPublicKey ASN.1 DER: SEQUENCE(modulus INTEGER, exponent INTEGER)
        $modulus = $this->encodeInteger($n);
        $exponent = $this->encodeInteger($e);
        $rsaPublicKey = $this->encodeSequence($modulus . $exponent);

        // Build SubjectPublicKeyInfo: SEQUENCE(AlgorithmIdentifier, BIT STRING)
        $algorithmId = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x00" . $rsaPublicKey;
        $bitStringDer = "\x03" . $this->encodeLength(strlen($bitString)) . $bitString;

        $subjectPublicKeyInfo = $this->encodeSequence($algorithmId . $bitStringDer);

        return '-----BEGIN PUBLIC KEY-----' . "\n" . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n") . '-----END PUBLIC KEY-----';
    }

    private function encodeInteger(string $value): string
    {
        if ($value !== '' && (ord($value[0]) & 0x80)) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->encodeLength(strlen($value)) . $value;
    }

    private function encodeSequence(string $value): string
    {
        return "\x30" . $this->encodeLength(strlen($value)) . $value;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}