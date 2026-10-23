<?php

namespace App\Entity;

use App\Repository\GouvernementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GouvernementRepository::class)]
class Gouvernement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $uid = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    /**
     * @var Collection<int, MembreGouvernement>
     */
    #[ORM\OneToMany(targetEntity: MembreGouvernement::class, mappedBy: 'gouvernement', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['preseance' => 'ASC'])]
    private Collection $membres;

    public function __construct()
    {
        $this->membres = new ArrayCollection();
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

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    /**
     * @return Collection<int, MembreGouvernement>
     */
    public function getMembres(): Collection
    {
        return $this->membres;
    }

    public function addMembre(MembreGouvernement $membre): static
    {
        if (!$this->membres->contains($membre)) {
            $this->membres->add($membre);
            $membre->setGouvernement($this);
        }

        return $this;
    }

    public function removeMembre(MembreGouvernement $membre): static
    {
        if ($this->membres->removeElement($membre)) {
            if ($membre->getGouvernement() === $this) {
                $membre->setGouvernement(null);
            }
        }

        return $this;
    }

    public function estActuel(): bool
    {
        return $this->dateFin === null;
    }

    public function getPremierMinistre(): ?MembreGouvernement
    {
        foreach ($this->membres as $membre) {
            if ('Premier ministre' === $membre->getCategorie()) {
                return $membre;
            }
        }

        return null;
    }

    public function getNomAffiche(): string
    {
        $nom = mb_strtolower((string) $this->nom, 'UTF-8');
        $nom = ucwords($nom);
        $nom = preg_replace_callback(
            '/\b([ivxlcdm]+)\b/i',
            static fn (array $correspondance): string => strtoupper($correspondance[1]),
            $nom,
        );

        return 'Gouvernement ' . $nom;
    }
}
