<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Sessions\RegistrySessionInvalidator;
use Nowo\UserKitBundle\Sessions\Twig\SessionsTwigExtension;
use stdClass;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Hooks on other UserKit features: password change, account disabled (SessionInvalidatorInterface), personal data
 * export / erasure, table prefix, the Twig helper.
 */
final class SessionHooksTest extends SessionsKernelTestCase
{
    public function testAPasswordChangeSignsTheOtherSessionsOut(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com');

        $laptop->request('GET', '/page/password');
        self::assertSame('changed', $this->body($laptop));
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $laptop->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($laptop));
        self::assertCount(1, $this->rowsOf($alice));

        // Outside a request (CLI, admin of another user): every session goes.
        $alice = $this->em()->find(Fixtures\Member::class, $alice->getId()) ?? self::fail();
        $alice->setPassword('again');
        $this->em()->flush();
        self::assertCount(0, $this->rowsOf($alice));
    }

    public function testPasswordChangeHookCanBeSwitchedOff(): void
    {
        $this->boot(['password_change' => ['revoke_sessions' => false]]);
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $alice = $this->em()->find(Fixtures\Member::class, $alice->getId()) ?? self::fail();
        $alice->setPassword('again');
        $this->em()->flush();
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testDisablingTheAccountSignsEverySessionOut(): void
    {
        $this->boot([], ['account_status' => ['invalidate_sessions_on_disable' => true]]);
        self::assertInstanceOf(RegistrySessionInvalidator::class, $this->container()->get(SessionInvalidatorInterface::class));
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');

        $alice = $this->em()->find(Fixtures\Member::class, $alice->getId()) ?? self::fail();
        $alice->setEnabled(false);
        $this->em()->flush();
        self::assertCount(0, $this->rowsOf($alice));
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testPersonalDataExportAndErasure(): void
    {
        $this->boot([], ['personal_data' => ['enabled' => true]], services: static function (ContainerConfigurator $c): void {
            $c->services()->alias('test.exporter', PersonalDataExporter::class)->public();
            $c->services()->alias('test.eraser', PersonalDataEraser::class)->public();
        });
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');

        $exporter = $this->container()->get('test.exporter');
        self::assertInstanceOf(PersonalDataExporter::class, $exporter);
        $document = $exporter->export($alice);
        self::assertSame('Firefox', $document['sections']['sessions'][0]['browser'] ?? null);
        self::assertArrayNotHasKey('session_hash', $document['sections']['sessions'][0]);

        $eraser = $this->container()->get('test.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);
        self::assertContains('sessions', $eraser->erase($alice));
        self::assertSame([], $this->store()->allFor($this->manager()->owner($alice) ?? self::fail()));
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testTablePrefix(): void
    {
        $this->boot(['doctrine' => ['table_prefix' => 'app_']]);
        self::assertSame('app_user_kit_session', $this->store()->table());
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testTwigHelper(): void
    {
        $this->boot();
        $root  = $this->member('root@example.com', ['ROLE_ADMIN']);
        $alice = $this->member('alice@example.com');
        $admin = $this->signIn('root@example.com');
        $admin->request('GET', '/page');

        $extension = $this->container()->get(SessionsTwigExtension::class);
        self::assertInstanceOf(SessionsTwigExtension::class, $extension);
        self::assertSame('user_kit_sessions_admin_path', $extension->getFunctions()[0]->getName());
        self::assertNull($extension->adminPath(null));
        self::assertNull($extension->adminPath(new stdClass()));
        // Outside the request the guard sees no user: no link.
        self::assertNull($extension->adminPath($alice));
        unset($root);
    }
}
