<?php

namespace App\Controller;

use App\Repository\GroupePolitiqueRepository;
use App\Repository\PersonnalitePolitiqueRepository;
use App\Repository\VoteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GroupePolitiqueController extends AbstractController
{
    #[Route('/partis', name: 'app_partis', methods: ['GET'])]
    public function index(GroupePolitiqueRepository $groupeRepository): Response
    {
        return $this->render('groupe/index.html.twig', [
            'groupes' => $groupeRepository->findAllOrdered(),
        ]);
    }

    #[Route('/partis/{uid}', name: 'app_partis_show', methods: ['GET'], requirements: ['uid' => '[A-Za-z0-9]+'])]
    public function show(
        string $uid,
        Request $request,
        GroupePolitiqueRepository $groupeRepository,
        PersonnalitePolitiqueRepository $personnaliteRepository,
        VoteRepository $voteRepository,
    ): Response {
        $groupe = $groupeRepository->findOneBy(['uid' => $uid]);
        if ($groupe === null) {
            throw $this->createNotFoundException('Ce groupe politique est introuvable.');
        }

        $parPage = 20;
        $total = $voteRepository->compterTextesPourGroupe($groupe);
        $pages = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);

        return $this->render('groupe/show.html.twig', [
            'groupe' => $groupe,
            'membres' => $personnaliteRepository->findByGroupe($groupe),
            'votes' => $voteRepository->statistiquesParTextePourGroupe($groupe, $parPage, ($page - 1) * $parPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }
}
