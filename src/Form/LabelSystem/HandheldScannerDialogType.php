<?php

declare(strict_types=1);

namespace App\Form\LabelSystem;

use Symfony\Bundle\SecurityBundle\Security;
use App\Form\LabelOptionsType;
use App\Helpers\EIGP114;
use App\Validator\Constraints\Misc\ValidRange;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HandheldScannerDialogType extends AbstractType
{
    public function __construct(protected Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder,  array $options = []): void
    {
        $builder->add('barcode', HiddenType::Class, [
            'required' => true,
            'action' => '',
        ]);
        $builder->add('manufacturer_pn', TextType::Class, [
            'required' => false,
            'label' => 'Manufacturer Part',
        ]);
        
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();
            if (!isset($data['barcode'])) {
                return;
            }
            $r = EIGP114::decode($data['barcode']);
            $data['manufacturer_pn'] = print_r($r, true);
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('mapped', false);
    }
}
