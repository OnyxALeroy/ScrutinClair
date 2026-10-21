<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un thème politique (finances, écologie, education…).
 *
 * Le catalogue de référence vit dans App\Enum\Theme : une ligne n'est créée
 * ici que lorsqu'un texte reçoit effectivement ce thème.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    /**
     * @var Collection<int, Texte>
     */
    #[ORM\ManyToMany(targetEntity: Texte::class, mappedBy: 'themes')]
    private Collection $textes;

    public function __construct()
    {
        $this->textes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;

        return $this;
    }

    /**
     * @return Collection<int, Texte>
     */
    public function getTextes(): Collection
    {
        return $this->textes;
    }

    public function addTexte(Texte $texte): static
    {
        if (!$this->textes->contains($texte)) {
            $this->textes->add($texte);
        }

        return $this;
    }

    public function removeTexte(Texte $texte): static
    {
        $this->textes->removeElement($texte);

        return $this;
    }
}
