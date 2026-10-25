<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Demo form_login credentials (REQ-TWIG-005 rule 8): flat _username / _password fields and the _csrf_token field
 * with the "authenticate" token id, as the form_login authenticator (enable_csrf) expects.
 *
 * @extends AbstractType<array{_username?: string, _password?: string}>
 */
final class LoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', EmailType::class, [
                'label'       => 'Email',
                'label_attr'  => ['class' => 'form-label'],
                'row_attr'    => ['class' => 'mb-3'],
                'attr'        => ['class' => 'form-control', 'autocomplete' => 'username'],
            ])
            ->add('_password', PasswordType::class, [
                'label'        => 'Password',
                'label_attr'   => ['class' => 'form-label'],
                'row_attr'     => ['class' => 'mb-3'],
                'attr'         => ['class' => 'form-control', 'autocomplete' => 'current-password'],
                // The demo pre-fills the published demo password.
                'always_empty' => false,
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
