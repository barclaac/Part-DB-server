<?php

declare(strict_types=1);

namespace App\Form\LabelSystem;

use Symfony\Bundle\SecurityBundle\Security;
use App\Form\LabelOptionsType;
use App\Validator\Constraints\Misc\ValidRange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HandheldScannerDialogType extends AbstractType
{
    public function __construct(protected Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
    }
}
