<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testHomePageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Account status');
    }

    public function testSessionPagesNeedASignedInUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/sessions');

        self::assertResponseRedirects('/login');
    }

    public function testMySessionsListsThisSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'demo@user-kit.test']);
        self::assertInstanceOf(User::class, $user);

        $client->loginUser($user);
        $client->request('GET', '/account/sessions');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-user-kit-session-current"]');
    }

    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demo login');
    }
}
