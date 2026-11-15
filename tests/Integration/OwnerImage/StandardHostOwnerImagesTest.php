<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Nowo\UserKitBundle\OwnerImage\Form\OwnerImageType;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\ChangeLog;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\HostBuckets;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

use function count;
use function gc_collect_cycles;
use function memory_get_usage;
use function method_exists;
use function str_repeat;

/**
 * Owner images in a standard Symfony host (1.18): attribute-mapped Doctrine users, the bundle's pointer table, the
 * default access (each user changes their own avatar), StorageKit 1.6 for the files. Upload, replace, remove, the
 * image checks (polyglots, bombs, SVG), downloads with the read check, the square-only context, Twig, the form field,
 * GDPR export / erasure, and a kept-alive kernel (FrankenPHP worker mode, no services resetter).
 */
final class StandardHostOwnerImagesTest extends OwnerImagesKernelTestCase
{
    private function images(): OwnerImagesInterface
    {
        return $this->service(OwnerImagesInterface::class);
    }

    private function exporter(): PersonalDataExporter
    {
        $exporter = $this->container()->get('test.personal_data.exporter');
        self::assertInstanceOf(PersonalDataExporter::class, $exporter);

        return $exporter;
    }

    private function eraser(): PersonalDataEraser
    {
        $eraser = $this->container()->get('test.personal_data.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        return $eraser;
    }

    private function bootWithLog(): void
    {
        $this->boot(extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(ChangeLog::class)->public()->tag('kernel.event_listener', ['event' => OwnerImageChangedEvent::class]);
        });
    }

    private function rows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . DbalOwnerImagePointerStore::TABLE);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function twig(string $template, array $context = []): string
    {
        return $this->service(Environment::class)->createTemplate($template)->render($context);
    }

    public function testAUserUploadsReplacesAndRemovesTheirOwnAvatar(): void
    {
        $this->bootWithLog();
        $alice = $this->user('alice');

        $first = $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'me.png');
        self::assertSame(['avatar', 'alice'], [$first->kind, $first->subjectId]);
        self::assertSame(1, $this->rows());
        self::assertCount(3, $this->buckets()->keys(), 'original, preview and thumb');
        self::assertSame(['alice' => $first->fileId], $this->images()->fileIds('avatar', ['alice', 'bob']));

        $html = $this->twig("{{ user_avatar(user, 'sm') }}|{{ owner_image_url('avatar', 'alice') }}", ['user' => $alice]);
        self::assertStringContainsString('src="/storage/files/' . $first->fileId . '/thumb"', $html);
        self::assertStringContainsString('|/storage/files/' . $first->fileId . '/preview', $html);
        self::assertStringContainsString('width="30" height="30"', $html);

        $second = $this->images()->upload($alice, 'avatar', 'alice', self::png(50, 400), 'me2.png');
        self::assertNotSame($first->fileId, $second->fileId);
        self::assertSame(1, $this->rows());
        self::assertCount(3, $this->buckets()->keys(), 'the replaced picture is gone at once');

        self::assertTrue($this->images()->remove($alice, 'avatar', 'alice'));
        self::assertFalse($this->images()->remove($alice, 'avatar', 'alice'));
        self::assertSame(0, $this->rows());
        self::assertSame([], $this->buckets()->keys());

        $events = $this->service(ChangeLog::class)->events;
        self::assertCount(3, $events);
        self::assertSame([null, $first->fileId, $second->fileId], [$events[0]->previousFileId, $events[1]->previousFileId, $events[2]->previousFileId]);
        self::assertSame([$first->fileId, $second->fileId, null], [$events[0]->fileId, $events[1]->fileId, $events[2]->fileId]);
        self::assertSame('alice', $events[0]->actorId);
    }

    public function testOnlyRealStillImagesWithinTheLimitsAreAccepted(): void
    {
        $this->boot();
        $alice = $this->user('alice');
        $cases = [
            [self::pngHeader(50_000, 50_000), OwnerImageRefused::PIXELS],
            [str_repeat('a', 2 * 1024 * 1024 + 1), OwnerImageRefused::SIZE],
            ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', OwnerImageRefused::TYPE],
            ['GIF89a' . str_repeat("\0", 40), OwnerImageRefused::TYPE],
            ['', OwnerImageRefused::EMPTY],
        ];
        foreach ($cases as [$bytes, $reason]) {
            try {
                $this->images()->upload($alice, 'avatar', 'alice', $bytes, 'x.png');
                self::fail('accepted: ' . $reason);
            } catch (OwnerImageRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertSame([], $this->buckets()->keys());

        // A polyglot is accepted as an image and stored without its payload.
        $view    = $this->images()->upload($alice, 'avatar', 'alice', self::png(40, 40) . '<?php echo 1; ?><script>alert(1)</script>', 'x.png');
        $browser = $this->browser();
        $browser->request('GET', '/storage/files/' . $view->fileId . '/original', server: ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'pw']);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('image/webp', $browser->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $browser->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('image/webp', OwnerImagesKernelTestCase::sniff($browser->getInternalResponse()->getContent()));
    }

    public function testOnlyTheUserChangesTheirAvatarAndSignedInUsersSeeIt(): void
    {
        $this->boot();
        [$alice, $bob] = [$this->user('alice'), $this->user('bob')];
        foreach ([
            [$bob, 'avatar', 'alice', OwnerImageRefused::FORBIDDEN],
            [$alice, 'logo', 'alice', OwnerImageRefused::KIND],
            [$alice, 'avatar', '../alice', OwnerImageRefused::KIND],
        ] as [$actor, $kind, $subject, $reason]) {
            try {
                $this->images()->upload($actor, $kind, $subject, self::png(), 'x.png');
                self::fail('accepted');
            } catch (OwnerImageRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        $view = $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'x.png');
        try {
            $this->images()->remove($bob, 'avatar', 'alice');
            self::fail('removed');
        } catch (OwnerImageRefused $e) {
            self::assertSame(OwnerImageRefused::FORBIDDEN, $e->reason);
        }

        // StorageKit's generic upload refuses the square-only context.
        try {
            $this->service(ImageUploaderInterface::class)->uploadImage($alice, 'avatar', 'alice', self::png(), 'x.png');
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::CONTEXT, $e->reason);
        }

        $browser = $this->browser();
        $browser->request('GET', '/storage/files/' . $view->fileId . '/thumb', server: ['PHP_AUTH_USER' => 'bob', 'PHP_AUTH_PW' => 'pw']);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $browser->request('GET', '/storage/files/' . $view->fileId . '/thumb');
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'read: authenticated');
    }

    public function testReadinessFollowsTheBucketAndTheActor(): void
    {
        $this->boot();
        $alice = $this->user('alice');
        self::assertTrue($this->images()->ready($alice, 'avatar', 'alice'));
        self::assertFalse($this->images()->ready($alice, 'avatar', 'bob'));
        self::assertSame(2 * 1024 * 1024, $this->images()->maxBytes());

        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($alice, 'main', ['ROLE_USER']));
        self::assertSame('1|', $this->twig("{{ owner_image_ready('avatar', 'alice') }}|{{ owner_image_ready('avatar', 'bob') }}"));
        $this->service(TokenStorageInterface::class)->setToken(null);
        self::assertSame('', $this->twig("{{ owner_image_ready('avatar', 'alice') }}"));

        $this->service(HostBuckets::class)->available = false;
        self::assertFalse($this->images()->ready($alice, 'avatar', 'alice'));
        try {
            $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'x.png');
            self::fail('accepted');
        } catch (OwnerImageRefused $e) {
            self::assertSame(OwnerImageRefused::OFF, $e->reason);
        }
    }

    public function testTheFormFieldShowsTheStoredImageAndCropsInTheBrowser(): void
    {
        $this->boot();
        $view = $this->images()->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'x.png');
        $form = $this->service(FormFactoryInterface::class)->createBuilder()
            ->add('picture', OwnerImageType::class, ['kind' => 'avatar', 'subject_id' => 'alice'])
            ->add('empty', OwnerImageType::class, ['kind' => 'avatar'])
            ->getForm();
        $html = $this->twig('{{ form_widget(form.picture) }}{{ form_widget(form.empty) }}', ['form' => $form->createView()]);

        self::assertStringContainsString('/storage/files/' . $view->fileId . '/preview', $html);
        self::assertStringContainsString('data-nowo-ui-image-crop', $html);
        self::assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $html);
        self::assertStringContainsString('circle', $html);
    }

    public function testPersonalDataExportAndErasure(): void
    {
        $this->boot();
        $alice = $this->user('alice');
        $view  = $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'x.png');

        $document = $this->exporter()->export($alice);
        self::assertSame(['avatar' => ['file' => $view->fileId, 'url' => '/storage/files/' . $view->fileId . '/original']], $document['sections']['owner_images'] ?? null);

        $this->eraser()->erase($alice);
        self::assertSame([], $this->images()->fileIds('avatar', ['alice']));
        self::assertCount(3, $this->buckets()->keys(), 'no bucket call inside the erasure transaction');
        $this->service(FilePurger::class)->run(new DateTimeImmutable());
        self::assertSame([], $this->buckets()->keys(), 'the next purge deletes it');
        self::assertFalse($this->images()->forget('avatar', 'alice'));
    }

    public function testAKeptAliveKernelNeverShowsAnImageMemoisedForAnEarlierRequest(): void
    {
        $this->boot();
        $alice = $this->user('alice');
        $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'x.png');
        $template = "{{ owner_image_preload('avatar', ['alice', 'bob']) }}{{ owner_image('avatar', 'alice') }}|{{ owner_image('avatar', 'bob', initials: 'B') }}";
        self::assertStringContainsString('<img', $this->twig($template));

        // Another worker (or SQL) removes the pointer: the memo still answers within the request…
        $this->em()->getConnection()->executeStatement('DELETE FROM ' . DbalOwnerImagePointerStore::TABLE);
        self::assertStringContainsString('<img', $this->twig($template));

        // …and forgets it at the next request boundary, without the services resetter.
        $kernel = $this->service(HttpKernelInterface::class);
        $before = 0;
        for ($i = 0; $i < 300; ++$i) {
            $request  = Request::create('/storage/files/00000000-0000-4000-8000-000000000000/thumb');
            $response = $kernel->handle($request);
            self::assertSame(404, $response->getStatusCode());
            if (method_exists($kernel, 'terminate')) {
                $kernel->terminate($request, $response);
            }
            $html = $this->twig($template);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringContainsString('>B</span>', $html);
            if ($i === 49) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 250 requests.');
        self::assertGreaterThan(0, count($this->buckets()->keys()));
    }
}
