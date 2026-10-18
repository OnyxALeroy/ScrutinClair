<?php

namespace App\Service;

/**
 * Transforme un texte législatif en vulgarisation grand public.
 *
 * Les textes longs dépassent la fenêtre de contexte du modèle : on
 * applique donc un « map-reduce » (résumé morceau par morceau, puis synthèse).
 */
final class Vulgarisateur
{
    private const SYSTEME = <<<'PROMPT'
Tu es un rédacteur spécialisé dans la vulgarisation de la vie politique française.
Tu expliques les textes de loi à un public qui n'a aucune connaissance juridique.
Règles : phrases courtes, vocabulaire simple, aucun jargon juridique, pas de listes
à puces interminables. Tu restes factuel et neutre politiquement. Tu écris en français.
N'utilise aucune syntaxe Markdown : pas de #, pas d'étoiles, pas de tirets de liste.
Écris uniquement des paragraphes de texte courant. Tu peux introduire un paragraphe
par un titre court terminé par un point, mais jamais avec des symboles.
PROMPT;

    /** Taille approximative d'un morceau, en caractères (~1 token ≈ 4 caractères). */
    private const TAILLE_MORCEAU = 16000;

    /** En dessous de cette taille, on appelle directement le modèle. */
    private const SEUIL_DIRECT = 14000;

    public function __construct(private readonly OllamaClient $ollama)
    {
    }

    public function modele(): string
    {
        return $this->ollama->modele();
    }

    /**
     * @param callable(int, int): void|null $onEtape reçoit ($morceauCourant, $totalMorceaux)
     */
    public function vulgariserTexteLoi(string $contenu, ?callable $onEtape = null): string
    {
        return $this->vulgariser(
            $contenu,
            $onEtape,
            "Voici le texte d'un projet ou d'une proposition de loi débattu à l'Assemblée nationale.",
            "Rédige une vulgarisation de ce texte pour le grand public en 200 à 350 mots. "
                . "Explique ce que la loi prévoit concrètement, pour qui, et pourquoi c'est important."
        );
    }

    /**
     * @param callable(int, int): void|null $onEtape
     */
    public function vulgariserAmendement(string $contenu, ?callable $onEtape = null): string
    {
        return $this->vulgariser(
            $contenu,
            $onEtape,
            <<<'PROMPT'
Voici le texte d'un amendement déposé à l'Assemblée nationale (dispositif et éventuel
exposé des motifs). Ton travail : expliquer au grand public ce que cet amendement change.
PROMPT,
            "Rédige 2 à 4 phrases claires expliquant ce que propose concrètement cet amendement. "
                . "Commence par « Cet amendement propose de… ». Pas de jargon, pas de préambule.",
        );
    }

    private function vulgariser(string $contenu, ?callable $onEtape, string $consigne, string $demande): string
    {
        $contenu = trim($contenu);
        if ($contenu === '') {
            throw new \InvalidArgumentException('Texte source vide.');
        }

        $morceaux = $this->decouper($contenu);

        if (\count($morceaux) === 1 || mb_strlen($contenu) <= self::SEUIL_DIRECT) {
            $this->notifier($onEtape, 1, 1);

            return $this->ollama->chat(
                self::SYSTEME,
                $consigne . "\n\n" . $demande . "\n\n--- TEXTE ---\n" . $contenu,
            );
        }

        $total = \count($morceaux);
        $resumes = [];
        foreach ($morceaux as $index => $morceau) {
            $this->notifier($onEtape, $index + 1, $total);
            $resumes[] = $this->ollama->chat(
                self::SYSTEME,
                sprintf(
                    "%s\n\nVoici la partie %d sur %d. Résume les points essentiels en 120 mots maximum, sans conclusion.\n\n--- EXTRAIT ---\n%s",
                    $consigne,
                    $index + 1,
                    $total,
                    $morceau,
                ),
            );
        }

        $this->notifier($onEtape, $total, $total);

        return $this->ollama->chat(
            self::SYSTEME,
            $demande
                . "\n\nVoici les résumés successifs des différentes parties du texte de loi. "
                . "Fusionne-les en un seul texte fluide et cohérent.\n\n--- RESUMES ---\n"
                . implode("\n\n", $resumes),
        );
    }

    /**
     * @return list<string>
     */
    private function decouper(string $contenu): array
    {
        if (mb_strlen($contenu) <= self::SEUIL_DIRECT) {
            return [$contenu];
        }

        $paragraphes = preg_split('/\n{2,}/u', $contenu) ?: [$contenu];
        $morceaux = [];
        $courant = '';

        foreach ($paragraphes as $paragraphe) {
            // Un paragraphe seul plus gros que la cible est découpé brutalement.
            if (mb_strlen($paragraphe) > self::TAILLE_MORCEAU) {
                if ($courant !== '') {
                    $morceaux[] = trim($courant);
                    $courant = '';
                }

                foreach (mb_str_split($paragraphe, self::TAILLE_MORCEAU) as $brut) {
                    $morceaux[] = trim($brut);
                }

                continue;
            }

            if (mb_strlen($courant) + mb_strlen($paragraphe) + 2 > self::TAILLE_MORCEAU && $courant !== '') {
                $morceaux[] = trim($courant);
                $courant = '';
            }

            $courant .= ($courant === '' ? '' : "\n\n") . $paragraphe;
        }

        if (trim($courant) !== '') {
            $morceaux[] = trim($courant);
        }

        return array_values(array_filter($morceaux, static fn (string $m): bool => $m !== ''));
    }

    private function notifier(?callable $onEtape, int $courant, int $total): void
    {
        if ($onEtape !== null) {
            $onEtape($courant, $total);
        }
    }
}
