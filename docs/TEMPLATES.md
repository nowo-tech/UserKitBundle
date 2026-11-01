# Templates

Pages of UserKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.20+). Only the opt-in session registry has
pages (`sessions.web_ui.enabled: true`): "My sessions" and the administration page of one user's sessions.
`Nowo\UserKitBundle\Sessions\Ui\SessionsPages` builds the UiKit view models (`Nowo\UiKitBundle\View\*`), resolves the
layout (`sessions.web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`) and the per-page overrides
(`sessions.web_ui.templates.<page>`). Contract test: `tests/Integration/Contract/SessionsPageContractTest.php` (UiKit
`PageContractTestCase`, strict missing-translation rule). Twig namespace: `@NowoUserKitBundle` (views in
`src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Actions and CSRF](#actions-and-csrf)
- [Personal data in the pages](#personal-data-in-the-pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Bundle partials](#bundle-partials)
- [Translations](#translations)

## Pages

Every view model passes `bundle: 'NowoUserKitBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoUserKitBundle`)
and `layout: SessionsPages::layout()`. Labels are `NowoUserKitBundle` translation keys (`SessionsPages::label()`).

| Page (`sessions.web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| My sessions (`index`) | `sessions/index.html.twig` | `page/list` | `ListPage` from `SessionsPages::ownPage()`: header `sessions.index.title` + `sessions.index.intro`, header action "Sign out all other sessions" (only when other sessions exist); columns device, network (priority 2), signed in (priority 3), last active; row action "Sign out" on every row but the current one; `EmptyState` `sessions.index.empty`; caption `sessions.index.caption`; root `data-testid="nowo-user-kit-sessions"` | `nowo_ui_list_row` → `sessions/_cells.html.twig` |
| User sessions (`admin`) | `sessions/admin.html.twig` | `page/list` | `ListPage` from `SessionsPages::adminPage()`: header `sessions.admin.title` / `intro` (`%user%`), breadcrumb, header action "Sign out everywhere" (when the user has sessions); same columns; row action "Sign out" on every row; `EmptyState` `sessions.admin.empty`; root `data-testid="nowo-user-kit-sessions-admin"` | `nowo_ui_list_row` → `sessions/_cells.html.twig` |

Rows are `Nowo\UserKitBundle\Sessions\Ui\SessionRow` view models (`publicId`, `browser`, `os`, `ip` — displayable
network or null —, `deviceId`, `createdAt`, `lastSeenAt`, `current`): the session hash never reaches a template, so a
`dump()` in a host override cannot print it. Other variable passed by the controller: `subject` (the user whose
sessions are listed, administration).

## Actions and CSRF

| Page | Action (`data-testid`) | Route | CSRF token id (form `csrf_only`) |
| --- | --- | --- | --- |
| My sessions | "Sign out all other sessions" (`nowo-user-kit-sessions-revoke-others`) | `POST nowo_user_kit_sessions_revoke_others` | `nowo_user_kit_sessions_revoke_others` |
| My sessions | "Sign out" (`nowo-user-kit-sessions-revoke`) | `POST nowo_user_kit_sessions_revoke` | `nowo_user_kit_sessions_revoke_<public id>` (`SessionsPages::revokeTokenId()`) |
| Administration | "Sign out everywhere" (`nowo-user-kit-sessions-revoke-all`) | `POST nowo_user_kit_sessions_admin_revoke_all` | `nowo_user_kit_sessions_admin_revoke_all_<profile>_<user id>` (bound to the user) |
| Administration | "Sign out" (`nowo-user-kit-sessions-admin-revoke`) | `POST nowo_user_kit_sessions_admin_revoke` | `nowo_user_kit_sessions_admin_revoke_<profile>_<user id>_<public id>` |

Each action is a `Nowo\UiKitBundle\View\Action` (kind `delete`, POST) with a FormKit CSRF-only `FormView`
(`CsrfOnlyFormFactory::createNamed()`) and a UiKit `Confirm` dialog; the UiKit action partial renders the form
(`form_start` / `form_end`, no raw `<form>` / `<input>` in the bundle Twig, REQ-TWIG-005). The handlers redirect back
with a flash (UiKit `_flashes`).

## Personal data in the pages

- Only the owner (or a user the access checker lets manage that owner) sees the rows; responses are `private, no-store`.
- The network column shows the truncated network; `hash` IPs (`h:…`) and `none` are shown as "—". The session hash is
  only compared, never printed.

## Unique pages

None: both pages extend the UiKit list scaffold.

## Per-page overrides

```yaml
nowo_user_kit:
    sessions:
        web_ui:
            templates:
                index: 'account/sessions.html.twig'
```

```twig
{# templates/account/sessions.html.twig #}
{% extends '@!NowoUserKitBundle/sessions/index.html.twig' %}

{% block nowo_ui_list_row %}
    {{ parent() }}
{% endblock %}
```

Restyle through `nowo_ui_kit.theme` (slots `table_cell`, `table_cell_muted`, `badge_success`, `code`), never in the
bundle templates. A full override also works under `templates/bundles/NowoUserKitBundle/sessions/` (REQ-TWIG-001).

## Bundle partials

- `sessions/_cells.html.twig` — the row cells of both lists (`row` is a `SessionRow`; keep their order in line with
  `SessionsPages::columns()`).

No local copies of UiKit partials (pagination, flashes, confirm, modal) are shipped.

## Translations

Keys under `sessions.*` in the `NowoUserKitBundle` domain (`de`, `en`, `es`, `fr`, `it`, `nl`, `pt`).
