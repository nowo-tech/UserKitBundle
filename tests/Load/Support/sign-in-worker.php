<?php

declare(strict_types=1);

/*
 * One browser signing in, in its own PHP process (SessionLimitRaceTest): boots the load kernel with the given policy,
 * answers READY, waits for GO on STDIN, posts the login form and answers "<status> <location>".
 *
 * Usage: php sign-in-worker.php <kernel id> <policy>   (USER_KIT_LOAD_DATABASE_URL, USER_KIT_LOAD_SLOW_DEVICE_MS)
 */

use Nowo\UserKitBundle\Tests\Load\Support\LoadKernels;
use Nowo\UserKitBundle\Tests\Load\Support\SessionLimitConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$id, $policy] = [$argv[1] ?? '', $argv[2] ?? ''];
$kernel        = LoadKernels::kernel($id, SessionLimitConfig::userKit($policy));
$kernel->boot();
$browser = new KernelBrowser($kernel);
$browser->disableReboot();
$browser->catchExceptions(true);
// A warm-up request: the firewall, the session and Doctrine are ready before the race starts.
$browser->request('GET', '/login');

fwrite(\STDOUT, "READY\n");
if (rtrim((string) fgets(\STDIN)) !== 'GO') {
    throw new RuntimeException('The test did not start the race.');
}

$browser->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret']);
$response = $browser->getResponse();
$location = (string) $response->headers->get('Location', '-');
fwrite(\STDOUT, $response->getStatusCode() . ' ' . (parse_url($location, \PHP_URL_PATH) ?: $location) . "\n");
$kernel->shutdown();
