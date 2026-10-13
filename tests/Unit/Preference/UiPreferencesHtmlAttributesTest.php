<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Preference;

use Nowo\UserKitBundle\Model\UiPreferences;
use Nowo\UserKitBundle\Preference\UiPreferencesHtmlAttributes;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\PreferencesUser;
use Nowo\UserKitBundle\Twig\UiPreferencesExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Twig\Node\Node;

final class UiPreferencesHtmlAttributesTest extends TestCase
{
    public function testGuestsGetNoAttributes(): void
    {
        $builder = new UiPreferencesHtmlAttributes();

        $this->assertSame([], $builder->build(null));
        $this->assertSame('', $builder->render(new stdClass()));
    }

    public function testDefaultAttributesUseTheHostNames(): void
    {
        $this->assertSame([
            'data-theme'           => 'light',
            'data-user-theme'      => 'light',
            'data-user-density'    => 'comfortable',
            'data-user-motion'     => 'system',
            'data-user-font-scale' => 'md',
            'data-user-contrast'   => 'system',
            'data-user-sidebar'    => 'expanded',
            'data-user-footer'     => 'scroll',
        ], (new UiPreferencesHtmlAttributes())->build(new UiPreferences()));
    }

    public function testResolvedAttributesAndFixedFooter(): void
    {
        $user = new PreferencesUser('u');
        $user->getUiPreferences()->setPreferredFooter('fixed')->setPreferredTheme('dark')->setPreferredUiDensity('compact');

        $attributes = (new UiPreferencesHtmlAttributes())->build($user, true);

        $this->assertSame('1', $attributes['data-footer-fixed']);
        $this->assertSame('dark', $attributes['data-theme']);
        $this->assertSame('compact', $attributes['data-ui-density']);
        $this->assertSame('system', $attributes['data-motion']);
        $this->assertSame('md', $attributes['data-font-scale']);
        $this->assertSame('system', $attributes['data-contrast']);
        $this->assertSame('content', $attributes['data-content-width']);
    }

    public function testTwigFunctionRendersEscapedAttributes(): void
    {
        $extension = new UiPreferencesExtension(new UiPreferencesHtmlAttributes());
        $function  = $extension->getFunctions()[0];

        $this->assertSame('user_ui_html_attributes', $function->getName());
        $this->assertSame(['html'], $function->getSafe(new Node()));
        $this->assertStringStartsWith('data-theme="light" data-user-theme="light"', $extension->htmlAttributes(new UiPreferences()));
        $this->assertStringContainsString('data-content-width="content"', $extension->htmlAttributes(new UiPreferences(), ['resolved' => true]));
        $this->assertSame('', $extension->htmlAttributes(null));
    }
}
