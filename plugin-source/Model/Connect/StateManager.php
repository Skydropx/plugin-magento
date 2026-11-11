<?php
/**
 * One-time state of the one-click connection, kept in the admin session.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Connect;

use Magento\Backend\Model\Auth\Session;

/**
 * Only the SHA-256 of the state is stored. One live attempt per session: a new start replaces it.
 */
class StateManager
{
    public const SESSION_KEY = 'skydropx_connect';
    public const TTL = 600;

    /**
     * Printable ASCII, 16 to 256 characters (contract §4).
     */
    private const STATE_PATTERN = '/^[\x21-\x7e]{16,256}$/';

    /**
     * @param Session $session
     */
    public function __construct(private Session $session)
    {
    }

    /**
     * New random state: 64 hex characters.
     *
     * @return string
     */
    public function newState(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Remember a new attempt.
     *
     * @param string $state
     * @param string $storeUrl
     * @param string $callbackUrl
     * @param int $adminUserId
     * @return void
     */
    public function start(string $state, string $storeUrl, string $callbackUrl, int $adminUserId): void
    {
        if (preg_match(self::STATE_PATTERN, $state) !== 1) {
            throw new \InvalidArgumentException('The connect state has an invalid format.');
        }
        $this->session->setData(self::SESSION_KEY, [
            'state_hash' => hash('sha256', $state),
            'store_url' => $storeUrl,
            'callback_url' => $callbackUrl,
            'admin_user_id' => $adminUserId,
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Validate and forget the attempt (single use: it is removed whether it matches or not).
     *
     * @param string $state
     * @param int $adminUserId
     * @return array{store_url: string, callback_url: string}|null Null when the state is not valid.
     */
    public function consume(string $state, int $adminUserId): ?array
    {
        $stored = $this->session->getData(self::SESSION_KEY, true);
        if (!is_array($stored) || preg_match(self::STATE_PATTERN, $state) !== 1) {
            return null;
        }
        $createdAt = (int) ($stored['created_at'] ?? 0);
        $age = $this->now() - $createdAt;
        if ($createdAt <= 0 || $age < 0 || $age >= self::TTL) {
            return null;
        }
        if ($adminUserId <= 0 || (int) ($stored['admin_user_id'] ?? 0) !== $adminUserId) {
            return null;
        }
        if (!hash_equals((string) ($stored['state_hash'] ?? ''), hash('sha256', $state))) {
            return null;
        }

        return [
            'store_url' => (string) ($stored['store_url'] ?? ''),
            'callback_url' => (string) ($stored['callback_url'] ?? ''),
        ];
    }

    /**
     * Clock seam for tests.
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }
}
