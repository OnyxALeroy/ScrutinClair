<?php

namespace App\Entity;

use App\Repository\GroupePolitiqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupePolitiqueRepository::class)]
class GroupePolitique
{
    /**
     * Position des groupes de l'Assemblée nationale sur l'axe gauche-droite
     * (17e législature). Sert à ordonner les groupes sur l'hémicycle : de la
     * gauche du diagramme (petites valeurs) vers sa droite (grandes valeurs).
     */
    private const POSITIONS_POLITIQUES = [
        'LFI-NFP' => 10,
        'GDR' => 20,
        'ECOS' => 30,
        'SOC' => 40,
        'LIOT' => 50,
        'DEM' => 60,
        'EPR' => 70,
        'HOR' => 80,
        'DR' => 90,
        'UDDPLR' => 100,
        'RN' => 110,
        'NI' => 120,
    ];

    /**
     * Couleur d'affichage des groupes (17e législature), par acronyme.
     */
    private const COULEURS = [
        'LFI-NFP' => '#cc3344',
        'GDR' => '#a83232',
        'ECOS' => '#2f9e44',
        'SOC' => '#e64980',
        'LIOT' => '#d9a441',
        'DEM' => '#f08c00',
        'EPR' => '#4c6ef5',
        'HOR' => '#22b8cf',
        'DR' => '#1c7ed6',
        'UDDPLR' => '#364fc7',
        'RN' => '#1a3a6b',
        'NI' => '#868e96',
    ];

    public static function couleurPour(?string $acronyme): string
    {
        return self::COULEURS[$acronyme ?? ''] ?? '#9ca3af';
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $uid = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $acronyme = null;

    #[ORM\Column(length: 20)]
    private ?string $couleur = null;

    #[ORM\Column]
    private ?int $ordre = null;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'groupePolitique')]
    private Collection $votes;

    /**
     * @var Collection<int, PersonnalitePolitique>
     */
    #[ORM\OneToMany(targetEntity: PersonnalitePolitique::class, mappedBy: 'groupePolitique')]
    private Collection $personnalites;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
        $this->personnalites = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string $uid): static
    {
        $this->uid = $uid;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getAcronyme(): ?string
    {
        return $this->acronyme;
    }

    public function setAcronyme(?string $acronyme): static
    {
        $this->acronyme = $acronyme;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(string $couleur): static
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    /**
     * Rang du groupe sur l'axe gauche-droite. Les groupes politiques connus
     * sont classés explicitement ; à défaut on retombe sur la colonne `ordre`
     * puis, en dernier recours, tout à droite du diagramme.
     */
    public function getPositionPolitique(): int
    {
        return self::POSITIONS_POLITIQUES[$this->acronyme ?? ''] ?? ($this->ordre ?? 999);
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
            $vote->setGroupePolitique($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            // set the owning side to null (unless already changed)
            if ($vote->getGroupePolitique() === $this) {
                $vote->setGroupePolitique(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PersonnalitePolitique>
     */
    public function getPersonnalites(): Collection
    {
        return $this->personnalites;
    }

    public function addPersonnalite(PersonnalitePolitique $personnalite): static
    {
        if (!$this->personnalites->contains($personnalite)) {
            $this->personnalites->add($personnalite);
            $personnalite->setGroupePolitique($this);
        }

        return $this;
    }

    public function removePersonnalite(PersonnalitePolitique $personnalite): static
    {
        if ($this->personnalites->removeElement($personnalite)) {
            if ($personnalite->getGroupePolitique() === $this) {
                $personnalite->setGroupePolitique(null);
            }
        }

        return $this;
    }

    /**
     * Nom d'affichage du groupe (acronyme s'il existe, sinon libellé complet).
     */
    public function getLibelleCourt(): string
    {
        return $this->acronyme ?? $this->nom ?? '';
    }
}
