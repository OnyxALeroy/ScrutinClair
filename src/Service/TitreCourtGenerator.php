<?php

namespace App\Service;

/**
 * Fabrique le titre court affiché au grand public pour un texte de loi.
 *
 * Le titre court a la forme « Sujet (article 4, amendement 1158) » :
 * le sujet est rédigé par l'IA, les références sont extraites du titre
 * officiel par règles (déterministe, jamais inventées). Si l'IA ne produit
 * rien d'exploitable, on retombe sur un raccourcissement par règles.
 * En dernier ressort, le titre court reste vide et l'interface affiche
 * le titre officiel complet.
 */
final class TitreCourtGenerator
{
    public const LONGUEUR_MAX = 70;
    public const LONGUEUR_TOLEREE = 90;

    private const SYSTEME = <<<'PROMPT'
    Tu rédiges des titres courts et clairs pour des textes de loi français,
    destinés au grand public (journal local, non juriste).
    Tu réponds uniquement par le titre : pas de guillemets, pas de point final,
    pas de commentaire, pas de marqueur de liste.
    PROMPT;

    /** Mots interdits dans le sujet : le code les ajoute lui-même. */
    private const BRUIT = ['n°', 'article', 'amendement', 'sous-amendement', 'première lecture', 'nouvelle lecture', 'seconde lecture', 'examen prioritaire'];

    /** Mots de liaison qui signalent une phrase inachevée. */
    private const MOTS_FINAUX_INTERDITS = [
        'dont', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'un', 'une',
        'à', 'au', 'aux', 'en', 'pour', 'par', 'sur', 'sous', 'avec', 'sans',
        'que', 'qui', 'quoi', 'où', 'et', 'ou', 'mais', 'car', 'afin', 'dans',
        'vers', 'chez', 'entre', 'contre', 'depuis', 'pendant', 'après', 'avant',
        'selon', 'lors', 'lorsque', 'quand', 'si', 'comme', 'toutefois', 'cependant',
        'néanmoins', 'or', 'donc', 'ni', 'y', 'd', 'qu', 'jusqu', 'lorsqu',
    ];

    public function __construct(private readonly OllamaClient $ollama)
    {
    }

    /**
     * @return ?string le titre court complet, null si rien d'exploitable
     */
    public function generer(string $titreOfficiel, ?string $dossier = null): ?string
    {
        $titreOfficiel = trim($titreOfficiel);
        if ($titreOfficiel === '') {
            return null;
        }

        [$reference, $budget, $budgetMax] = $this->referencesEtBudgets($titreOfficiel);

        $sujet = $this->demanderSujet($titreOfficiel, $dossier, $budget, $budgetMax);
        $sujet ??= $this->sujetParRegle($titreOfficiel);

        return $this->composer($sujet, $reference);
    }

    /**
     * Repli déterministe, sans appel à l'IA : sert à l'affichage pour les
     * textes dont le titre court n'a pas encore été généré.
     */
    public function genererSansIa(string $titreOfficiel): ?string
    {
        $titreOfficiel = trim($titreOfficiel);
        if ($titreOfficiel === '') {
            return null;
        }

        [$reference] = $this->referencesEtBudgets($titreOfficiel);

        return $this->composer($this->sujetParRegle($titreOfficiel), $reference);
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function referencesEtBudgets(string $titreOfficiel): array
    {
        $reference = $this->references($titreOfficiel);
        $budget = $reference === ''
            ? self::LONGUEUR_MAX
            : self::LONGUEUR_MAX - mb_strlen($reference) - 4;
        $budgetMax = $reference === ''
            ? self::LONGUEUR_TOLEREE
            : self::LONGUEUR_TOLEREE - mb_strlen($reference) - 4;

        if ($budget < 16) {
            return ['', self::LONGUEUR_MAX, self::LONGUEUR_TOLEREE];
        }

        return [$reference, $budget, $budgetMax];
    }

    private function composer(?string $sujet, string $reference): ?string
    {
        if ($sujet === null || trim($sujet) === '') {
            return null;
        }

        $sujet = $this->capitaliser(trim($sujet));
        if (!$this->sujetComplet($sujet)) {
            return null;
        }

        return $reference === '' ? $sujet : $sujet . ' (' . $reference . ')';
    }

    /**
     * Les références législatives (article, amendement), extraites par
     * expression régulière du titre officiel.
     */
    public function references(string $titre): string
    {
        $parties = [];

        if (preg_match("/(?:^|\\W)(?:l['\x{2019}\x{0060}]?)?articles?\\s+(premier|1er|\\d+(?:\\s+(?:bis|ter|quater|quinquies|sexies|septies|octies|novies|decies))?)/iu", $titre, $m) === 1) {
            $valeur = mb_strtolower($m[1]);
            if (\in_array($valeur, ['premier', '1er'], true)) {
                $valeur = '1';
            }
            $parties[] = 'article ' . $valeur;
        }

        if (preg_match('/n[°o]\s*(\d+)/u', $titre, $m) === 1) {
            $parties[] = 'amendement ' . $m[1];
        }

        return implode(', ', $parties);
    }

    private function demanderSujet(string $titreOfficiel, ?string $dossier, int $budget, int $budgetMax): ?string
    {
        $consigne = "Voici le titre officiel d'un texte de loi ou d'un amendement de l'Assemblée nationale :\n"
            . '« ' . $titreOfficiel . ' »';
        if ($dossier !== null && trim($dossier) !== '') {
            $consigne .= "\nDossier législatif auquel il appartient : " . trim($dossier);
        }

        $consigne .= "\n\nRédige un titre court qui résume le sujet de ce texte : "
            . 'entre 3 et 8 mots, vise ' . $budget . ' caractères (maximum ' . $budgetMax . '), '
            . 'sans guillemets ni point final. '
            . 'Le titre doit être une phrase complète et autonome : ne le termine jamais par un '
            . 'mot de liaison (de, du, des, la, le, les, à, au, aux, dont, que, qui, pour, entre…). '
            . "Ne mentionne ni le nom d'un auteur, ni le numéro d'un amendement ou d'un article, "
            . 'ni la phase de lecture : le code ajoute ces références après coup. '
            . "Le sujet doit se lire comme le titre d'un article de presse. "
            . 'Réponds uniquement par le titre.';

        for ($essai = 0; $essai < 2; ++$essai) {
            try {
                $reponse = $this->ollama->chat(self::SYSTEME, $consigne, [
                    'temperature' => $essai === 0 ? 0.2 : 0,
                    'num_predict' => 90,
                ]);
            } catch (\Throwable) {
                return null;
            }

            $sujet = $this->nettoyer($reponse, $budgetMax);
            if ($sujet !== null) {
                return $sujet;
            }

            $consigne .= "\nTa réponse précédente était inexploitable : elle dépassait "
                . $budgetMax . ' caractères, se terminait par un mot de liaison ou contenait une référence. '
                . 'Raccourcis-la en une phrase complète de 3 à 8 mots, sans mot de liaison final. '
                . 'Réponds par le seul sujet, sans rien d\'autre.';
        }

        return null;
    }

    private function nettoyer(string $reponse, int $budgetMax): ?string
    {
        $sujet = trim($reponse);

        // Encadrement résiduel (« Titre : … », guillemets, listes Markdown).
        $sujet = (string) preg_replace('/^(?:titre|titre court|titre court proposé)\s*[:\-–]\s*/iu', '', $sujet);
        $sujet = (string) preg_replace('/^(?:#+\s*|\*\*|\*|\d+\.\s*|-\s*)/u', '', $sujet);
        $sujet = trim($sujet, " \t\n\r\0\x0B\"“”«»'’`*_");
        $sujet = rtrim($sujet, " \t\n\r\0\x0B.,;:…—-–");
        $sujet = trim($sujet);

        if (mb_strlen($sujet) < 4 || mb_strlen($sujet) > $budgetMax) {
            return null;
        }

        $minuscules = mb_strtolower($sujet);
        foreach (self::BRUIT as $mot) {
            if (preg_match('/\\b' . preg_quote($mot, '/') . '/iu', $minuscules) === 1) {
                return null;
            }
        }

        return $this->sujetComplet($sujet) ? $sujet : null;
    }

    /**
     * Vérifie que le sujet se termine par un mot plein, pas par un mot de
     * liaison : une phrase coupée en plein milieu est rejetée.
     */
    private function sujetComplet(string $sujet): bool
    {
        $sujet = trim($sujet);
        if ($sujet === '') {
            return false;
        }

        if (preg_match('/[.,;:…\-–—]$/u', $sujet) === 1) {
            return false;
        }

        $mots = preg_split('/\s+/u', $sujet) ?: [];
        $dernier = mb_strtolower((string) end($mots));
        if (str_ends_with($dernier, "'") || str_ends_with($dernier, '’')) {
            return false;
        }

        $dernier = trim($dernier, "'’");

        return !\in_array($dernier, self::MOTS_FINAUX_INTERDITS, true);
    }

    /**
     * Repli déterministe : on coupe le titre officiel au dernier marqueur
     * introduisant le sujet, puis on nettoie les mentions de lecture.
     */
    private function sujetParRegle(string $titre): ?string
    {
        $reste = (string) preg_replace(
            '/\s*\((?:première|nouvelle|seconde|deuxième)\s+lecture\)\.?|\s*\(examen prioritaire\)\.?|\s*\(rect(?:\.|ificatif)[^)]*\)\.?|\s*\(supprimé\)\.?/iu',
            '',
            $titre,
        );
        $reste = rtrim(trim($reste), '.;, ');

        $marqueurs = [
            'visant à ', 'visant la ', 'visant le ', 'visant les ', 'visant ',
            'relative à ', 'relatif à ', 'relative au ', 'relatif au ',
            'relative aux ', 'relatif aux ',
            'portant sur ', 'portant ', 'concernant ',
            'du projet de loi de ', 'de la proposition de loi de ',
            'du projet de loi portant ', 'de la proposition de loi visant ',
            'du projet de loi ', 'de la proposition de loi ',
        ];

        $position = -1;
        $longueur = 0;
        foreach ($marqueurs as $marqueur) {
            $trouve = mb_strripos($reste, $marqueur);
            if ($trouve !== false && $trouve > $position) {
                $position = $trouve;
                $longueur = mb_strlen($marqueur);
            }
        }

        if ($position < 0) {
            return null;
        }

        $sujet = trim(mb_substr($reste, $position + $longueur));
        $sujet = (string) preg_replace('/^(?:la |le |l\x27|les |un |une |des |du |de |d\x27)/iu', '', $sujet);

        return trim($sujet) === '' ? null : $sujet;
    }

    /**
     * Met une majuscule initiale : le modèle prolonge parfois la phrase du
     * titre officiel (« … visant à simplifier » → « simplifier … »).
     */
    private function capitaliser(string $sujet): string
    {
        $initiale = mb_substr($sujet, 0, 1);

        return mb_strtoupper($initiale) . mb_substr($sujet, 1);
    }

}
