<?php

namespace App\Controller;

use App\Repository\GouvernementRepository;
use App\Repository\MembreGouvernementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GouvernementController extends AbstractController
{
    #[Route('/gouvernements', name: 'app_gouvernements', methods: ['GET'])]
    public function index(GouvernementRepository $gouvernementRepository): Response
    {
        return $this->render('gouvernement/index.html.twig', [
            'gouvernements' => $gouvernementRepository->findAllOrdered(),
        ]);
    }

    #[Route('/gouvernements/{uid}', name: 'app_gouvernements_show', methods: ['GET'], requirements: ['uid' => '[A-Za-z0-9]+'])]
    public function show(
        string $uid,
        GouvernementRepository $gouvernementRepository,
        MembreGouvernementRepository $membreGouvernementRepository,
    ): Response {
        $gouvernement = $gouvernementRepository->findOneBy(['uid' => $uid]);
        if ($gouvernement === null) {
            throw $this->createNotFoundException('Ce gouvernement est introuvable.');
        }

        $membres = $membreGouvernementRepository->findByGouvernementOrdered($gouvernement);

        $groupes = [];
        foreach ($membres as $membre) {
            $groupes[$membre->getCategorie()][] = $membre;
        }

        $ordre = [
            'Premier ministre',
            'Ministres d’État',
            'Ministres',
            'Ministres délégués',
            'Secrétaires d’État',
        ];

        $membresParCategorie = [];
        foreach ($ordre as $titre) {
            if (isset($groupes[$titre])) {
                $membresParCategorie[] = [
                    'titre' => $titre,
                    'membres' => $groupes[$titre],
                ];
                unset($groupes[$titre]);
            }
        }

        foreach ($groupes as $titre => $membresRestants) {
            $membresParCategorie[] = [
                'titre' => $titre,
                'membres' => $membresRestants,
            ];
        }

        return $this->render('gouvernement/show.html.twig', [
            'gouvernement' => $gouvernement,
            'membresParCategorie' => $membresParCategorie,
            'nombreMembres' => \count($membres),
        ]);
    }
}
