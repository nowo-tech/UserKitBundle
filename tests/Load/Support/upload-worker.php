<?php

declare(strict_types=1);

/*
 * One avatar upload of alice, in its own PHP process (OwnerImageLoadTest): boots the owner images load kernel, answers
 * READY, waits for GO on STDIN, uploads and answers the new file id (or "ERR <class>").
 *
 * Usage: php upload-worker.php <variant>   (USER_KIT_TEST_DB_URL, USER_KIT_LOAD_SLOW_FIND_MS, USER_KIT_LOAD_SLOW_SET_MS)
 */

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Nowo\UserKitBundle\Tests\Load\Support\OwnerImageKernels;
use Symfony\Component\DependencyInjection\ContainerInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = OwnerImageKernels::kernel($argv[1] ?? 'worker');
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$images = $container->get(OwnerImagesInterface::class);
$em     = $container->get(EntityManagerInterface::class);
assert($images instanceof OwnerImagesInterface && $em instanceof EntityManagerInterface);
$alice = $em->find(User::class, 'alice') ?? throw new RuntimeException('no alice');
$png   = OwnerImageKernels::png();
// A warm-up upload of bob (image processing, lazy services) so both workers reach alice's pointer at the same moment.
$images->upload($em->find(User::class, 'bob') ?? throw new RuntimeException('no bob'), 'avatar', 'bob', $png, 'warm.png');

fwrite(\STDOUT, "READY\n");
if (rtrim((string) fgets(\STDIN)) !== 'GO') {
    throw new RuntimeException('The test did not start the race.');
}

try {
    fwrite(\STDOUT, $images->upload($alice, 'avatar', 'alice', $png, 'me.png')->fileId . "\n");
} catch (Throwable $failure) {
    fwrite(\STDOUT, 'ERR ' . $failure::class . "\n");
}
$kernel->shutdown();
