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
Commence directement par l'explication : aucun préambule, aucune formule du type
« Voici une reformulation… », aucun rappel de la consigne ni du nombre de mots.
Règle la plus importante : tu ne recopies jamais le texte source. Tu le reformules
avec tes propres mots, comme si tu l'expliquais à un ami. Tu ne cites aucun numéro
d'article, aucune référence de code, aucun « article 1er ».
PROMPT;

    /** Préambules que les petits modèles ajoutent parfois avant le vrai contenu. */
    private const PREAMBULES = [
        '/^voici\s+(?:un\s+|une\s+|le\s+|la\s+|les\s+)?(?:proposition\s+de\s+)?(?:reformulation|vulgarisation|réduction|rédaction|version\s+(?:simplifiée|vulgarisée)|résumé|synthèse|texte\s+(?:reformulé|vulgarisé))[^.\n]{0,200}?[:.]\s*/iu',
        '/^voici\s+les?\s+(?:principaux?\s+)?(?:points?|éléments?|enseignements?|résumés?|synthèses?)[^.\n]{0,200}?[:.]\s*/iu',
        '/^(?:un\s+|une\s+|le\s+|la\s+|les\s+|cette\s+)?(?:reformulation|vulgarisation|réduction|rédaction|version\s+(?:simplifiée|vulgarisée)|résumé|synthèse)[^.\n]{0,200}?[:.]\s*/iu',
        '/^pour\s+le\s+grand\s+public\s*[:.]\s*/iu',
        '/^(?:en\s+)?\d{2,4}\s*(?:à|et|-|–)\s*\d{2,4}\s*mots?[^.\n]{0,80}?[:.]\s*/iu',
    ];

    /**
     * Exemple de vulgarisation : les petits modèles recopient le texte source
     * s'ils n'ont pas d'exemple sous les yeux.
     */
    private const EXEMPLE = <<<'PROMPT'
Exemple de vulgarisation attendue :
« Cette loi crée un dispositif d'aide pour les entreprises de moins de dix salariés.
Chaque entreprise créée après 2024 reçoit 1 500 euros par salarié pendant deux ans
pour l'aider à embaucher. Seules les entreprises de moins de dix personnes en
bénéficient, et l'aide est versée pendant deux ans. »
PROMPT;

    /** Taille approximative d'un morceau, en caractères (~1 token ≈ 4 caractères). */
    private const TAILLE_MORCEAU = 10000;

    /** En dessous de cette taille, on appelle directement le modèle. */
    private const SEUIL_DIRECT = 9000;

    /**
     * On ne vulgarise que le début du texte de loi : les lois analysed
     * dépassent les 500 000 caractères, et les traiter en entier coûterait
     * des heures de calcul pour un résultat équivalent.
     */
    private const TAILLE_SOURCE_MAX = 30000;

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
            mb_substr($contenu, 0, self::TAILLE_SOURCE_MAX),
            $onEtape,
            "Voici le texte d'un projet ou d'une proposition de loi débattu à l'Assemblée nationale.",
            "Écris entre 200 et 350 mots pour le grand public. "
                . "Explique ce que la loi prévoit concrètement, pour qui, et pourquoi c'est important. "
                . "Reformule avec tes propres mots, sans citer les articles. "
                . "Commence directement par l'explication, sans préambule ni formule du type « Voici… »."
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
            300,
        );
    }

    private function vulgariser(string $contenu, ?callable $onEtape, string $consigne, string $demande, int $numPredict = 700): string
    {
        $contenu = trim($contenu);
        if ($contenu === '') {
            throw new \InvalidArgumentException('Texte source vide.');
        }

        $morceaux = $this->decouper($contenu);

        if (\count($morceaux) === 1 || mb_strlen($contenu) <= self::SEUIL_DIRECT) {
            $this->notifier($onEtape, 1, 1);

            return $this->reformuler($consigne, $demande, $contenu, $numPredict);
        }

        $total = \count($morceaux);
        $resumes = [];
        foreach ($morceaux as $index => $morceau) {
            $this->notifier($onEtape, $index + 1, $total);
            $resumes[] = $this->nettoyerSortie($this->ollama->chat(
                self::SYSTEME,
                sprintf(
                    "%s\n\nVoici la partie %d sur %d. Résume les points essentiels en 120 mots maximum, sans conclusion ni préambule.\n\n--- EXTRAIT ---\n%s",
                    $consigne,
                    $index + 1,
                    $total,
                    $morceau,
                ),
                ['num_predict' => 280],
            ));
        }

        $this->notifier($onEtape, $total, $total);

        return $this->reformuler(
            $demande . "\n\nVoici les résumés successifs des différentes parties du texte de loi. "
                . "Fusionne-les en un seul texte fluide et cohérent.\n\n--- RESUMES ---\n"
                . implode("\n\n", $resumes),
            self::SYSTEME,
            implode("\n\n", $resumes),
            700,
        );
    }

    /**
     * Demande une reformulation et la reteste : les petits modèles recopient
     * parfois le texte source, auquel cas on les oblige à recommencer.
     */
    private function reformuler(string $consigne, string $demande, string $source, int $numPredict): string
    {
        $sortie = $this->nettoyerSortie($this->ollama->chat(
            self::SYSTEME,
            $consigne . "\n\n" . $demande . "\n\n--- TEXTE ---\n" . $source,
            ['num_predict' => $numPredict],
        ));

        if (!$this->estRecopie($sortie, $source)) {
            return $sortie;
        }

        return $this->nettoyerSortie($this->ollama->chat(
            self::SYSTEME,
            $consigne . "\n\n" . $demande
                . "\n\nATTENTION : ta précédente réponse recopiait le texte source. "
                . "Explique le texte avec tes propres mots, dans un style simple, "
                . "sans reprendre les phrases d'origine.\n\n--- TEXTE ---\n" . $source,
            ['num_predict' => $numPredict],
        ));
    }

    /**
     * Retire les formules d'introduction que le modèle ajoute parfois
     * (« Voici une reformulation… : », « En 200 à 350 mots… », etc.).
     */
    private function nettoyerSortie(string $sortie): string
    {
        $original = trim($sortie);
        $texte = $this->retirerGuillemets($original);

        for ($tour = 0; $tour < 4; ++$tour) {
            $avant = $texte;

            $texte = preg_replace('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*/u', '', $texte) ?? $texte;
            $texte = preg_replace('/^\s*(?:[#>*_]+|-\s+)+/u', '', $texte) ?? $texte;
            $texte = trim($texte);

            foreach (self::PREAMBULES as $motif) {
                $nettoye = preg_replace($motif, '', $texte, 1) ?? $texte;
                if ($nettoye !== $texte) {
                    $texte = ltrim($nettoye);
                    continue 2;
                }
            }

            if ($texte === $avant) {
                break;
            }
        }

        $texte = $this->retirerGuillemets(trim($texte));

        return $texte !== '' ? $texte : $original;
    }

    private function retirerGuillemets(string $texte): string
    {
        $texte = trim($texte);
        if (preg_match('/^[«"“](.+)[»"”]$/us', $texte, $m) === 1) {
            return trim($m[1]);
        }

        return $texte;
    }

    /**
     * Vrai si la sortie reprend essentiellement les mots du texte source :
     * le modèle a alors recopié au lieu de vulgariser.
     */
    private function estRecopie(string $sortie, string $source): bool
    {
        $mots = static function (string $texte): array {
            return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texte), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        };

        $motsSource = array_flip(array_unique($mots($source)));
        $motsSortie = $mots($sortie);

        if ($motsSortie === [] || $motsSource === []) {
            return false;
        }

        $communs = 0;
        foreach ($motsSortie as $mot) {
            if (isset($motsSource[$mot])) {
                ++$communs;
            }
        }

        return $communs / \count($motsSortie) > 0.85;
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
