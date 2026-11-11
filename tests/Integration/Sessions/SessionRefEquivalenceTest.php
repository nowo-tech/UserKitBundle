<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Closure;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Model\SessionRef;
use Nowo\UserKitBundle\Sessions\SessionHashesInterface;
use Nowo\UserKitBundle\Sessions\Ui\SessionRow;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Sessions\UserSessionsInterface;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\HeaderDeviceIdResolver;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\LegacyHeaderDeviceIdResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function array_map;
use function sort;

/**
 * REQ-ARCH-001 rule 3 equivalence (1.15): the framework-free `SessionHashesInterface::sessionHash()` /
 * `UserSessionManager::sessionRef()` and `DeviceIdProviderInterface` give the same registry hashes, the same kept /
 * revoked sessions and the same stored device ids as the deprecated `currentHash(Request|SessionInterface)` and
 * `DeviceIdResolverInterface` they replace.
 */
final class SessionRefEquivalenceTest extends SessionsKernelTestCase
{
    public function testSessionHashMatchesTheDeprecatedCurrentHash(): void
    {
        $this->boot(services: $this->publicPorts());
        $this->member('alice@example.com');
        $laptop  = $this->signIn('alice@example.com');
        $manager = $this->manager();
        $session = $laptop->getRequest()->getSession();
        $request = $laptop->getRequest();

        $this->expectUserDeprecationMessage('Since nowo-tech/user-kit-bundle 1.15: "UserSessionsInterface::currentHash()" is deprecated, use "SessionHashesInterface::sessionHash()" with "UserSessionManager::sessionRef()" instead.');
        $legacy = $manager->currentHash($session);
        self::assertNotNull($legacy);
        self::assertSame($legacy, $manager->currentHash($request));
        self::assertSame($legacy, $manager->sessionHash($manager->sessionRef($session)));
        self::assertSame($legacy, $manager->sessionHash($manager->sessionRef($request)));
        self::assertSame($legacy, $manager->sessionHash(new SessionRef($session->getId())));
        self::assertSame($legacy, $manager->sessionHash($session->getId()));
        self::assertSame($legacy, $this->hashes()->sessionHash($manager->sessionRef($session)));

        // Not registered: no session, a fresh session, a session with a malformed marker, null.
        $fresh     = new Session(new MockArraySessionStorage());
        $malformed = new Session(new MockArraySessionStorage());
        $malformed->set(UserSessionManager::ATTRIBUTE, ['main' => ['h' => 1]]);
        foreach ([null, new Request(), $fresh, $malformed] as $case) {
            self::assertNull($manager->currentHash($case));
            self::assertNull($manager->sessionRef($case));
            self::assertNull($manager->sessionHash($manager->sessionRef($case)));
        }
        self::assertNull($manager->sessionHash(null));
        self::assertNull($manager->sessionHash(''), 'An empty id has no hash');
    }

    public function testRevokeOthersKeepsTheSameSessionWithTheNewHash(): void
    {
        $this->boot(services: $this->publicPorts());
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1');
        $tablet = $this->signIn('alice@example.com', 'Mozilla/5.0 (Linux; Android 15) Chrome/131.0 Mobile Safari/537.36');
        $hash   = $this->hashes()->sessionHash($this->manager()->sessionRef($laptop->getRequest()->getSession()));

        $own = array_map(static fn (SessionRow $row): bool => $row->current, $this->sessions()->listRowsFor($alice, $hash));
        sort($own);
        self::assertSame([false, false, true], $own);

        self::assertSame(2, $this->sessions()->revokeOthers($alice, $hash));
        foreach ([$phone, $tablet] as $other) {
            $other->request('GET', '/page');
            self::assertRedirectsTo($other, '/login');
        }
        $laptop->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($laptop));
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testDeviceIdProviderStoresWhatTheLegacyResolverStored(): void
    {
        $stored = [];
        foreach ([HeaderDeviceIdResolver::class, LegacyHeaderDeviceIdResolver::class] as $resolver) {
            if ($resolver === LegacyHeaderDeviceIdResolver::class) {
                $this->expectUserDeprecationMessageMatches('/implementing "DeviceIdResolverInterface"/');
            }
            $this->boot(['client' => ['device_id_resolver' => $resolver]], services: static function (ContainerConfigurator $c) use ($resolver): void {
                $c->services()->set($resolver);
            });
            $alice = $this->member('alice@example.com');
            foreach (['tablet-1', 'phone-1', ''] as $device) {
                $browser = $this->browser();
                if ($device !== '') {
                    $browser->setServerParameter('HTTP_X_DEVICE', $device);
                }
                $browser->request('POST', '/login', ['_username' => 'alice@example.com', '_password' => 'secret']);
            }
            $devices = array_map(static fn (UserSession $row): ?string => $row->getDeviceId(), $this->rowsOf($alice));
            sort($devices);
            $stored[$resolver] = [$devices, $this->manager()->revokeDevice($alice, 'tablet-1'), $this->manager()->revokeDevice($alice, 'phone-1'), $this->manager()->revokeDevice($alice, 'unknown')];
            $this->tearDown();
        }

        self::assertSame([[null, 'phone-1', 'tablet-1'], 1, 1, 0], $stored[HeaderDeviceIdResolver::class]);
        self::assertSame($stored[HeaderDeviceIdResolver::class], $stored[LegacyHeaderDeviceIdResolver::class]);
    }

    public function testThePortsAreAliasedToTheManager(): void
    {
        $this->boot(services: $this->publicPorts());
        self::assertSame($this->manager(), $this->hashes());
        self::assertSame($this->manager(), $this->sessions());
    }

    private function publicPorts(): Closure
    {
        return static function (ContainerConfigurator $c): void {
            $c->services()->alias('test.session_hashes', SessionHashesInterface::class)->public();
            $c->services()->alias('test.user_sessions', UserSessionsInterface::class)->public();
        };
    }

    private function hashes(): SessionHashesInterface
    {
        $hashes = $this->container()->get('test.session_hashes');
        self::assertInstanceOf(SessionHashesInterface::class, $hashes);

        return $hashes;
    }

    private function sessions(): UserSessionsInterface
    {
        $sessions = $this->container()->get('test.user_sessions');
        self::assertInstanceOf(UserSessionsInterface::class, $sessions);

        return $sessions;
    }
}
