<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Demo form_login credentials (REQ-TWIG-005 rule 8, REQ-DEMO-015): a FormKit form (profile `demo`) with flat
 * _username / _password fields and the _csrf_token field with the "authenticate" token id, as the form_login
 * authenticator (enable_csrf) expects.
 *
 * @extends AbstractType<array{_username?: string, _password?: string}>
 */
#[FormKitConfig('demo')]
final class LoginType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addEmail($builder, '_username', [
            'label' => 'Email',
            'translation_domain' => false,
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'username'],
        ]);
        $this->addPassword($builder, '_password', [
            'label' => 'Password',
            'translation_domain' => false,
            'placeholder' => false,
            'help' => false,
            // The demo pre-fills the published demo password.
            'always_empty' => false,
            'attr' => ['autocomplete' => 'current-password'],
        ]);
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label' => 'Sign in',
            'translation_domain' => false,
            'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id'   => 'authenticate',
        ]);
    }

    /**
     * Empty prefix so field names match the form_login authenticator.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
