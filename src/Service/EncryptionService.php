<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\EncryptionException;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Defuse\Crypto\Key;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class EncryptionService
{
    /** @var array<string, Key> */
    private array $keys;

    private string $currentVersion;

    public function __construct(
        #[Autowire('%env(ENCRYPTION_KEYS)%')]
        string $encryptionKeys,
        #[Autowire('%env(ENCRYPTION_CURRENT_VERSION)%')]
        ?string $currentVersion = null,
    ) {
        $parsedKeys = $this->parseAllKeys($encryptionKeys);
        $this->currentVersion = $currentVersion ?? array_key_last($parsedKeys);

        $keys = [];
        foreach ($parsedKeys as $version => $keyString) {
            $keys[$version] = Key::loadFromAsciiSafeString($keyString);
        }

        $this->keys = $keys;

        if (!isset($this->keys[$this->currentVersion])) {
            throw new EncryptionException(sprintf('Current encryption version "%s" is not defined in ENCRYPTION_KEYS.', $this->currentVersion));
        }
    }

    public function encrypt(string $plaintext): string
    {
        return $this->currentVersion . ':' . Crypto::encrypt($plaintext, $this->keys[$this->currentVersion]);
    }

    public function decrypt(string $ciphertext): string
    {
        // Backward compatibility: old unversioned ciphertext from before key versioning
        if (!str_contains($ciphertext, ':')) {
            try {
                return Crypto::decrypt($ciphertext, $this->keys[$this->currentVersion]);
            } catch (WrongKeyOrModifiedCiphertextException $e) {
                throw new EncryptionException('Decryption failed: invalid key or corrupted data.', previous: $e);
            }
        }

        $parts = explode(':', $ciphertext, 2);
        [$version, $encryptedData] = $parts;

        if (!isset($this->keys[$version])) {
            throw new EncryptionException(sprintf('Encryption version "%s" is not configured.', $version));
        }

        try {
            return Crypto::decrypt($encryptedData, $this->keys[$version]);
        } catch (WrongKeyOrModifiedCiphertextException $e) {
            throw new EncryptionException('Decryption failed: invalid key or corrupted data.', previous: $e);
        }
    }

    public function isEncrypted(string $value): bool
    {
        if (preg_match('/^v\d+:def[0-9a-f]{3}/i', $value)) {
            return true;
        }

        return preg_match('/^def[0-9a-f]{3}/i', $value) === 1;
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    public function getConfiguredVersions(): array
    {
        return array_keys($this->keys);
    }

    public function reencrypt(string $ciphertext, string $targetVersion): string
    {
        $plaintext = $this->decrypt($ciphertext);

        if (!isset($this->keys[$targetVersion])) {
            throw new EncryptionException(sprintf('Target encryption version "%s" is not configured.', $targetVersion));
        }

        return $targetVersion . ':' . Crypto::encrypt($plaintext, $this->keys[$targetVersion]);
    }

    /** @return array<string, string> */
    private function parseAllKeys(string $encryptionKeys): array
    {
        $keys = [];

        foreach (explode(',', $encryptionKeys) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            if (!str_contains($pair, ':')) {
                throw new EncryptionException('Invalid ENCRYPTION_KEYS format. Expected "version:key" pairs separated by commas.');
            }

            [$version, $key] = explode(':', $pair, 2);
            $version = trim($version);
            $key = trim($key);

            if ($version === '' || $key === '') {
                throw new EncryptionException('Invalid ENCRYPTION_KEYS format. Version and key cannot be empty.');
            }

            $keys[$version] = $key;
        }

        if ($keys === []) {
            throw new EncryptionException('ENCRYPTION_KEYS must contain at least one "version:key" pair.');
        }

        return $keys;
    }
}
