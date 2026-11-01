<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UserKitBundle\Tests\Support\SessionPageFixtures;

use function dirname;

/**
 * REQ-UI-003 page contract: the session pages extend the UiKit list scaffold; no <html>, CDN, inline handler or
 * confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or missing translation
 * (strict rule); the CSRF-only action forms keep their token field.
 */
final class SessionsPageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        yield 'my sessions' => new PageFixture('@NowoUserKitBundle/sessions/index.html.twig', SessionPageFixtures::own());
        yield 'my sessions (empty)' => new PageFixture('@NowoUserKitBundle/sessions/index.html.twig', SessionPageFixtures::own(false));
        yield 'user sessions (admin)' => new PageFixture('@NowoUserKitBundle/sessions/admin.html.twig', SessionPageFixtures::admin());
        yield 'user sessions (admin, empty)' => new PageFixture('@NowoUserKitBundle/sessions/admin.html.twig', SessionPageFixtures::admin(false));
    }

    public function testTheCurrentSessionIsMarkedAndKeepsNoSignOutAction(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoUserKitBundle/sessions/index.html.twig', SessionPageFixtures::own()))->html;

        self::assertSame(1, substr_count($html, 'data-testid="nowo-user-kit-session-current"'));
        self::assertSame(2, substr_count($html, 'data-testid="nowo-user-kit-sessions-revoke"'));
        self::assertStringContainsString('203.0.113.0/24', $html);
        self::assertStringNotContainsString('h:0123456789abcdef', $html);
        self::assertStringContainsString('Unknown browser', $html);
    }

    protected static function templatePaths(): array
    {
        return ['NowoUserKitBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
