<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection;

use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\PersonalData\Eraser\UiPreferencesEraser;
use Nowo\UserKitBundle\PersonalData\ErasureGuardInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\UserKitBundle\PersonalData\Provider\UiPreferencesExportProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoUserKitExtensionPersonalDataTest extends TestCase
{
    public function testDisabledByDefault(): void
    {
        $container = $this->load([]);

        $this->assertFalse($container->getParameter('nowo_user_kit.personal_data.enabled'));
        $this->assertFalse($container->hasDefinition(PersonalDataExporter::class));
        $this->assertFalse($container->hasDefinition(PersonalDataEraser::class));
        $this->assertFalse($container->hasDefinition(UiPreferencesExportProvider::class));
        $this->assertArrayNotHasKey(PersonalDataExportProviderInterface::class, $container->getAutoconfiguredInstanceof());
    }

    public function testEnabledWiresServicesAndAutoconfiguration(): void
    {
        $container = $this->load(['enabled' => true, 'schema' => 'app-export', 'version' => 2]);

        $this->assertTrue($container->hasDefinition(PersonalDataExporter::class));
        $this->assertTrue($container->hasDefinition(PersonalDataEraser::class));
        $this->assertSame('app-export', $container->getParameter('nowo_user_kit.personal_data.schema'));
        $this->assertSame(2, $container->getParameter('nowo_user_kit.personal_data.version'));
        $this->assertSame([['priority' => -120]], $container->getDefinition(UiPreferencesExportProvider::class)->getTag(NowoUserKitExtension::TAG_EXPORT_PROVIDER));
        $this->assertTrue($container->hasDefinition(UiPreferencesEraser::class));

        $instanceof = $container->getAutoconfiguredInstanceof();
        $this->assertTrue($instanceof[PersonalDataExportProviderInterface::class]->hasTag(NowoUserKitExtension::TAG_EXPORT_PROVIDER));
        $this->assertTrue($instanceof[PersonalDataEraserInterface::class]->hasTag(NowoUserKitExtension::TAG_ERASER));
        $this->assertTrue($instanceof[ErasureGuardInterface::class]->hasTag(NowoUserKitExtension::TAG_ERASURE_GUARD));
    }

    public function testBuiltinsCanBeTurnedOff(): void
    {
        $container = $this->load(['enabled' => true, 'builtin_providers' => false, 'builtin_erasers' => false]);

        $this->assertTrue($container->hasDefinition(PersonalDataExporter::class));
        $this->assertFalse($container->hasDefinition(UiPreferencesExportProvider::class));
        $this->assertFalse($container->hasDefinition(UiPreferencesEraser::class));
    }

    /**
     * @param array<string, mixed> $personalData
     */
    private function load(array $personalData): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class'    => 'App\\Entity\\User',
            'personal_data' => $personalData,
        ]], $container);

        return $container;
    }
}
