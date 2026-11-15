<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\UserKitBundle\DependencyInjection\OwnerImagesLoader;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\MemoryPointers;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Organizations;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Twig\Environment;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Owner images in a DDD / hexagonal host (1.18): the host keeps the pointers on its own aggregates
 * (`owner_images.pointer_store`) and decides access by id (`owner_images.access`); no bundle table is mapped. The
 * organization logo (light and dark) is the generalisation of the avatar: an `other` kind whose managers change it.
 */
final class DddHostOwnerImagesTest extends OwnerImagesKernelTestCase
{
    private function bootDdd(): void
    {
        $this->boot([
            'owner_images' => [
                'kinds' => [
                    'avatar'        => ['subject' => 'user', 'read' => 'authenticated', 'shape' => 'circle'],
                    'org_logo'      => ['subject' => 'other', 'read' => 'public'],
                    'org_logo_dark' => ['subject' => 'other', 'read' => 'host'],
                ],
                'access'        => Organizations::class,
                'pointer_store' => MemoryPointers::class,
            ],
        ], static function (ContainerConfigurator $container): void {
            $container->services()->set(MemoryPointers::class)->public();
            $container->services()->set(Organizations::class)->args([service(OwnerImageSubjects::class)])->public();
        });
    }

    private function images(): OwnerImagesInterface
    {
        return $this->service(OwnerImagesInterface::class);
    }

    public function testTheHostPortsAreUsedAndNoBundleTableIsMapped(): void
    {
        $this->bootDdd();
        self::assertInstanceOf(MemoryPointers::class, $this->service(OwnerImagePointerStoreInterface::class));
        self::assertTrue($this->em()->getMetadataFactory()->isTransient(OwnerImagePointer::class));
        self::assertTrue($this->container()->has(OwnerImagesLoader::CONTEXT_PREFIX . 'org_logo_dark'));

        $alice = $this->user('alice');
        self::assertTrue($this->images()->ready($alice, 'org_logo', 'org-1'));
        self::assertFalse($this->images()->ready($alice, 'org_logo', 'org-2'));
        self::assertFalse($this->images()->ready($alice, 'org_logo', 'org-bad'), 'a target StorageKit refuses');
        try {
            $this->images()->upload($alice, 'org_logo', 'org-bad', self::png(), 'x.png');
            self::fail('accepted');
        } catch (OwnerImageRefused $e) {
            self::assertSame(OwnerImageRefused::FORBIDDEN, $e->reason);
        }
    }

    public function testManagersChangeTheOrganizationLogosEveryoneSeesThem(): void
    {
        $this->bootDdd();
        [$alice, $bob] = [$this->user('alice'), $this->user('bob')];
        $light         = $this->images()->upload($alice, 'org_logo', 'org-1', self::png(), 'logo.png');
        $dark          = $this->images()->upload($alice, 'org_logo_dark', 'org-1', self::png(), 'logo-dark.png');
        self::assertSame(['org_logo' => ['org-1' => $light->fileId], 'org_logo_dark' => ['org-1' => $dark->fileId]], $this->service(MemoryPointers::class)->pointers);
        try {
            $this->images()->upload($bob, 'org_logo', 'org-1', self::png(), 'logo.png');
            self::fail('accepted');
        } catch (OwnerImageRefused $e) {
            self::assertSame(OwnerImageRefused::FORBIDDEN, $e->reason);
        }

        $browser = $this->browser();
        $browser->request('GET', '/storage/files/' . $light->fileId . '/preview');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'read: public');
        $browser->request('GET', '/storage/files/' . $dark->fileId . '/preview', server: ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'pw']);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'read: host, signed in');
        $browser->request('GET', '/storage/files/' . $dark->fileId . '/preview');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'the host access lets anyone read logos');

        $html = $this->service(Environment::class)->createTemplate("{{ owner_image('org_logo', 'org-1', 'lg', 'Acme', class: 'org-logo') }}{{ owner_image('org_logo', 'org-2', 'xs', 'Beta', 'BE') }}")->render();
        self::assertStringContainsString('class="nowo-user-kit-owner-image nowo-user-kit-owner-image--lg org-logo" src="/storage/files/' . $light->fileId . '/preview" alt="Acme"', $html);
        self::assertStringContainsString('role="img" aria-label="Beta" data-owner-image="org_logo">BE</span>', $html);
    }

    public function testAFailedPointerWriteRemovesTheNewFileAndForgetDefersTheBucket(): void
    {
        $this->bootDdd();
        $alice    = $this->user('alice');
        $pointers = $this->service(MemoryPointers::class);
        $view     = $this->images()->upload($alice, 'org_logo', 'org-1', self::png(), 'logo.png');
        self::assertCount(3, $this->buckets()->keys());

        $pointers->failWrites = true;
        try {
            $this->images()->upload($alice, 'org_logo', 'org-1', self::png(), 'logo2.png');
            self::fail('accepted');
        } catch (OwnerImageRefused $e) {
            self::assertSame(OwnerImageRefused::STORAGE, $e->reason);
        }
        self::assertCount(3, $this->buckets()->keys(), 'the new file was removed, the current one kept');
        self::assertSame($view->fileId, $pointers->pointers['org_logo']['org-1']);
        $pointers->failWrites = false;

        // The organization is deleted: the host forgets its logo; the purge removes it later.
        self::assertTrue($this->images()->forget('org_logo', 'org-1'));
        self::assertFalse($this->images()->forget('org_logo', 'org-1'));
        self::assertFalse($this->images()->forget('nope', 'org-1'));
        self::assertCount(3, $this->buckets()->keys());
        $this->service(FilePurger::class)->run(new DateTimeImmutable());
        self::assertSame([], $this->buckets()->keys());
    }

    public function testThePurgeOrphansTheImagesOfSubjectsThatLostTheirPointer(): void
    {
        $this->bootDdd();
        $view = $this->images()->upload($this->user('alice'), 'org_logo', 'org-1', self::png(), 'logo.png');
        // The host deleted the organization (and its pointer) without telling the bundle.
        $this->service(MemoryPointers::class)->pointers = [];

        $purger = $this->service(FilePurger::class);
        $purger->run(new DateTimeImmutable());
        self::assertCount(3, $this->buckets()->keys(), 'orphaned now, kept for the retention');
        $purger->run(new DateTimeImmutable('+30 days'));
        self::assertSame([], $this->buckets()->keys(), $view->fileId . ' purged');
    }
}
