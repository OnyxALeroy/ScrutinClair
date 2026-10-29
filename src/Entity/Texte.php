<?php

namespace App\Entity;

use App\Enum\Chambre;
use App\Repository\TexteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TexteRepository::class)]
#[ORM\Index(name: 'idx_texte_chambre_date', columns: ['chambre', 'date_scrutin'])]
class Texte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $synthese = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lien = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $dossierRef = null;

    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $uidScrutin = null;

    #[ORM\Column(length: 8, enumType: Chambre::class, options: ['default' => 'AN'])]
    private Chambre $chambre = Chambre::AssembleeNationale;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateScrutin = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $resultat = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $nonVotants = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $vulgarisation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vulgarisationSource = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $vulgarisationAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vulgarisationStatut = null;

    /**
     * Titre court destiné au grand public, rédigé par l'IA.
     * Le titre officiel complet reste dans « titre » / « objet ».
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $titreCourt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $titreCourtAt = null;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'texte', orphanRemoval: true)]
    private Collection $votes;

    /**
     * Thèmes politiques attribués par l'IA lors de la vulgarisation.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'textes')]
    #[ORM\JoinTable(name: 'texte_tag')]
    private Collection $themes;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
        $this->themes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = $objet;

        return $this;
    }

    public function getSynthese(): ?string
    {
        return $this->synthese;
    }

    public function setSynthese(?string $synthese): static
    {
        $this->synthese = $synthese;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(?string $lien): static
    {
        $this->lien = $lien;

        return $this;
    }

    public function getDossierRef(): ?string
    {
        return $this->dossierRef;
    }

    public function setDossierRef(?string $dossierRef): static
    {
        $this->dossierRef = $dossierRef;

        return $this;
    }

    public function getUidScrutin(): ?string
    {
        return $this->uidScrutin;
    }

    public function setUidScrutin(?string $uidScrutin): static
    {
        $this->uidScrutin = $uidScrutin;

        return $this;
    }

    public function getChambre(): Chambre
    {
        return $this->chambre;
    }

    public function setChambre(Chambre $chambre): static
    {
        $this->chambre = $chambre;

        return $this;
    }

    public function getDateScrutin(): ?\DateTimeImmutable
    {
        return $this->dateScrutin;
    }

    public function setDateScrutin(?\DateTimeImmutable $dateScrutin): static
    {
        $this->dateScrutin = $dateScrutin;

        return $this;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function setResultat(?string $resultat): static
    {
        $this->resultat = $resultat;

        return $this;
    }

    public function getNonVotants(): ?int
    {
        return $this->nonVotants;
    }

    public function setNonVotants(?int $nonVotants): static
    {
        $this->nonVotants = $nonVotants;

        return $this;
    }

    public function getVulgarisation(): ?string
    {
        return $this->vulgarisation;
    }

    public function setVulgarisation(?string $vulgarisation): static
    {
        $this->vulgarisation = $vulgarisation;

        return $this;
    }

    public function getVulgarisationSource(): ?string
    {
        return $this->vulgarisationSource;
    }

    public function setVulgarisationSource(?string $vulgarisationSource): static
    {
        $this->vulgarisationSource = $vulgarisationSource;

        return $this;
    }

    public function getVulgarisationAt(): ?\DateTimeImmutable
    {
        return $this->vulgarisationAt;
    }

    public function setVulgarisationAt(?\DateTimeImmutable $vulgarisationAt): static
    {
        $this->vulgarisationAt = $vulgarisationAt;

        return $this;
    }

    public function getVulgarisationStatut(): ?string
    {
        return $this->vulgarisationStatut;
    }

    public function setVulgarisationStatut(?string $vulgarisationStatut): static
    {
        $this->vulgarisationStatut = $vulgarisationStatut;

        return $this;
    }

    public function getTitreCourt(): ?string
    {
        return $this->titreCourt;
    }

    public function setTitreCourt(?string $titreCourt): static
    {
        $this->titreCourt = $titreCourt;

        return $this;
    }

    public function getTitreCourtAt(): ?\DateTimeImmutable
    {
        return $this->titreCourtAt;
    }

    public function setTitreCourtAt(?\DateTimeImmutable $titreCourtAt): static
    {
        $this->titreCourtAt = $titreCourtAt;

        return $this;
    }

    /**
     * Titre officiel complet, tel qu'il figure dans le scrutin.
     */
    public function getTitreLong(): string
    {
        $objet = trim((string) $this->objet);
        if ($objet !== '') {
            return $objet;
        }

        $titre = trim((string) $this->titre);

        return $titre !== '' ? $titre : 'Texte sans titre';
    }

    /**
     * Titre à afficher au public : le titre court s'il existe, sinon le
     * titre officiel complet.
     */
    public function getTitreAffiche(): string
    {
        $court = trim((string) $this->titreCourt);

        return $court !== '' ? $court : $this->getTitreLong();
    }

    /**
     * @return Collection<int, Vote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): static
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
            $vote->setTexte($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            // set the owning side to null (unless already changed)
            if ($vote->getTexte() === $this) {
                $vote->setTexte(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getThemes(): Collection
    {
        return $this->themes;
    }

    public function addTheme(Tag $theme): static
    {
        if (!$this->themes->contains($theme)) {
            $this->themes->add($theme);
            $theme->addTexte($this);
        }

        return $this;
    }

    public function removeTheme(Tag $theme): static
    {
        if ($this->themes->removeElement($theme)) {
            $theme->removeTexte($this);
        }

        return $this;
    }

    /**
     * Remplace l'ensemble des thèmes du texte.
     *
     * @param iterable<Tag> $themes
     */
    public function setThemes(iterable $themes): static
    {
        foreach ($this->themes->toArray() as $theme) {
            $this->removeTheme($theme);
        }

        foreach ($themes as $theme) {
            $this->addTheme($theme);
        }

        return $this;
    }

    /**
     * Prépare les données de l'hémicycle : un arc par groupe, découpé en
     * segments Pour / Contre / Abstention, avec le tracé SVG prêt à l'emploi.
     *
     * @return array{total: int, pour: int, contre: int, abstention: int, groupes: list<array<string, mixed>>}
     */
    public function getHemicyclePolitique(): array
    {
        $votesParGroupe = [];
        $totalVotes = 0;
        $totaux = ['Pour' => 0, 'Contre' => 0, 'Abstention' => 0];

        foreach ($this->getVotes() as $vote) {
            $groupe = $vote->getGroupePolitique();
            if (!$groupe) {
                continue;
            }

            $uid = $groupe->getUid();

            if (!isset($votesParGroupe[$uid])) {
                $votesParGroupe[$uid] = [
                    'groupe' => $groupe,
                    'Pour' => 0,
                    'Contre' => 0,
                    'Abstention' => 0,
                    'total' => 0,
                ];
            }

            $statut = $vote->getStatut();
            if (isset($votesParGroupe[$uid][$statut])) {
                $votesParGroupe[$uid][$statut]++;
                $votesParGroupe[$uid]['total']++;
                $totalVotes++;
                $totaux[$statut]++;
            }
        }

        if ($totalVotes === 0) {
            return [
                'total' => 0,
                'pour' => 0,
                'contre' => 0,
                'abstention' => 0,
                'groupes' => [],
            ];
        }

        usort(
            $votesParGroupe,
            static fn (array $a, array $b): int => $a['groupe']->getPositionPolitique() <=> $b['groupe']->getPositionPolitique(),
        );

        // Géométrie du demi-disque : centre en bas au milieu, de 180° (gauche)
        // à 0° (droite). Chaque groupe occupe un arc proportionnel à ses votants.
        $centreX = 200.0;
        $centreY = 200.0;
        $rayonInterne = 120.0;
        $rayonExterne = 190.0;
        $ecart = 0.9; // léger espace entre deux groupes, en degrés
        $couleurs = ['Pour' => '#22c55e', 'Contre' => '#ef4444', 'Abstention' => '#94a3b8'];

        $angle = 180.0;
        $groupes = [];

        foreach ($votesParGroupe as $donnees) {
            if ($donnees['total'] === 0) {
                continue;
            }

            $part = ($donnees['total'] / $totalVotes) * 180.0;
            $debut = $angle;
            $fin = $angle - $part;
            $angle = $fin;

            // On rentre les bords de l'arc pour ménager un espace entre groupes.
            $marge = min($ecart / 2, $part / 4);
            $curseur = $debut - $marge;
            $limite = $fin + $marge;

            $segments = [];
            foreach (['Pour', 'Contre', 'Abstention'] as $statut) {
                $nombre = $donnees[$statut];
                if ($nombre === 0) {
                    continue;
                }

                $sousFin = $curseur - ($nombre / $donnees['total']) * ($curseur - $limite);

                $segments[] = [
                    'statut' => $statut,
                    'nombre' => $nombre,
                    'couleur' => $couleurs[$statut],
                    'path' => $this->anneauSegment($centreX, $centreY, $rayonInterne, $rayonExterne, $curseur, $sousFin),
                ];

                $curseur = $sousFin;
            }

            $groupes[] = [
                'nom' => $donnees['groupe']->getAcronyme() ?? $donnees['groupe']->getNom(),
                'nom_complet' => $donnees['groupe']->getNom(),
                'total' => $donnees['total'],
                'pour' => $donnees['Pour'],
                'contre' => $donnees['Contre'],
                'abstention' => $donnees['Abstention'],
                'segments' => $segments,
            ];
        }

        return [
            'total' => $totalVotes,
            'pour' => $totaux['Pour'],
            'contre' => $totaux['Contre'],
            'abstention' => $totaux['Abstention'],
            'groupes' => $groupes,
        ];
    }

    /**
     * Tracé SVG d'un segment d'anneau (entre deux angles, dans le sens horaire).
     */
    private function anneauSegment(float $cx, float $cy, float $rIn, float $rOut, float $angleDebut, float $angleFin): string
    {
        [$xExtDebut, $yExtDebut] = $this->pointPolaire($cx, $cy, $rOut, $angleDebut);
        [$xExtFin, $yExtFin] = $this->pointPolaire($cx, $cy, $rOut, $angleFin);
        [$xIntFin, $yIntFin] = $this->pointPolaire($cx, $cy, $rIn, $angleFin);
        [$xIntDebut, $yIntDebut] = $this->pointPolaire($cx, $cy, $rIn, $angleDebut);

        return sprintf(
            'M %s %s A %s %s 0 0 1 %s %s L %s %s A %s %s 0 0 0 %s %s Z',
            $xExtDebut,
            $yExtDebut,
            $rOut,
            $rOut,
            $xExtFin,
            $yExtFin,
            $xIntFin,
            $yIntFin,
            $rIn,
            $rIn,
            $xIntDebut,
            $yIntDebut,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function pointPolaire(float $cx, float $cy, float $rayon, float $angleDeg): array
    {
        $angle = deg2rad($angleDeg);

        return [
            round($cx + $rayon * cos($angle), 2),
            round($cy - $rayon * sin($angle), 2),
        ];
    }
}
