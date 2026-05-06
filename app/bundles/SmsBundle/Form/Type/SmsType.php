<?php

namespace Mautic\SmsBundle\Form\Type;

use Doctrine\ORM\EntityManager;
use Mautic\CategoryBundle\Form\Type\CategoryListType;
use Mautic\CoreBundle\Form\DataTransformer\IdToEntityModelTransformer;
use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\CoreBundle\Form\EventListener\FormExitSubscriber;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\PublishDownDateType;
use Mautic\CoreBundle\Form\Type\PublishUpDateType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\LeadBundle\Form\Type\LeadListType;
use Mautic\ProjectBundle\Form\Type\ProjectType;
use Mautic\SmsBundle\Entity\Sms;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType; // <-- Adicionado para o Dropdown
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\LocaleType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Sms>
 */
class SmsType extends AbstractType
{
    public function __construct(
        private readonly EntityManager $em,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventSubscriber(new CleanFormSubscriber(['content' => 'html', 'customHtml' => 'html']));
        $builder->addEventSubscriber(new FormExitSubscriber('sms.sms', $options));

        $builder->add(
            'name',
            TextType::class,
            [
                'label'      => 'mautic.sms.form.internal.name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'description',
            TextareaType::class,
            [
                'label'      => 'mautic.sms.form.internal.description',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
                'required'   => false,
            ]
        );

        // ==========================================
        // INÍCIO DOS CAMPOS CUSTOMIZADOS DO SETUP MNO
        // ==========================================
        
        $builder->add('tipo_disparo', ChoiceType::class, [
            'label' => 'Estratégia de Disparo (Roteamento n8n)',
            'label_attr' => ['class' => 'control-label font-weight-bold text-primary'],
            'mapped' => false, 
            'choices' => [
                'WhatsApp (API Não Oficial) - Fluxo Typebot' => 'wa_nao_oficial_typebot',
                'WhatsApp (API Não Oficial) - Texto Livre' => 'wa_nao_oficial_texto',
                'WhatsApp (API Oficial Meta) - Fluxo Typebot' => 'wa_oficial_typebot',
                'SMS Tradicional' => 'sms_simples',
            ],
            'attr' => ['class' => 'form-control', 'id' => 'tipo_disparo_selector'],
        ]);

        $builder->add('ui_template', TextType::class, [
            'label' => 'Nome do Template (Typebot)',
            'mapped' => false,
            'required' => false,
            'attr' => ['class' => 'form-control', 'id' => 'ui_template_field'],
        ]);

        $builder->add('ui_mensagem', TextareaType::class, [
            'label' => 'Conteúdo da Mensagem',
            'mapped' => false,
            'required' => false,
            'attr' => ['class' => 'form-control', 'id' => 'ui_mensagem_field', 'rows' => 4],
        ]);

        $builder->add('ui_remetente', TextType::class, [
            'label' => 'Dono / Nome da Instância (Ex: Business3)',
            'mapped' => false,
            'required' => false,
            'attr' => ['class' => 'form-control', 'id' => 'ui_remetente_field'],
        ]);

        // O CAMPO ORIGINAL DO MAUTIC FOI OCULTADO
        $builder->add(
            'message',
            TextareaType::class,
            [
                'label'      => 'Payload Gerado',
                'label_attr' => ['class' => 'control-label', 'style' => 'display:none;'],
                'attr'       => [
                    'class'                => 'form-control',
                    'style'                => 'display:none;', // Invisível na tela
                    'data-token-activator' => '{',
                    'data-token-visual'    => 'false',
                    'rows'                 => 6,
                    'id'                   => 'mensagem_original_oculta'
                ],
            ]
        );
        // ==========================================
        // FIM DOS CAMPOS CUSTOMIZADOS
        // ==========================================

        $builder->add('isPublished', YesNoButtonGroupType::class, [
            'label' => 'mautic.core.form.available',
        ]);

        // add lead lists
        $transformer = new IdToEntityModelTransformer($this->em, \Mautic\LeadBundle\Entity\LeadList::class, 'id', true);
        $builder->add(
            $builder->create(
                'lists',
                LeadListType::class,
                [
                    'label'      => 'mautic.email.form.list',
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => [
                        'class'        => 'form-control',
                    ],
                    'multiple' => true,
                    'expanded' => false,
                    'required' => true,
                ]
            )
                ->addModelTransformer($transformer)
        );

        $builder->add('publishUp', PublishUpDateType::class);
        $builder->add('publishDown', PublishDownDateType::class);

        // add category
        $builder->add(
            'category',
            CategoryListType::class,
            [
                'bundle' => 'sms',
            ]
        );

        $builder->add('projects', ProjectType::class);

        $builder->add(
            'language',
            LocaleType::class,
            [
                'label'      => 'mautic.core.language',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class' => 'form-control',
                ],
                'required' => false,
            ]
        );

        $transformer = new IdToEntityModelTransformer($this->em, Sms::class);
        $builder->add(
            $builder->create(
                'translationParent',
                HiddenType::class
            )->addModelTransformer($transformer)
        );

        $builder->add(
            'translationParentSelector', 
            SmsListType::class, 
            [
                'label'      => 'mautic.core.form.translation_parent',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.core.form.translation_parent.help',
                ],
                'required'       => false,
                'multiple'       => false,
                'placeholder'    => 'mautic.core.form.translation_parent.empty',
                'top_level'      => 'translation',
                'ignore_ids'     => [(int) $options['data']->getId()],
                'mapped'         => false,
                'data'           => ($options['data']->getTranslationParent()) ? $options['data']->getTranslationParent()->getId() : null,
            ]
        );

        // ==========================================
        // INTERCEPTADOR: MONTAGEM DO PAYLOAD
        // ==========================================
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) {
                $data = $event->getData();
                
                if (isset($data['translationParentSelector'])) {
                    $data['translationParent'] = $data['translationParentSelector'];
                }

                // Pega os campos soltos que o usuário digitou e compila no formato do n8n
                if (isset($data['tipo_disparo'])) {
                    $tipo = $data['tipo_disparo'];
                    $template = $data['ui_template'] ?? '';
                    $mensagem = $data['ui_mensagem'] ?? '';
                    $remetente = $data['ui_remetente'] ?? '';

                    $payload = "";

                    switch ($tipo) {
                        case 'wa_nao_oficial_typebot':
                            $payload = "template: " . $template . "\nnumber: {contactfield=phone}\nnome: {contactfield=firstname}\nchatbot: true\nremetente: " . $remetente;
                            break;
                        case 'wa_nao_oficial_texto':
                            $payload = "mensagem_whatsapp: " . $mensagem . "\nnumber: {contactfield=phone}\nremetente: " . $remetente;
                            break;
                        case 'wa_oficial_typebot':
                            $payload = "template_oficial: " . $template . "\nnumber: {contactfield=phone}\nnome: {contactfield=firstname}\nchatbot: true\nremetente: " . $remetente;
                            break;
                        case 'sms_simples':
                            $payload = "mensagem_sms: " . $mensagem . "\nnumber: {contactfield=phone}\nremetente: " . $remetente;
                            break;
                    }

                    // Sobrescreve o campo "message" real do Mautic com o payload completo
                    $data['message'] = $payload;
                }

                $event->setData($data);
            }
        );

        $builder->add('smsType', HiddenType::class);
        $builder->add('buttons', FormButtonsType::class);

        if (!empty($options['update_select'])) {
            $builder->add(
                'buttons',
                FormButtonsType::class,
                [
                    'apply_text' => false,
                ]
            );
            $builder->add(
                'updateSelect',
                HiddenType::class,
                [
                    'data'   => $options['update_select'],
                    'mapped' => false,
                ]
            );
        } else {
            $builder->add(
                'buttons',
                FormButtonsType::class
            );
        }

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'data_class' => Sms::class,
            ]
        );

        $resolver->setDefined(['update_select']);
    }
}
