<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Ui;

use Closure;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function is_string;

/**
 * UiKit page contract (REQ-UI-003) of the session pages: templates (`web_ui.templates.<page>`), layout
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`) and the {@see ListPage} view models of "My sessions"
 * and of the administration page. The POST actions carry FormKit CSRF-only forms (REQ-TWIG-005 rule 11, field
 * `csrf_only[_token]`) and the UiKit confirm dialog. Plain data in, immutable view models out (worker safe).
 */
final readonly class SessionsPages
{
    /** Bundle name of the view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoUserKitBundle`). */
    public const BUNDLE = NowoUserKitBundle::TRANSLATION_DOMAIN;

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'index' => '@NowoUserKitBundle/sessions/index.html.twig',
        'admin' => '@NowoUserKitBundle/sessions/admin.html.twig',
    ];

    public const ROUTE_INDEX = 'nowo_user_kit_sessions';

    public const ROUTE_REVOKE = 'nowo_user_kit_sessions_revoke';

    public const ROUTE_REVOKE_OTHERS = 'nowo_user_kit_sessions_revoke_others';

    public const ROUTE_ADMIN = 'nowo_user_kit_sessions_admin';

    public const ROUTE_ADMIN_REVOKE = 'nowo_user_kit_sessions_admin_revoke';

    public const ROUTE_ADMIN_REVOKE_ALL = 'nowo_user_kit_sessions_admin_revoke_all';

    /** CSRF token id prefixes (see the *TokenId() helpers: bound to the row and, on the administration page, to the user). */
    public const CSRF_REVOKE = 'nowo_user_kit_sessions_revoke';

    public const CSRF_REVOKE_OTHERS = 'nowo_user_kit_sessions_revoke_others';

    public const CSRF_ADMIN_REVOKE = 'nowo_user_kit_sessions_admin_revoke';

    public const CSRF_ADMIN_REVOKE_ALL = 'nowo_user_kit_sessions_admin_revoke_all';

    /**
     * @param string|null $layoutTemplate `web_ui.layout_template`
     * @param array<string, string> $templates `web_ui.templates`
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private CsrfOnlyFormFactory $csrfForms,
        private ?string $layoutTemplate = null,
        private array $templates = [],
    ) {
    }

    /**
     * CSRF token id of "Sign out" of one of the viewer's sessions.
     */
    public static function revokeTokenId(string $publicId): string
    {
        return self::CSRF_REVOKE . '_' . $publicId;
    }

    /**
     * CSRF token id of the administration "Sign out" of one session (bound to the user and the row; `/` separates the
     * parts, which neither route requirement allows, so the id is unambiguous).
     */
    public static function adminRevokeTokenId(string $profile, string $userId, string $publicId): string
    {
        return self::CSRF_ADMIN_REVOKE . '/' . $profile . '/' . $userId . '/' . $publicId;
    }

    /**
     * CSRF token id of "Sign out everywhere" (bound to the user: a token for one user never signs out another).
     */
    public static function adminRevokeAllTokenId(string $profile, string $userId): string
    {
        return self::CSRF_ADMIN_REVOKE_ALL . '/' . $profile . '/' . $userId;
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, int|string> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, NowoUserKitBundle::TRANSLATION_DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : self::TEMPLATES[$page] ?? $page;
    }

    /**
     * Layout the scaffolds extend (null = `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        return $this->layoutTemplate === '' ? null : $this->layoutTemplate;
    }

    /**
     * Columns of both lists (cells filled by the templates in this order).
     *
     * @return list<Column>
     */
    public static function columns(): array
    {
        return [
            new Column('device', self::label('sessions.column.device'), required: true),
            new Column('network', self::label('sessions.column.network'), priority: 2),
            new Column('createdAt', self::label('sessions.column.created_at'), priority: 3),
            new Column('lastSeenAt', self::label('sessions.column.last_seen_at'), required: true),
        ];
    }

    /**
     * "My sessions": the user's live sessions, "Sign out" on every row but the current one, "Sign out all other
     * sessions" in the header when there are others.
     *
     * @param list<UserSession> $sessions
     */
    public function ownPage(array $sessions, ?string $currentHash): ListPage
    {
        $rows   = array_map(static fn (UserSession $s): SessionRow => SessionRow::of($s, $currentHash), $sessions);
        $others = false;
        foreach ($rows as $row) {
            $others = $others || !$row->current;
        }
        $actions = $others ? [$this->post(
            self::ROUTE_REVOKE_OTHERS,
            [],
            self::CSRF_REVOKE_OTHERS,
            self::label('sessions.action.revoke_others'),
            new Confirm(self::label('sessions.confirm.revoke_others_title'), self::label('sessions.confirm.revoke_others'), self::label('sessions.action.revoke_others')),
            'nowo-user-kit-sessions-revoke-others',
        )] : [];

        $rowActions = [];
        foreach ($rows as $row) {
            $rowActions[$row->publicId] = $row->current ? [] : [$this->post(
                self::ROUTE_REVOKE,
                ['id' => $row->publicId],
                self::revokeTokenId($row->publicId),
                self::label('sessions.action.revoke'),
                new Confirm(self::label('sessions.confirm.revoke_title'), self::label('sessions.confirm.revoke'), self::label('sessions.action.revoke')),
                'nowo-user-kit-sessions-revoke',
            )];
        }

        return new ListPage(
            new PageHeader(self::label('sessions.index.title'), self::label('sessions.index.intro'), $actions),
            self::columns(),
            $rows,
            rowActions: $this->perRow($rowActions),
            empty: new EmptyState(self::label('sessions.index.empty')),
            caption: self::label('sessions.index.caption'),
            bundle: self::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'nowo-user-kit-sessions'],
            actionsLabel: self::label('sessions.column.actions'),
        );
    }

    /**
     * Administration page of one user's sessions: "Sign out" per row and "Sign out everywhere".
     *
     * @param list<UserSession> $sessions
     * @param string $subject display name of the user (security user identifier)
     */
    public function adminPage(array $sessions, string $profile, string $userId, string $subject): ListPage
    {
        $rows    = array_map(static fn (UserSession $s): SessionRow => SessionRow::of($s), $sessions);
        $params  = ['profile' => $profile, 'user' => $userId];
        $actions = $rows !== [] ? [$this->post(
            self::ROUTE_ADMIN_REVOKE_ALL,
            $params,
            self::adminRevokeAllTokenId($profile, $userId),
            self::label('sessions.action.revoke_all'),
            new Confirm(self::label('sessions.confirm.revoke_all_title'), self::label('sessions.confirm.revoke_all', ['%user%' => $subject]), self::label('sessions.action.revoke_all')),
            'nowo-user-kit-sessions-revoke-all',
        )] : [];
        $title      = self::label('sessions.admin.title', ['%user%' => $subject]);
        $rowActions = [];
        foreach ($rows as $row) {
            $rowActions[$row->publicId] = [$this->post(
                self::ROUTE_ADMIN_REVOKE,
                $params + ['id' => $row->publicId],
                self::adminRevokeTokenId($profile, $userId, $row->publicId),
                self::label('sessions.action.revoke'),
                new Confirm(self::label('sessions.confirm.revoke_title'), self::label('sessions.confirm.admin_revoke', ['%user%' => $subject]), self::label('sessions.action.revoke')),
                'nowo-user-kit-sessions-admin-revoke',
            )];
        }

        return new ListPage(
            new PageHeader($title, self::label('sessions.admin.intro', ['%user%' => $subject]), $actions, [new Crumb($title)]),
            self::columns(),
            $rows,
            rowActions: $this->perRow($rowActions),
            empty: new EmptyState(self::label('sessions.admin.empty', ['%user%' => $subject])),
            caption: self::label('sessions.admin.caption', ['%user%' => $subject]),
            bundle: self::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'nowo-user-kit-sessions-admin'],
            actionsLabel: self::label('sessions.column.actions'),
        );
    }

    /**
     * Row actions built once per page (the forms keep one token each, however often the scaffold asks).
     *
     * @param array<string, list<Action>> $actions public id => actions
     *
     * @return Closure(SessionRow): list<Action>
     */
    private function perRow(array $actions): Closure
    {
        return static fn (SessionRow $row): array => $actions[$row->publicId] ?? [];
    }

    /**
     * POST action with a FormKit CSRF-only form behind the UiKit confirm dialog.
     *
     * @param array<string, string> $params
     */
    private function post(string $route, array $params, string $tokenId, Label $label, Confirm $confirm, string $testId): Action
    {
        $url = $this->urls->generate($route, $params);

        return new Action(
            'delete',
            $label,
            $url,
            method: 'POST',
            confirm: $confirm,
            attributes: ['data-testid' => $testId],
            form: $this->csrfForms->createNamed($url, $tokenId)->createView(),
        );
    }
}
