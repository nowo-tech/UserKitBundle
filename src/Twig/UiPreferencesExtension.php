<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Twig;

use Nowo\UserKitBundle\Preference\UiPreferencesHtmlAttributes;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `user_ui_html_attributes(user, {resolved: false})` — prints the `<html>` data-* attributes.
 */
final class UiPreferencesExtension extends AbstractExtension
{
    public function __construct(
        private readonly UiPreferencesHtmlAttributes $attributes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_ui_html_attributes', $this->htmlAttributes(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array{resolved?: bool} $options
     */
    public function htmlAttributes(?object $subject, array $options = []): string
    {
        return $this->attributes->render($subject, (bool) ($options['resolved'] ?? false));
    }
}
