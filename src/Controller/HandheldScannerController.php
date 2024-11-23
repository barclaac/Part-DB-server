<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Base\AbstractDBElement;
use App\Entity\LabelSystem\LabelOptions;
use App\Entity\LabelSystem\LabelProcessMode;
use App\Entity\LabelSystem\LabelProfile;
use App\Entity\LabelSystem\LabelSupportedElement;
use App\Exceptions\TwigModeException;
use App\Form\LabelSystem\HandheldScannerDialogType;
use App\Repository\DBElementRepository;
use App\Services\ElementTypeNameGenerator;
use App\Services\LabelSystem\LabelGenerator;
use App\Services\Misc\RangeParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class HandheldScannerController extends AbstractController
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    #[Route(path: '/handheldscanner',name: 'handheld_scanner_dialog')]
    public function generator(Request $request, LoggerInterface $logger, #[MapQueryParameter] ?string $input = null): Response
    {
        $logger->info('*** rendering form ***');
        $logger->info(var_export($request->getPayload()->all(), true));

        $form = $this->createForm(HandheldScannerDialogType::class);

        $form->handleRequest($request);

        return $this->render('label_system/handheld_scanner/handheld_scanner.html.twig', [
            'form' => $form,
        ]);
    }
    /*
    #[Route(path: '/handheldscanner', methods: ['POST'])]
    public function parseBarcode(Request $request, LoggerInterface $logger, #[MapQueryParameter] ?string $input = null): Response
    {

        $form = $this->createForm(HandheldScannerDialogType::class);
        $form->get('manufacturer_pn')->setData($request->getPayload()->get('barcode'));
        $form->handleRequest($request);

        if ($input === null && $form->isSubmitted()) {
        }

        return $this->render('label_system/handheld_scanner/handheld_scanner.html.twig', [
            'form' => $form,
        ]);
        }*/
}
