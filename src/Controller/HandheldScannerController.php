<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Base\AbstractDBElement;
use App\Entity\LabelSystem\LabelOptions;
use App\Entity\LabelSystem\LabelProcessMode;
use App\Entity\LabelSystem\LabelProfile;
use App\Entity\LabelSystem\LabelSupportedElement;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Exceptions\TwigModeException;
use App\Form\LabelSystem\HandheldScannerDialogType;
use App\Helpers\EIGP114;
use App\Repository\DBElementRepository;
use App\Services\ElementTypeNameGenerator;
use App\Services\LabelSystem\LabelGenerator;
use App\Services\Misc\RangeParser;
use App\Services\Parts\PartLotWithdrawAddHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class BarcodeScanType
{
    protected ?string $barcode;
    protected ?string $manufacturerPN;
    protected ?string $location;
    
    public function __construct() {
        $this->barcode = "";
        $this->manufacturerPN = "";
        $this->location = "";
    }
    
    public function getBarcode(): ?string {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): self {
        $this->barcode = $barcode;
        return $this;
    }

    public function getManufacturerPN(): ?string {
        return $this->manufacturerPN;
    }
    
    public function setManufacturerPN(?string $manufacturerPN): self {
        $this->manufacturerPN = $manufacturerPN;
        return $this;
    }

    public function getLocation(): ?string {
        return $this->location;
    }

    public function setLocation(?string $location): self {
        $this->location = $location;
        return $this;
    }
}

class HandheldScannerController extends AbstractController
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    #[Route(path: '/handheldscanner',name: 'handheld_scanner_dialog')]
    public function generator(Request $request, EntityManagerInterface $em, LoggerInterface $logger, PartLotWithdrawAddHelper $withdrawAddHelper, #[MapQueryParameter] ?string $input = null): Response
    {
        $logger->info('*** rendering form ***');
        $logger->info(var_export($request->getPayload()->all(), true));

        $barcode = new BarcodeScanType();
        $builder = $this->createFormBuilder($barcode);
        $builder->add('barcode', HiddenType::Class, [
            'required' => true,
            'action' => '',
        ]);
        $builder->add('location', TextType::Class, [
            'required' => false,
            'label' => 'Storage Location',
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
            if (array_key_exists('location', $r)) {
                $data['location'] = $r['location'];
            }
            if (array_key_exists('supplier_pn', $r)) {
                $data['manufacturer_pn'] = $r['supplier_pn'];
            }
 
            $event->setData($data);
        });

        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $logger->info("form submitted");

            $storage = null;
            $part = null;
            // See if the storage location exists
            if ($barcode->getLocation() != "") {
                $repository = $em->getRepository(StorageLocation::class);
                $storage = $repository->findOneBy(['name' => $barcode->getLocation()]);
                $logger->info($storage->getFullPath());
            }

            // See if the part exists
            if ($barcode->getManufacturerPN() != "") {
                $repository = $em->getRepository(Part::class);
                $part = $repository->findOneBy(['manufacturer_product_number' => $barcode->getManufacturerPN()]);
                $logger->info($part->getName());
            }

            // Does a part lot exist for this combination?
            if ($storage != null && $part != null) {
                $found=false;
                foreach ($part->getPartLots() as &$pl) {
                    if ($pl->getStorageLocation()->getId() == $storage->getId()) {
                        $logger->info('Found existing storage location, adding stock');
                        if ($withdrawAddHelper->canAdd($pl)) {
                            $withdrawAddHelper->add($pl, 10.0, "Test add");
                            $found = true;
                        }
                        $em->flush();
                        break;
                    }
                    $logger->info('Part lot {fullPath}', ['fullPath' => $pl->getStorageLocation()->getFullPath()]);
                }
                if (!$found) {
                    // No part lot for this storage location - add one
                    $partLot = new PartLot();
                    $partLot->setStorageLocation($storage);
                    $partLot->setInstockUnknown(false);
                    $partLot->setAmount(0.0);
                    $part->addPartLot($partLot);
                    $em->flush();
                    if ($withdrawAddHelper->canAdd($partLot)) {
                        $withdrawAddHelper->add($partLot, 42.0, "Creational add");
                    }
                    $em->flush();
                }
            }
        }

        return $this->render('label_system/handheld_scanner/handheld_scanner.html.twig', [
            'form' => $form,
        ]);
    }
}
