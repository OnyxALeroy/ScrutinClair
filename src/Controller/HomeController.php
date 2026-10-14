<?php

namespace App\Controller;

use App\Repository\TexteRepository;
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
}
