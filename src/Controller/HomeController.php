<?php

namespace App\Controller;

use App\Entity\Texte;
use App\Repository\TexteRepository;
use App\Service\AssembleeNationaleImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request, TexteRepository $texteRepository): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('home/index.html.twig', [
            'q' => $q,
            'textes' => $q === '' ? [] : $texteRepository->search($q, 20),
        ]);
    }

    #[Route('/api/textes', name: 'app_textes_search', methods: ['GET'])]
    public function searchTextes(Request $request, TexteRepository $texteRepository): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $textes = array_map(static fn (Texte $texte): array => [
            'id' => $texte->getId(),
            'titre' => $texte->getObjet() ?? $texte->getTitre(),
            'synthese' => $texte->getSynthese(),
        ], $texteRepository->search($q));

        return $this->json($textes);
    }

    #[Route('/textes/{id}', name: 'app_texte_show', methods: ['GET'])]
    public function show(Texte $texte): Response
    {
        return $this->render('texte/show.html.twig', [
            'texte' => $texte,
        ]);
    }

    #[Route('/import', name: 'app_import', methods: ['POST'])]
    public function import(AssembleeNationaleImporter $importer): Response
    {
        $importer->importTextes();

        return $this->redirectToRoute('app_home');
    }
}
