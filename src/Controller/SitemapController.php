<?php

namespace App\Controller;

use App\Repository\GouvernementRepository;
use App\Repository\GroupePolitiqueRepository;
use App\Repository\PersonnalitePolitiqueRepository;
use App\Repository\TagRepository;
use App\Repository\TexteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(
        Request $request,
        TexteRepository $texteRepository,
        GroupePolitiqueRepository $groupeRepository,
        GouvernementRepository $gouvernementRepository,
        PersonnalitePolitiqueRepository $personnaliteRepository,
        TagRepository $tagRepository,
        \App\Service\DossierLegislatifIndex $dossierIndex,
    ): Response {
        $base = $request->getSchemeAndHttpHost();
        $urls = [
            $this->url($base, $this->generateUrl('app_home')),
            $this->url($base, $this->generateUrl('app_dossiers_index')),
            $this->url($base, $this->generateUrl('app_partis')),
            $this->url($base, $this->generateUrl('app_gouvernements')),
            $this->url($base, $this->generateUrl('app_personnalites')),
            $this->url($base, $this->generateUrl('app_themes')),
        ];

        foreach ($texteRepository->findAllIds() as $id) {
            $urls[] = $this->url($base, $this->generateUrl('app_texte_show', ['id' => $id]));
        }
        foreach ($groupeRepository->findAllOrdered() as $groupe) {
            $urls[] = $this->url($base, $this->generateUrl('app_partis_show', ['uid' => $groupe->getUid()]));
        }
        foreach ($gouvernementRepository->findAllOrdered() as $gouvernement) {
            $urls[] = $this->url($base, $this->generateUrl('app_gouvernements_show', ['uid' => $gouvernement->getUid()]));
        }
        foreach ($personnaliteRepository->findAllOrdered() as $personnalite) {
            $urls[] = $this->url($base, $this->generateUrl('app_personnalites_show', ['uid' => $personnalite->getUid()]));
        }
        foreach ($tagRepository->findAvecTextes() as $tag) {
            $urls[] = $this->url($base, $this->generateUrl('app_theme_show', ['slug' => $tag->getSlug()]));
        }

        // Dossiers législatifs (index + individual pages)
        foreach ($dossierIndex->allDossierRefs() as $ref) {
            $urls[] = $this->url($base, $this->generateUrl('app_dossier_show', ['dossierRef' => $ref]));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . implode("\n", $urls) . "\n"
            . '</urlset>' . "\n";

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function url(string $base, string $path): string
    {
        return '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
    }
}
