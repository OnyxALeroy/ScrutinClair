<?php

namespace App\Entity;

use App\Repository\VoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoteRepository::class)]
class Vote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $statut = null;

    #[ORM\ManyToOne(inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Texte $texte = null;

    #[ORM\ManyToOne(inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PersonnalitePolitique $personalite = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getTexte(): ?Texte
    {
        return $this->texte;
    }

    public function setTexte(?Texte $texte): static
    {
        $this->texte = $texte;

        return $this;
    }

    public function getPersonalite(): ?PersonnalitePolitique
    {
        return $this->personalite;
    }

    public function setPersonalite(?PersonnalitePolitique $personalite): static
    {
        $this->personalite = $personalite;

        return $this;
    }
}
