<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Model\Preference\Theme;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Domain\Account;
use Nowo\UserKitBundle\Tests\Integration\UiPreferencesMappingKernelTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function json_decode;
use function sort;

use const JSON_THROW_ON_ERROR;

/**
 * REQ-ARCH-001 DDD host: XML-mapped aggregate (no attribute, no bundle base class or trait) embedding the kit
 * embeddables through the bundle's XML mapping, a non-entity security user, the framework-free device id port, and an
 * application service that drives the session registry through ports and value objects only.
 */
final class DddHostKernelTest extends TestCase
{
    private ?DddHostTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->kernel = new DddHostTestKernel($this->name());
        $this->kernel->boot();
        $em = $this->em();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Account::class), $em->getClassMetadata(UserSession::class)]);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(DddHostTestKernel::baseDir());
    }

    public function testTheAggregateCarriesNoMappingAttributeNorBundleBaseClass(): void
    {
        $class = new ReflectionClass(Account::class);

        self::assertSame([], $class->getAttributes());
        self::assertFalse($class->getParentClass());
        self::assertSame([], $class->getTraitNames());
        foreach ($class->getProperties() as $property) {
            self::assertSame([], $property->getAttributes(), $property->getName());
        }
    }

    public function testTheXmlMappingHasTheSameColumnsAsTheAttributeMapping(): void
    {
        $metadata = $this->em()->getClassMetadata(Account::class);

        $actual = [];
        foreach ($metadata->getFieldNames() as $field) {
            $mapping                        = $metadata->getFieldMapping($field);
            $actual[$mapping['columnName']] = [$mapping['type'], $mapping['length'] ?? null, (bool) ($mapping['nullable'] ?? false)];
        }
        foreach (UiPreferencesMappingKernelTest::EXPECTED_COLUMNS as $column => $expected) {
            self::assertArrayHasKey($column, $actual, $column);
            self::assertSame($expected, $actual[$column], $column);
        }
    }

    public function testTheEmbeddablesRoundTrip(): void
    {
        $account = new Account('a-1', 'alice@example.com', 'REDACTED-TEST-x');
        $account->uiPreferences()->setPreferredTheme(Theme::Dark)->setPreferredLocale(' ES ');
        $account->productTour()->markPageSeen('dashboard');
        $this->em()->persist($account);
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $this->em()->find(Account::class, 'a-1');
        self::assertInstanceOf(Account::class, $reloaded);
        self::assertSame('dark', $reloaded->uiPreferences()->getPreferredTheme());
        self::assertSame('es', $reloaded->uiPreferences()->getPreferredLocale());
        self::assertSame('comfortable', $reloaded->uiPreferences()->getPreferredUiDensityRaw());
        self::assertSame(['dashboard'], $reloaded->productTour()->getProductTourSeenPages());
    }

    public function testTheApplicationServiceDrivesTheRegistryThroughPortsOnly(): void
    {
        $this->account('a-1', 'alice@example.com');
        $this->account('b-1', 'bob@example.com');
        $laptop = $this->signIn('alice@example.com', 'laptop-1');
        $phone  = $this->signIn('alice@example.com', 'phone-1');
        $bob    = $this->signIn('bob@example.com', null);

        $overview = $this->json($laptop, 'GET', '/me');
        self::assertSame(2, $overview['count']);
        self::assertSame(1, $overview['current']);
        $devices = $overview['devices'];
        sort($devices);
        self::assertSame(['laptop-1', 'phone-1'], $devices);
        self::assertSame(['count' => 1, 'current' => 1, 'devices' => [null]], $this->json($bob, 'GET', '/me'));

        self::assertSame(['revoked' => 1], $this->json($laptop, 'POST', '/me/sign-out-others'));
        $phone->request('GET', '/me');
        self::assertTrue($phone->getResponse()->isRedirection());
        self::assertStringEndsWith('/login', (string) $phone->getResponse()->headers->get('Location'));
        self::assertSame(['count' => 1, 'current' => 1, 'devices' => ['laptop-1']], $this->json($laptop, 'GET', '/me'));
        self::assertSame(1, $this->json($bob, 'GET', '/me')['count']);
    }

    public function testAPasswordChangeOnTheXmlMappedAggregateSignsTheSessionsOut(): void
    {
        $this->account('a-1', 'alice@example.com');
        $laptop = $this->signIn('alice@example.com', 'laptop-1');
        self::assertSame(1, $this->json($laptop, 'GET', '/me')['count']);

        // Outside a request (a domain command handler): every session of the account goes.
        $alice = $this->em()->find(Account::class, 'a-1');
        self::assertInstanceOf(Account::class, $alice);
        $alice->changePassword('REDACTED-TEST-y');
        $this->em()->flush();

        $laptop->request('GET', '/me');
        self::assertTrue($laptop->getResponse()->isRedirection());
        self::assertStringEndsWith('/login', (string) $laptop->getResponse()->headers->get('Location'));
    }

    private function account(string $id, string $email): Account
    {
        $account = new Account($id, $email, 'REDACTED-TEST-x');
        $this->em()->persist($account);
        $this->em()->flush();

        return $account;
    }

    private function signIn(string $email, ?string $device): KernelBrowser
    {
        self::assertInstanceOf(DddHostTestKernel::class, $this->kernel);
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        if ($device !== null) {
            $browser->getCookieJar()->set(new Cookie('device', $device));
        }
        $browser->request('POST', '/login', ['_username' => $email, '_password' => 'REDACTED-TEST-x']);
        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertStringEndsWith('/me', (string) $browser->getResponse()->headers->get('Location'));

        return $browser;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser, string $method, string $uri): array
    {
        $browser->request($method, $uri);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $data = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function em(): EntityManagerInterface
    {
        self::assertInstanceOf(DddHostTestKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
