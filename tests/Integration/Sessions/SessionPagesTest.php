<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "My sessions" and the administration page: list, sign out one, sign out the others, sign out everywhere; access
 * (anonymous, role, custom roles), CSRF fail-closed, unknown ids.
 */
final class SessionPagesTest extends SessionsKernelTestCase
{
    public function testMySessionsListsTheSessionsAndSignsOneOut(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com', 'Mozilla/5.0 (Linux; Android 15) Chrome/130.0 Mobile Safari/537.36');
        $phone->request('GET', '/page');

        $crawler = $laptop->request('GET', '/account/sessions');
        self::assertSame(200, $laptop->getResponse()->getStatusCode(), $this->body($laptop));
        self::assertStringContainsString('no-store', (string) $laptop->getResponse()->headers->get('Cache-Control'));
        self::assertCount(2, $crawler->filter('[data-testid="nowo-user-kit-session-client"]'));
        self::assertSame('This session', trim($crawler->filter('[data-testid="nowo-user-kit-session-current"]')->text()));
        self::assertStringContainsString('Chrome on Android', $this->body($laptop));

        $phoneRow = $this->other($alice, $laptop);
        $form     = $crawler->filter('form[action="/account/sessions/' . $phoneRow . '/revoke"]')->form();
        $laptop->submit($form);
        self::assertRedirectsTo($laptop, '/account/sessions');
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        self::assertCount(1, $this->rowsOf($alice));

        $crawler = $laptop->request('GET', '/account/sessions');
        self::assertStringContainsString('The session was signed out.', $this->body($laptop));
        self::assertCount(0, $crawler->filter('form[action="/account/sessions/revoke-others"]'));
    }

    public function testSignOutAllOtherSessions(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');

        $crawler = $laptop->request('GET', '/account/sessions');
        $laptop->submit($crawler->filter('form[action="/account/sessions/revoke-others"]')->form());
        self::assertRedirectsTo($laptop, '/account/sessions');
        $laptop->request('GET', '/account/sessions');
        self::assertStringContainsString('Signed out 2 other session(s).', $this->body($laptop));
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testMutationsFailClosedWithoutAValidToken(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');

        $laptop->request('POST', '/account/sessions/revoke-others', ['csrf_only' => ['_token' => 'forged']]);
        self::assertSame(403, $laptop->getResponse()->getStatusCode());
        $laptop->request('POST', '/account/sessions/revoke-others');
        self::assertSame(403, $laptop->getResponse()->getStatusCode());
        self::assertCount(2, $this->rowsOf($alice));
    }

    public function testTokensAreBoundToTheirRowAndStaleRowsAreNotFound(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $laptop = $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');
        $bob = $this->signIn('bob@example.com');
        $bob->request('GET', '/page');
        $bobRow = $this->rowsOf($this->find('bob@example.com'))[0]->getPublicId();

        // A token of Alice's row does not work for another row (Bob's): 403, nothing revoked.
        $crawler = $laptop->request('GET', '/account/sessions');
        $form    = $crawler->filter('form[action$="/revoke"]')->first();
        $token   = (string) $form->filter('input[name="csrf_only[_token]"]')->attr('value');
        $laptop->request('POST', '/account/sessions/' . $bobRow . '/revoke', ['csrf_only' => ['_token' => $token]]);
        self::assertSame(403, $laptop->getResponse()->getStatusCode());
        self::assertCount(1, $this->rowsOf($this->find('bob@example.com')));

        // A stale form (the row was revoked meanwhile): 404.
        $this->manager()->revokeOthers($alice, $this->manager()->currentHash($laptop->getRequest()->getSession()));
        $laptop->request('POST', (string) $form->attr('action'), ['csrf_only' => ['_token' => $token]]);
        self::assertSame(404, $laptop->getResponse()->getStatusCode());
    }

    public function testAnonymousAndUnauthorisedUsersAreDenied(): void
    {
        $this->boot(['security' => ['access_roles' => ['ROLE_SESSIONS']]]);
        $this->member('alice@example.com');
        $anonymous = $this->browser();
        $anonymous->request('GET', '/account/sessions');
        self::assertRedirectsTo($anonymous, '/login');

        $alice = $this->signIn('alice@example.com');
        $alice->request('GET', '/account/sessions');
        self::assertSame(403, $alice->getResponse()->getStatusCode());
        // A user the actor may not manage is a 404 (no probing of other users' ids).
        $alice->request('GET', '/admin/user-sessions/default/1');
        self::assertSame(404, $alice->getResponse()->getStatusCode());
    }

    public function testAdministratorListsAndSignsOutAUser(): void
    {
        $this->boot();
        $this->member('root@example.com', ['ROLE_ADMIN']);
        $alice = $this->member('alice@example.com');
        $admin = $this->signIn('root@example.com');
        $one   = $this->signIn('alice@example.com');
        $two   = $this->signIn('alice@example.com');
        $one->request('GET', '/page');
        $two->request('GET', '/page');

        $url     = '/admin/user-sessions/default/' . $alice->getId();
        $crawler = $admin->request('GET', $url);
        self::assertSame(200, $admin->getResponse()->getStatusCode(), $this->body($admin));
        self::assertStringContainsString('Sessions of alice@example.com', $this->body($admin));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-user-kit-session-current"]'));

        $rows = $this->rowsOf($alice);
        $admin->submit($crawler->filter('form[action="' . $url . '/' . $rows[0]->getPublicId() . '/revoke"]')->form());
        self::assertRedirectsTo($admin, $url);
        self::assertCount(1, $this->rowsOf($alice));

        $crawler = $admin->request('GET', $url);
        $admin->submit($crawler->filter('form[action="' . $url . '/revoke-all"]')->form());
        self::assertRedirectsTo($admin, $url);
        $crawler = $admin->request('GET', $url);
        self::assertStringContainsString('Signed out 1 session(s).', $this->body($admin));
        self::assertStringContainsString('alice@example.com has no active sessions.', $this->body($admin));
        self::assertCount(0, $crawler->filter('form[action="' . $url . '/revoke-all"]'));

        $one->request('GET', '/page');
        self::assertRedirectsTo($one, '/login');
        $two->request('GET', '/page');
        self::assertRedirectsTo($two, '/login');
        $two->followRedirect();
        $two->request('GET', '/login');
    }

    public function testAdministrationGuards(): void
    {
        $this->boot();
        $this->member('root@example.com', ['ROLE_ADMIN']);
        $alice = $this->member('alice@example.com');
        $admin = $this->signIn('root@example.com');
        $this->signIn('alice@example.com');

        $admin->request('GET', '/admin/user-sessions/nope/' . $alice->getId());
        self::assertSame(404, $admin->getResponse()->getStatusCode());
        $admin->request('GET', '/admin/user-sessions/default/999');
        self::assertSame(404, $admin->getResponse()->getStatusCode());
        $admin->request('POST', '/admin/user-sessions/default/' . $alice->getId() . '/revoke-all');
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        $crawler = $admin->request('GET', '/admin/user-sessions/default/' . $alice->getId());
        $form    = $crawler->filter('form[action$="/revoke"]')->first();
        $this->manager()->revokeAll($alice);
        $admin->request('POST', (string) $form->attr('action'), ['csrf_only' => ['_token' => (string) $form->filter('input[name="csrf_only[_token]"]')->attr('value')]]);
        self::assertSame(404, $admin->getResponse()->getStatusCode());
    }

    public function testCustomAccessCheckerAndAdminPageSwitchedOff(): void
    {
        $this->boot(['web_ui' => ['admin' => false]]);
        $this->member('root@example.com', ['ROLE_ADMIN']);
        $admin = $this->signIn('root@example.com');
        $admin->request('GET', '/admin/user-sessions/default/1');
        self::assertSame(404, $admin->getResponse()->getStatusCode());
    }

    private function find(string $email): Member
    {
        $member = $this->em()->getRepository(Member::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Member::class, $member);

        return $member;
    }

    /**
     * Public id of the member's session that is not the browser's own.
     */
    private function other(Member $member, KernelBrowser $own): string
    {
        $hash = $this->manager()->currentHash($own->getRequest()->getSession());
        foreach ($this->rowsOf($member) as $row) {
            if ($row->getSessionHash() !== $hash) {
                return $row->getPublicId();
            }
        }
        self::fail('No other session.');
    }
}
