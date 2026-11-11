<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Connect;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\Session\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Connect\StateManager;

class StateManagerTest extends TestCase
{
    private const STORE_URL = 'https://shop.example.com/';
    private const CALLBACK_URL = 'https://shop.example.com/admin/skydropx/connect/callback/key/abc/';
    private const NOW = 1762459205;

    /**
     * @var Storage
     */
    private Storage $storage;

    /**
     * @var Session
     */
    private Session $session;

    /**
     * @var int
     */
    private int $now = self::NOW;

    protected function setUp(): void
    {
        // Real session object over an in-memory storage: setData goes through SessionManager::__call.
        $this->storage = new Storage();
        $this->session = (new \ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SessionManager::class, 'storage'))->setValue($this->session, $this->storage);
    }

    public function testNewStateIs64HexCharactersAndRandom(): void
    {
        $manager = $this->manager();
        $state = $manager->newState();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);
        $this->assertNotSame($state, $manager->newState());
    }

    public function testStartStoresOnlyTheHashOfTheState(): void
    {
        $state = str_repeat('a1', 32);
        $this->manager()->start($state, self::STORE_URL, self::CALLBACK_URL, 7);

        $stored = $this->storage->getData(StateManager::SESSION_KEY);
        $this->assertSame(
            [
                'state_hash' => hash('sha256', $state),
                'store_url' => self::STORE_URL,
                'callback_url' => self::CALLBACK_URL,
                'admin_user_id' => 7,
                'created_at' => self::NOW,
            ],
            $stored
        );
        $this->assertStringNotContainsString($state, (string) json_encode($stored));
    }

    public function testStartRejectsAMalformedState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->start('too-short', self::STORE_URL, self::CALLBACK_URL, 7);
    }

    public function testConsumeReturnsTheAttemptOnce(): void
    {
        $manager = $this->manager();
        $state = $manager->newState();
        $manager->start($state, self::STORE_URL, self::CALLBACK_URL, 7);

        $this->assertSame(
            ['store_url' => self::STORE_URL, 'callback_url' => self::CALLBACK_URL],
            $manager->consume($state, 7)
        );
        $this->assertNull($manager->consume($state, 7), 'A state is single use.');
        $this->assertNull($this->storage->getData(StateManager::SESSION_KEY));
    }

    public function testConsumeForgetsTheAttemptEvenWhenItDoesNotMatch(): void
    {
        $manager = $this->manager();
        $state = $manager->newState();
        $manager->start($state, self::STORE_URL, self::CALLBACK_URL, 7);

        $this->assertNull($manager->consume(str_repeat('b', 64), 7), 'hash_equals rejects another state.');
        $this->assertNull($manager->consume($state, 7), 'The attempt was removed by the failed check.');
    }

    public function testConsumeRejectsAnotherAdminUser(): void
    {
        $manager = $this->manager();
        $state = $manager->newState();
        $manager->start($state, self::STORE_URL, self::CALLBACK_URL, 7);

        $this->assertNull($manager->consume($state, 8));
    }

    public function testConsumeRejectsAMalformedState(): void
    {
        $manager = $this->manager();
        $manager->start($manager->newState(), self::STORE_URL, self::CALLBACK_URL, 7);

        $this->assertNull($manager->consume("bad state\n", 7));
    }

    public function testConsumeWithoutAttemptReturnsNull(): void
    {
        $this->assertNull($this->manager()->consume(str_repeat('a', 64), 7));
    }

    /**
     * @dataProvider ageProvider
     */
    public function testStateLivesForLessThan600Seconds(int $age, bool $valid): void
    {
        $manager = $this->manager();
        $state = $manager->newState();
        $manager->start($state, self::STORE_URL, self::CALLBACK_URL, 7);
        $this->now = self::NOW + $age;

        $this->assertSame($valid, $manager->consume($state, 7) !== null);
    }

    public static function ageProvider(): array
    {
        return [
            'fresh' => [0, true],
            'just in time' => [599, true],
            'expired at 600 s' => [600, false],
            'long expired' => [3600, false],
            'clock went back' => [-5, false],
        ];
    }

    public function testNewStartReplacesThePreviousAttempt(): void
    {
        $manager = $this->manager();
        $first = $manager->newState();
        $second = $manager->newState();
        $manager->start($first, self::STORE_URL, self::CALLBACK_URL, 7);
        $manager->start($second, 'https://other.example.com/', self::CALLBACK_URL, 7);

        $this->assertSame('https://other.example.com/', $manager->consume($second, 7)['store_url']);

        $manager->start($first, self::STORE_URL, self::CALLBACK_URL, 7);
        $manager->start($second, self::STORE_URL, self::CALLBACK_URL, 7);
        $this->assertNull($manager->consume($first, 7));
    }

    /**
     * @return StateManager&MockObject
     */
    private function manager(): StateManager
    {
        $manager = $this->getMockBuilder(StateManager::class)
            ->setConstructorArgs([$this->session])
            ->onlyMethods(['now'])
            ->getMock();
        $manager->method('now')->willReturnCallback(fn (): int => $this->now);

        return $manager;
    }
}
