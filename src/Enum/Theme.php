<?php

namespace App\Enum;

use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\UnicodeString;

/**
 * Catalogue fermé des thèmes politiques, pour classer les textes de loi.
 *
 * C'est la source de vérité : le prompt envoyé au modèle est construit à
 * partir de ce catalogue, et toute réponse qui ne correspond pas à un thème
 * connu est ignorée.
 */
enum Theme: string
{
    case FinancesPubliques = 'Finances publiques';
    case Fiscalite = 'Fiscalité';
    case Economie = 'Économie & entreprises';
    case Travail = 'Travail & emploi';
    case Sante = 'Santé';
    case Education = 'Éducation';
    case Recherche = 'Recherche & innovation';
    case EnseignementSuperieur = 'Enseignement supérieur';
    case Culture = 'Culture';
    case Sports = 'Sports';
    case Ecologie = 'Écologie';
    case Energie = 'Énergie';
    case Transports = 'Transports';
    case Logement = 'Logement';
    case Agriculture = 'Agriculture & alimentation';
    case Justice = 'Justice';
    case SecuriteInterieure = 'Sécurité intérieure';
    case Defense = 'Défense & armées';
    case Famille = 'Famille & enfance';
    case Handicap = 'Handicap';
    case Numerique = 'Numérique';
    case Collectivites = 'Collectivités territoriales';
    case RelationsInternationales = 'Relations internationales';

    public function label(): string
    {
        return $this->value;
    }

    public function couleur(): string
    {
        return match ($this) {
            self::FinancesPubliques => '#4f46e5',
            self::Fiscalite => '#7c3aed',
            self::Economie => '#0ea5e9',
            self::Travail => '#f08c00',
            self::Sante => '#e64980',
            self::Education => '#f59e0b',
            self::Recherche => '#8b5cf6',
            self::EnseignementSuperieur => '#a855f7',
            self::Culture => '#d946ef',
            self::Sports => '#22c55e',
            self::Ecologie => '#16a34a',
            self::Energie => '#65a30d',
            self::Transports => '#0891b2',
            self::Logement => '#ca8a04',
            self::Agriculture => '#84cc16',
            self::Justice => '#64748b',
            self::SecuriteInterieure => '#334155',
            self::Defense => '#475569',
            self::Famille => '#ec4899',
            self::Handicap => '#06b6d4',
            self::Numerique => '#3b82f6',
            self::Collectivites => '#14b8a6',
            self::RelationsInternationales => '#4338ca',
        };
    }

    /**
     * Éclaircissement donné au modèle pour l'aider à trancher.
     */
    public function description(): string
    {
        return match ($this) {
            self::FinancesPubliques => "budget de l'État, dette publique, comptes publics, finances des collectivités",
            self::Fiscalite => "impôts, TVA, fiscalité des entreprises et des ménages, redevances",
            self::Economie => "concurrence, entreprises, PME, régulation économique, pouvoir d'achat",
            self::Travail => "code du travail, licenciement, salaires, dialogue social, formation professionnelle",
            self::Sante => "assurance maladie, hôpitaux, médicaments, santé publique, professions de santé",
            self::Education => "école primaire et secondaire, programmes scolaires, enseignants, rentrée scolaire",
            self::Recherche => "recherche publique, laboratoires, financement de la recherche, innovation",
            self::EnseignementSuperieur => "universités, licences et diplômes, étudiants, parcours de formation",
            self::Culture => "cinéma, musique, patrimoine culturel, bibliothèques, spectacles",
            self::Sports => "sport amateur, sport professionnel, Jeux olympiques, clubs et fédérations",
            self::Ecologie => "biodiversité, climat, pollutions, espaces naturels, protection de l'environnement",
            self::Energie => "électricité, gaz, énergies renouvelables, sobriété énergétique, énergie nucléaire",
            self::Transports => "transports en commun, route, ferroviaire, aviation, mobilités",
            self::Logement => "logement social, location, accession à la propriété, habitat, métropole",
            self::Agriculture => "agriculture, élevage, pêche, alimentation, sécurité sanitaire",
            self::Justice => "tribunaux, procès, lois pénales, civils, pénitentiaire",
            self::SecuriteInterieure => "police, gendarmerie, vidéosurveillance, ordre public, sécurité administrative",
            self::Defense => "armées, défense nationale, budget militaire, opérations extérieures",
            self::Famille => "enfance, natalité, aides aux familles, parenting, femmes",
            self::Handicap => "handicap, personnes handicapées, inclusion, accessibilité",
            self::Numerique => "numérique, internet, intelligence artificielle, données personnelles, plateformes",
            self::Collectivites => "communes, départements, régions, intercommunalités, décentralisation",
            self::RelationsInternationales => "diplomatie, unions européennes, aide au développement, sanctions internationales",
        };
    }

    public function slug(): string
    {
        return (new AsciiSlugger())->slug($this->value)->lower()->toString();
    }

    /**
     * Retrouve un thème à partir d'un libellé libre (réponse du modèle).
     *
     * La correspondance ignore la casse, les accents et la ponctuation, et
     * accepte un libellé abrégé (« Finances » pour « Finances publiques »)
     * à condition qu'il n'identifie qu'un seul thème.
     */
    public static function fromLabel(string $label): ?self
    {
        $normalise = self::normaliser($label);
        if ($normalise === '') {
            return null;
        }

        foreach (self::cases() as $theme) {
            if (self::normaliser($theme->value) === $normalise) {
                return $theme;
            }
        }

        $candidats = array_filter(
            self::cases(),
            static fn (self $theme): bool => str_starts_with(self::normaliser($theme->value), $normalise),
        );

        return \count($candidats) === 1 ? reset($candidats) : null;
    }

    /**
     * @return list<string>
     */
    public static function libelles(): array
    {
        return array_map(static fn (self $theme): string => $theme->value, self::cases());
    }

    /**
     * Catalogue mis en forme pour le prompt : un thème par ligne, avec son
     * éclaircissement.
     */
    public static function cataloguePourModele(): string
    {
        $lignes = [];
        foreach (self::cases() as $theme) {
            $lignes[] = sprintf('- %s : %s', $theme->value, $theme->description());
        }

        return implode("\n", $lignes);
    }

    /**
     * Même catalogue, mais avec deux mots-clés par thème : le prompt est
     * pré-rempli à chaque appel, autant qu'il soit court.
     */
    public static function catalogueCourtPourModele(): string
    {
        $lignes = [];
        foreach (self::cases() as $theme) {
            $motsCles = array_slice(explode(',', $theme->description()), 0, 2);
            $lignes[] = sprintf('- %s : %s', $theme->value, implode(', ', $motsCles));
        }

        return implode("\n", $lignes);
    }

    private static function normaliser(string $valeur): string
    {
        $ascii = (new UnicodeString($valeur))
            ->ascii()
            ->replace('&', ' et ')
            ->lower()
            ->toString();

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }
}
