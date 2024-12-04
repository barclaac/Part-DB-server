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

    // Show last item and quantity added to help check if scanning is working well
    protected ?string $lastManufacturerPN; 
    protected ?int $lastQuantity;
    
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

    public function getLastManufacturerPN(): ?string {
        return $this->lastManufacturerPN;
    }

    public function setLastManufacturerPN(?string $lastManufacturerPN): self {
        $this->lastManufacturerPN = $lastManufacturerPN;
        return $this;
    }

    public function getQuantity(): ?int {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): self {
        $this->quantity = $quantity;
        return $this;
    }

    public function getLastQuantity(): ?int {
        return $this->lastQuantity;
    }

    public function setLastQuantity(?int $lastQuantity): self {
        $this->lastQuantity = $lastQuantity;
        return $this;
    }

    public function getLocation(): ?string {
        return $this->location;
    }

    public function setLocation(?string $location): self {
        $this->location = $location;
        return $this;
    }

    public function cycleLastAdded() : void {
        $this->lastManufacturerPN = $this->manufacturerPN;
        $this->manufacturerPN = null;
        $this->lastQuantity = $this->quantity;
        $this->quantity = 0;
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
        $form = $this->buildForm($barcode);

        $form->handleRequest($request);

        if ($form['autocommit'] == true || ($form->isSubmitted() && $form->isValid())) {
            if ($this->processSubmit($form, $barcode)) {
                // Need a new form to render because we can't change submitted form
                $this->logger->info('replacing form with fresh');
                $barcode->cycleLastAdded(); // Shuffle into last added slot
                $newForm = $this->buildForm($barcode);
                $newForm->get('missingloc')->setData($form->get('missingloc')->getData());
                $newForm->get('locfrompart')->setData($form->get('locfrompart')->getData());
                $newForm->get('foundloc')->setData($form->get('foundloc')->getData());
                $newForm->get('missingpart')->setData($form->get('missingpart')->getData());
                $newForm->get('manufacturer_pn')->setData('');
                $newForm->get('quantity')->setData(0);
                $form = $newForm;
            }
        }
        
        return $this->render('label_system/handheld_scanner/handheld_scanner.html.twig', [
            'form' => $form,
        ]);
    }

    protected function buildForm(BarcodeScanType $barcode) : Form
    {
        $builder = $this->createFormBuilder($barcode);

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

        
        $this->addPreSubmitEventHandler($builder);

        $form = $builder->getForm();

        return $form;
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
                $data['manufacturer_pn'] = '';
                $data['locfrompart'] = false;
                $data['foundpart'] = false;
                $data['quantity'] = '';
                
            } else if (array_key_exists('supplier_pn', $r)) {
                $data['manufacturer_pn'] = $r['supplier_pn'];
                $data['last_scan'] = 'part';
                if (array_key_exists('quantity', $r)) {
                    $data['quantity'] = $r['quantity'];
                }
            }

            // Look up the location in the database to see if one needs to be created
            if ($data['location'] != "") {
                
                $storageRepository = $this->entityManager->getRepository(StorageLocation::class);
                $storage = $this->getStorageLocation($data['location']);
                $data['foundloc'] = ($storage != null);
            }

            // Look up the part in the database to see if one needs to be created
            $part = null;
            if ($data['manufacturer_pn'] != "") {
                $partRepository = $this->entityManager->getRepository(Part::class);
                $part = $partRepository->findOneBy(['manufacturer_product_number' => $data['manufacturer_pn']]);
                $data['foundpart'] = ($part != null);
                if ($data['foundpart'] == false &&
                    (array_key_exists('missingpart', $data) && $data['missingpart'] == false) &&
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

    protected function processSubmit(Form $form, BarcodeScanType $barcode) : bool {
        $this->logger->info("form submitted");
        // We could be here through an autosubmit or because the actual button was pressed
        // To proceed we need a storage location, manufacturer part number and a quantity
        if ($form->getClickedButton() != null ||
            ($form->getClickedButton() == null && $form->get('autocommit')->getData() == true)) {
            $fail = false;
            if ($barcode->getLocation() != '' && $barcode->getManufacturerPN() != '' &&
                $barcode->getQuantity() != 0) {
                // Got all the data that we need - now work through the items to see if we
                // can submit the data
                $storageLocation = $this->getStorageLocation($barcode->getLocation());
                if (!$storageLocation) {
                    if ($form->get('missingloc')->getData() == true) {
                        $storageLocation = $this->createStorageLocation($barcode->getLocation());
                    } else {
                        $fail = true;
                        $this->addFlash('error', 'storage doesn\'t exist');
                    }
                }

                $part = $this->getPart($barcode->getManufacturerPN());
                if (!$part) {
                    $this->logger->debug('part not found');
                    if ($form->get('missingpart')->getData() == true) {
                        $this->logger->debug('create part');
                        $part = $this->createMissingPart($barcode->getManufacturerPN());
                        $this->logger->debug('part created');
                        
                        $this->entityManager->flush();
                    } else {
                        $fail = true;
                        $this->addFlash('error', 'part doesn\'t exist');
                    }
                }

                if (!$fail) {
                    // Have a part and storage location so attempt to add stock for this combination
                    $found=false;
                    $partLots = $part->getPartLots();
                    if ($partLots) {
                        foreach ($part->getPartLots() as &$pl) {
                            if ($pl->getStorageLocation()->getId() == $storageLocation->getId()) {
                                $this->logger->info('Found existing storage location, adding stock');
                                if ($this->withdrawAddHelper->canAdd($pl)) {
                                    $this->withdrawAddHelper->add($pl, $barcode->getQuantity(), "Barcode scan add");
                                    $found = true;
                                }
                                break;
                            }
                            $this->logger->info('Part lot {fullPath}', ['fullPath' => $pl->getStorageLocation()->getFullPath()]);
                        }
                    }
                    if (!$found) {
                        // No part lot for this storage location - add one
                        $partLot = new PartLot();
                        $partLot->setStorageLocation($storageLocation);
                        $partLot->setInstockUnknown(false);
                        $partLot->setAmount(0.0);
                        $part->addPartLot($partLot);
                        $this->entityManager->flush(); // Must have an ID for target
                        if ($this->withdrawAddHelper->canAdd($partLot)) {
                            $this->withdrawAddHelper->add($partLot, $barcode->getQuantity(), "Barcode scan add");
                        }

                    }
                }
                    
                if (!$fail) {
                    $this->entityManager->flush();
                }

                return true;
                /*       
                $this->logger->info('attempt autosubmit');
                if ($form->get('missingpart')->getData() == true &&
                    $form->get('foundpart')->getData() == false) {
                    $this->logger->info('create missing part');
                    $this->createMissingPart($form, $barcode);
                }
                $this->addStock($form, $barcode);
                return true;*/
            }
        }

        /*
        else {
            // Actual submit button was pressed so commit to database
            $storage = null;
            $part = null;
            // See if the storage location exists was in barcode
            if ($barcode->getLocation() != "") {
                $storage = $this->getStorageLocation($barcode->getLocation());
                if (!$storage && $form->get('missingloc')->getData() == true) {
                    $storage = $this->createStorageLocation($barcode->getLocation());
                    if ($storage) {
                        $this->addFlash('success', 'storage location created');
                    }
                }
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
            }*/

        return false;
    }

    protected function getStorageLocation(string $name) : ?StorageLocation
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

    protected function createStorageLocation(string $location): StorageLocation
    {
        $repository = $this->entityManager->getRepository(StorageLocation::class);
        $storage = new StorageLocation();
        $storage->setName($location);
        $this->entityManager->persist($storage);
        return $storage;
    }

    protected function getPart(string $partNumber) : ?Part
    {
        $repository = $this->entityManager->getRepository(Part::class);
        $part = $repository->findOneBy(['manufacturer_product_number' => $partNumber]);
        if ($part) {
            $this->logger->info($part->getName());
        }
        return $part;
    }
    
    protected function createMissingPart(string $partNumber) : ?Part
    {
        $repository = $this->entityManager->getRepository(Category::class);
        $category = $repository->findOneBy(['name' => 'Unclassified']);

        $part = new Part();
        $part->setCategory($category);
        $part->setName($partNumber);
        $part->setManufacturerProductNumber($partNumber);
        $this->entityManager->persist($part);

        return $part;
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
