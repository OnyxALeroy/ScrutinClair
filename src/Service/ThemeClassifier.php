<?php

namespace App\Service;

use App\Enum\Theme;

/**
 * Classe un texte de loi dans le catalogue fermé des thèmes politiques.
 *
 * Le modèle reçoit la liste des thèmes possibles et doit répondre en JSON.
 * Toute réponse illisible, ou tout thème absent du catalogue, est ignoré :
 * un texte sans thème vaut mieux qu'un thème inventé.
 */
final class ThemeClassifier
{
    private const SYSTEME = <<<'PROMPT'
    Tu classes des textes de loi français dans un catalogue de thèmes politiques.
    Tu ne choisis que des thèmes du catalogue fourni, en recopiant le libellé exact.
    Un seul thème suffit : n'ajoute un deuxième thème que s'il est lui aussi clairement pertinent.
    Tu ne commentes pas, tu ne reformules pas : tu réponds seulement par un objet JSON.
    PROMPT;

    /** Nombre maximal de thèmes retenus pour un texte. */
    private const MAX_THEMES = 3;

    /** Longueur maximale du contenu envoyé au modèle (~1 token ≈ 4 caractères). */
    private const TAILLE_MAX = 3500;

    /** Nombre maximal de tokens générés (la réponse JSON est courte). */
    private const NUM_PREDICT = 250;

    public function __construct(private readonly OllamaClient $ollama)
    {
    }

    /**
     * @return list<Theme> les thèmes reconnus, dans l'ordre du catalogue
     */
    public function classer(string $contenu): array
    {
        $contenu = trim($contenu);
        if ($contenu === '') {
            return [];
        }

        try {
            $reponse = $this->ollama->chatJson(
                self::SYSTEME,
                $this->construirePrompt($contenu),
                ['temperature' => 0.1, 'num_predict' => self::NUM_PREDICT],
            );
        } catch (\Throwable) {
            return [];
        }

        $themes = $this->extraireThemes($reponse);
        if ($themes !== []) {
            return $themes;
        }

        // Réponse inexploitable (JSON tronqué, thème hors catalogue) : on
        // redemande une réponse plus contrainte avant d'abandonner.
        try {
            $reponse = $this->ollama->chatJson(
                self::SYSTEME,
                $this->construirePrompt($contenu)
                    . "\n\nRéponds uniquement par {\"themes\": []} si aucun thème ne correspond.",
                ['temperature' => 0, 'num_predict' => self::NUM_PREDICT],
            );
        } catch (\Throwable) {
            return [];
        }

        return $this->extraireThemes($reponse);
    }

    private function construirePrompt(string $contenu): string
    {
        $extrait = mb_substr($contenu, 0, self::TAILLE_MAX);

        return "Voici un texte de loi ou un amendement de l'Assemblée nationale.\n"
            . "Choisis les thèmes du catalogue ci-dessous qui correspondent le mieux à son contenu, "
            . "par ordre de pertinence. Un seul thème peut suffire : n'en ajoute un deuxième que "
            . "s'il est lui aussi clairement pertinent. Si aucun thème ne correspond, "
            . "renvoie une liste vide.\n\n"
            . "CATALOGUE :\n" . Theme::catalogueCourtPourModele() . "\n\n"
            . "Réponds uniquement par un objet JSON de la forme :\n"
            . '{"themes": ["Finances publiques"]}' . "\n\n"
            . "--- TEXTE ---\n" . $extrait;

    }

    /**
     * @return list<Theme>
     */
    private function extraireThemes(string $reponse): array
    {
        $donnees = $this->decoder($reponse);
        if ($donnees === null) {
            return [];
        }

        $themes = [];
        foreach ($this->extraireLibelles($donnees) as $libelle) {
            $theme = Theme::fromLabel($libelle);
            if ($theme === null || \in_array($theme, $themes, true)) {
                continue;
            }

            $themes[] = $theme;

            if (\count($themes) >= self::MAX_THEMES) {
                break;
            }
        }

        return $themes;
    }

    /**
     * @return list<string>
     */
    private function extraireLibelles(mixed $donnees): array
    {
        if (!\is_array($donnees)) {
            return [];
        }

        foreach (['themes', 'theme', 'libelles', 'labels'] as $cle) {
            if (!\array_key_exists($cle, $donnees)) {
                continue;
            }

            $valeur = $donnees[$cle];
            if (\is_string($valeur)) {
                return [$valeur];
            }

            if (\is_array($valeur)) {
                return $this->extraireLibellesDeListe($valeur);
            }
        }

        if (array_is_list($donnees)) {
            return $this->extraireLibellesDeListe($donnees);
        }

        return [];
    }

    /**
     * @param array<mixed> $liste
     *
     * @return list<string>
     */
    private function extraireLibellesDeListe(array $liste): array
    {
        $libelles = [];

        foreach ($liste as $item) {
            if (\is_string($item)) {
                $libelles[] = $item;
                continue;
            }

            if (!\is_array($item)) {
                continue;
            }

            foreach (['theme', 'nom', 'libelle', 'label', 'name'] as $cle) {
                if (isset($item[$cle]) && \is_string($item[$cle])) {
                    $libelles[] = $item[$cle];
                    break;
                }
            }
        }

        return $libelles;
    }

    private function decoder(string $reponse): mixed
    {
        $brut = trim($reponse);
        if ($brut === '') {
            return null;
        }

        $donnees = json_decode($brut, true);
        if (\is_array($donnees)) {
            return $donnees;
        }

        // Le modèle a parfois glissé du texte autour du JSON : on extrait le bloc.
        if (preg_match('/\{.*\}/s', $brut, $correspondances) === 1) {
            $donnees = json_decode($correspondances[0], true);
            if (\is_array($donnees)) {
                return $donnees;
            }
        }

        return null;
    }
}
