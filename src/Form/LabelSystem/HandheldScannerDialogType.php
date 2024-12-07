<?php

declare(strict_types=1);

namespace App\Form\LabelSystem;

use Symfony\Bundle\SecurityBundle\Security;
use App\Form\LabelOptionsType;
use App\Helpers\EIGP114;
use App\Validator\Constraints\Misc\ValidRange;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
        $builder->add('location', TextType::Class, [
            'required' => false,
            'label' => 'Storage Location',
            'help' => 'Scan this first, will erase part fields',
        ]);
        
        $builder->add('manufacturer_pn', TextType::Class, [
            'required' => false,
            'label' => 'Manufacturer Part',
        ]);

        $builder->add('last_manufacturer_pn', TextType::Class, [
            'required' => false,
            'label' => 'Last Added Manufacturer Part',
        ]);

        $builder->add('quantity', IntegerType::Class, [
            'required' => false,
            'label' => 'Quantity',
        ]);

        $builder->add('last_quantity', IntegerType::Class, [
            'required' => false,
            'label' => 'Last Added Quantity',
        ]);

        $builder->add('missingloc', CheckboxType::Class, [
            'label' => 'Create missing Storage Location',
            'mapped' => false,
            'required' => false,
        ]);

        $builder->add('locfrompart', CheckboxType::Class, [
            'label' => 'Take storage location from part',
            'mapped' => false,
            'required' => false,
        ]);

        $builder->add('foundloc', CheckboxType::Class, [
            'label' => 'Storage Location in database',
            'mapped' => false,
            'required' => false,
        ]);

        $builder->add('missingpart', CheckboxType::Class, [
            'label' => 'Create missing Part',
            'mapped' => false,
            'required' => false,
        ]);
        $builder->add('foundpart', CheckboxType::Class, [
            'label' => 'Part in database',
            'mapped' => false,
            'required' => false,
        ]);

        $builder->add('autocommit', CheckboxType::Class, [
            'label' => 'Autocommit on Scan',
            'mapped' => false,
            'required' => false,
        ]);

        $builder->add('connect', ButtonType::Class, [
            'label' => 'Connect',
            'attr' => ['data-action' => 'pages--handheld-scan#onConnectScanner', 'class' => 'btn btn-primary' ],
        ]);
        
        $builder->add('submit', SubmitType::Class, [
            'label' => 'Submit',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('mapped', false);
    }
}
