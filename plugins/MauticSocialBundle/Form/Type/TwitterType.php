<?php

namespace MauticPlugin\MauticSocialBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<array<mixed>>
 */
class TwitterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('count', ChoiceType::class, [
            'choices' => [
                'mautic.integration.Twitter.share.layout.horizontal' => 'horizontal',
                'mautic.integration.Twitter.share.layout.vertical'   => 'vertical',
                'mautic.integration.Twitter.share.layout.none'       => 'none',
            ],
            'label'             => 'mautic.integration.Twitter.share.layout',
            'required'          => false,
            'placeholder'       => false,
            'label_attr'        => ['class' => 'control-label'],
            'attr'              => ['class' => 'form-control'],
        ]);

        $builder->add('text', TextType::class, [
            'label_attr' => ['class' => 'control-label'],
            'label'      => 'mautic.integration.Twitter.share.text',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'mautic.integration.Twitter.share.text.pagetitle',
            ],
        ]);

        $builder->add('via', TextType::class, [
            'label_attr' => ['class' => 'control-label'],
            'label'      => 'mautic.integration.Twitter.share.via',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'mautic.integration.Twitter.share.username',
                'preaddon'    => 'ri-at-line',
            ],
        ]);

        $builder->add('related', TextType::class, [
            'label_attr' => ['class' => 'control-label'],
            'label'      => 'mautic.integration.Twitter.share.related',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'mautic.integration.Twitter.share.username',
                'preaddon'    => 'ri-at-line',
            ],
        ]);

        $builder->add('hashtags', TextType::class, [
            'label_attr' => ['class' => 'control-label'],
            'label'      => 'mautic.integration.Twitter.share.hashtag',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'mautic.integration.Twitter.share.hashtag.placeholder',
                'preaddon'    => 'symbol-hashtag',
            ],
        ]);

        $builder->add('size', YesNoButtonGroupType::class, [
            'no_value'  => 'medium',
            'yes_value' => 'large',
            'label'     => 'mautic.integration.Twitter.share.largesize',
            'data'      => (!empty($options['data']['size'])) ? $options['data']['size'] : 'medium',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'socialmedia_twitter';
    }
}
