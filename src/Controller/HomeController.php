<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use App\Service\AssembleeNationaleImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(TexteRepository $texteRepository): Response
    {
        // Récupère le premier texte de la table
        $texte = $texteRepository->findOneBy([]);

        return $this->render('home/index.html.twig', [
            'texte' => $texte,
        ]);
    }

    #[Route('/import', name: 'app_import', methods: ['POST'])]
    public function import(AssembleeNationaleImporter $importer): Response
    {
        // Le code va s'arrêter net à l'intérieur de ce service à cause du dd()
        $importer->importTextes();

        return $this->redirectToRoute('app_home');
    }
}
