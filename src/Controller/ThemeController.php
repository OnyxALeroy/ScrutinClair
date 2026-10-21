<?php

namespace App\Controller;

use App\Enum\Theme;
use App\Repository\TagRepository;
use App\Repository\TexteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ThemeController extends AbstractController
{
    #[Route('/themes', name: 'app_themes', methods: ['GET'])]
    public function index(TagRepository $tagRepository): Response
    {
        $compteurs = $tagRepository->compterTextesParTheme();

        $themes = [];
        foreach (Theme::cases() as $theme) {
            $themes[] = [
                'theme' => $theme,
                'nombre' => $compteurs[$theme->label()] ?? 0,
            ];
        }

        return $this->render('theme/index.html.twig', [
            'themes' => $themes,
        ]);
    }

    #[Route('/themes/{slug}', name: 'app_theme_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function show(
        string $slug,
        Request $request,
        TagRepository $tagRepository,
        TexteRepository $texteRepository,
    ): Response {
        $theme = $this->themesDuCatalogue()[$slug] ?? null;
        if ($theme === null) {
            throw $this->createNotFoundException('Ce thème est introuvable.');
        }

        $tag = $tagRepository->findOneBy(['slug' => $slug]);

        $parPage = 20;
        $total = $tag !== null ? $texteRepository->compterPourTheme($tag) : 0;
        $pages = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);

        return $this->render('theme/show.html.twig', [
            'theme' => $theme,
            'textes' => $tag !== null ? $texteRepository->findPourTheme($tag, $parPage, ($page - 1) * $parPage) : [],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    /**
     * @return array<string, Theme>
     */
    private function themesDuCatalogue(): array
    {
        $thèmes = [];
        foreach (Theme::cases() as $theme) {
            $thèmes[$theme->slug()] = $theme;
        }

        return $thèmes;
    }
}
