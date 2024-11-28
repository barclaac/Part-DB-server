<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Base\AbstractDBElement;
use App\Entity\LabelSystem\LabelOptions;
use App\Entity\LabelSystem\LabelProcessMode;
use App\Entity\LabelSystem\LabelProfile;
use App\Entity\LabelSystem\LabelSupportedElement;
use App\Entity\Parts\Category;
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
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
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
    protected ?int  $quantity;
    protected ?string $location;
    
    public function __construct() {
        $this->barcode = "";
        $this->manufacturerPN = "";
        $this->quantity = 0;
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

    public function getQuantity(): ?int {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): self {
        $this->quantity = $quantity;
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
    private $entityManager;
        
    public function __construct(protected TranslatorInterface $translator,
                                EntityManagerInterface $entityManager,
                                PartLotWithdrawAddHelper $withdrawAddHelper,
                                LoggerInterface $logger,)
    {
        $this->entityManager = $entityManager;
        $this->withdrawAddHelper = $withdrawAddHelper;
        $this->logger = $logger;
        $this->logger->info("Create Controller");
    }

    #[Route(path: '/handheldscanner',name: 'handheld_scanner_dialog')]
    public function generator(Request $request, #[MapQueryParameter] ?string $input = null): Response
    {
        $this->logger->info('*** rendering form ***');
        $this->logger->info(var_export($request->getPayload()->all(), true));

        $barcode = new BarcodeScanType();
        $builder = $this->createFormBuilder($barcode);

        $this->buildForm($builder);

        $this->addPreSubmitEventHandler($builder);

        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form['autocommit'] == true || ($form->isSubmitted() && $form->isValid())) {
            $this->processSubmit($form, $barcode);
        }
        
        return $this->render('label_system/handheld_scanner/handheld_scanner.html.twig', [
            'form' => $form,
        ]);
    }

    protected function buildForm(FormBuilder $builder)
    {
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

        $builder->add('quantity', IntegerType::Class, [
            'required' => false,
            'label' => 'Quantity',
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

    protected function addPreSubmitEventHandler(FormBuilder $builder)
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();
            if (!isset($data['barcode'])) {
                return;
            }
            $r = EIGP114::decode($data['barcode']);
            if (array_key_exists('location', $r)) {
                $data['location'] = $r['location'];
                $data['last_scan'] = 'location';
            }
            if (array_key_exists('supplier_pn', $r)) {
                $data['manufacturer_pn'] = $r['supplier_pn'];
                $data['last_scan'] = 'part';
            }
            if (array_key_exists('quantity', $r)) {
                $data['quantity'] = $r['quantity'];
            }

            // Look up the location in the database to see if one needs to be created
            if ($data['location'] != "") {
                
                $storageRepository = $this->entityManager->getRepository(StorageLocation::class);
                $storage = $this->getStorageLocation($data['location']);
                $data['foundloc'] = ($storage != null);
            }

            // Look up the part in the database to see if one needs to be created
            if ($data['manufacturer_pn'] != "") {
                $partRepository = $this->entityManager->getRepository(Part::class);
                $part = $partRepository->findOneBy(['manufacturer_product_number' => $data['manufacturer_pn']]);
                $data['foundpart'] = ($part != null);
                if ($data['foundpart'] == false &&
                    $data['missingpart'] == false &&
                    $data['autocommit'] == true) {
                    $this->addFlash('error', 'Cannot autocommit part - part not in database');
                }
            }

            // Did we want to use the storage location for this part
            // Will require all part-lots to be in the same location
            if (array_key_exists('locfrompart', $data) && $part) {
                $this->logger->info('take loc from part');
                $locs=[];
                foreach ($part->getPartLots() as &$pl) {
                    $locs[$pl->getStorageLocation()->getId()] = $pl->getStorageLocation();
                }
                if (count($locs) == 1) {
                    // Got exactly 1 location - can set this as the default
                    $storageLoc = array_pop($locs);
                    $data['location'] = $storageLoc->getName();
                }
            }

            $event->setData($data);
        });
    }

    protected function processSubmit(Form $form, BarcodeScanType $barcode) {
        $this->logger->info("form submitted");
        if ($form->getClickedButton() == null) {
            // Autosubmit possible?
            if ($form->get('autocommit')->getData() == true &&
                $barcode->getLocation() != '' && $barcode->getManufacturerPN() != '' &&
                $barcode->getQuantity() != 0) {
                $this->logger->info('attempt autosubmit');
                if ($form->get('missingpart')->getData() == true &&
                    $form->get('foundpart')->getData() == false) {
                    $this->logger->info('create missing part');
                    $this->createMissingPart($form, $barcode);
                }
                $this->addStock($form, $barcode);
            }
        }
        if ($form->getClickedButton() != null) {
            // Actual submit button was pressed so commit to database
            $storage = null;
            $part = null;
            // See if the storage location exists was in barcode
            if ($barcode->getLocation() != "") {
                $storage = getStorageLocation($barcode->getLocation());
            } else if ($barcode->getManufacturerPN() != "") {
                // Got a part instead
                $repository = $em->getRepository(Part::class);
                $part = $repository->findOneBy(['manufacturer_product_number' => $barcode->getManufacturerPN()]);
                if ($part) {
                    $this->logger->info($part->getName());
                }

            }
            
            // Does a part lot exist for this combination?
            if ($storage != null && $part != null) {
                $found=false;
                foreach ($part->getPartLots() as &$pl) {
                    if ($pl->getStorageLocation()->getId() == $storage->getId()) {
                        $this->logger->info('Found existing storage location, adding stock');
                        if ($withdrawAddHelper->canAdd($pl)) {
                            $withdrawAddHelper->add($pl, $barcode->getQuantity(), "Test add");
                            $found = true;
                        }
                        $em->flush();
                        break;
                    }
                    $this->logger->info('Part lot {fullPath}', ['fullPath' => $pl->getStorageLocation()->getFullPath()]);
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
                        $withdrawAddHelper->add($partLot, $barcode->getQuantity(), "Creational add");
                    }
                    $em->flush();
                }
            }
        } else {
        }
    }

    protected function getStorageLocation(string $name) : StorageLocation
    {
        $repository = $this->entityManager->getRepository(StorageLocation::class);
        $storage = $repository->findOneBy(['name' => $name]);
        if ($storage) {
            $this->logger->info($storage->getFullPath());
        } else {
            $this->logger->info('Storage not found in database');
        }
        return $storage;
    }

    protected function createMissingPart(Form $form, BarcodeScanType $barcode)
    {
        $repository = $this->entityManager->getRepository(Category::class);
        $category = $repository->findOneBy(['name' => 'Unclassified']);

        $part = new Part();
        $part->setCategory($category);
        $part->setName($barcode->getManufacturerPN());
        $part->setManufacturerProductNumber($barcode->getManufacturerPN());
        $this->entityManager->persist($part);
        $this->entityManager->flush();
    }
    
    protected function addStock(Form $form, BarcodeScanType $barcode)
    {
        $storage = null;
        $part = null;
        // See if the storage location exists was in barcode
        if ($barcode->getLocation() != "") {
            $storage = $this->getStorageLocation($barcode->getLocation());
        }
        if ($barcode->getManufacturerPN() != "") {
            // Got a part instead
            $repository = $this->entityManager->getRepository(Part::class);
            $part = $repository->findOneBy(['manufacturer_product_number' => $barcode->getManufacturerPN()]);
            if ($part) {
                $this->logger->info($part->getName());
            }
        }
            
        // Does a part lot exist for this combination?
        if ($storage != null && $part != null) {
            $found=false;
            foreach ($part->getPartLots() as &$pl) {
                if ($pl->getStorageLocation()->getId() == $storage->getId()) {
                    $this->logger->info('Found existing storage location, adding stock');
                    if ($this->withdrawAddHelper->canAdd($pl)) {
                        $this->withdrawAddHelper->add($pl, $barcode->getQuantity(), "Test add");
                        $found = true;
                    }
                    $this->entityManager->flush();
                    $this->addFlash('success', 'stock added');
                    break;
                }
                $this->logger->info('Part lot {fullPath}', ['fullPath' => $pl->getStorageLocation()->getFullPath()]);
                }
            if (!$found) {
                // No part lot for this storage location - add one
                $partLot = new PartLot();
                $partLot->setStorageLocation($storage);
                $partLot->setInstockUnknown(false);
                $partLot->setAmount(0.0);
                $part->addPartLot($partLot);
                $this->entityManager->flush();
                if ($this->withdrawAddHelper->canAdd($partLot)) {
                    $this->withdrawAddHelper->add($partLot, $barcode->getQuantity(), "Creational add");
                }
                
                $this->entityManager->flush();
                $this->addFlash('success', 'partlot added');
                
            }
        }
    }
}
