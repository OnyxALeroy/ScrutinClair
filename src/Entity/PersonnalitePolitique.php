<?php

namespace App\Entity;

use App\Repository\PersonnalitePolitiqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PersonnalitePolitiqueRepository::class)]
class PersonnalitePolitique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $prenom = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'personalite')]
    private Collection $votes;

    #[ORM\Column(length: 255)]
    private ?string $uid = null;

    #[ORM\Column(length: 10, nullable: true, unique: true)]
    private ?string $uidSenat = null;

    #[ORM\ManyToOne(inversedBy: 'personnalites')]
    private ?GroupePolitique $groupePolitique = null;

    #[ORM\ManyToOne]
    private ?GroupePolitique $groupePolitiqueSenat = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $circonscription = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $circonscriptionSenat = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $photoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoCredit = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profession = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fonction = null;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

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
            $vote->setPersonalite($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            // set the owning side to null (unless already changed)
            if ($vote->getPersonalite() === $this) {
                $vote->setPersonalite(null);
            }
        }

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }

    public function getGroupePolitique(): ?GroupePolitique
    {
        return $this->groupePolitique;
    }

    public function setGroupePolitique(?GroupePolitique $groupePolitique): static
    {
        $this->groupePolitique = $groupePolitique;

        return $this;
    }

    public function getCirconscription(): ?string
    {
        return $this->circonscription;
    }

    public function setCirconscription(?string $circonscription): static
    {
        $this->circonscription = $circonscription;

        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getProfession(): ?string
    {
        return $this->profession;
    }

    public function setProfession(?string $profession): static
    {
        $this->profession = $profession;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
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

    public function getUidSenat(): ?string
    {
        return $this->uidSenat;
    }

    public function setUidSenat(?string $uidSenat): static
    {
        $this->uidSenat = $uidSenat;

        return $this;
    }

    public function getGroupePolitiqueSenat(): ?GroupePolitique
    {
        return $this->groupePolitiqueSenat;
    }

    public function setGroupePolitiqueSenat(?GroupePolitique $groupePolitiqueSenat): static
    {
        $this->groupePolitiqueSenat = $groupePolitiqueSenat;

        return $this;
    }

    public function getCirconscriptionSenat(): ?string
    {
        return $this->circonscriptionSenat;
    }

    public function setCirconscriptionSenat(?string $circonscriptionSenat): static
    {
        $this->circonscriptionSenat = $circonscriptionSenat;

        return $this;
    }

    public function getPhotoUrl(): ?string
    {
        return $this->photoUrl;
    }

    public function setPhotoUrl(?string $photoUrl): static
    {
        $this->photoUrl = $photoUrl;

        return $this;
    }

    public function getPhotoCredit(): ?string
    {
        return $this->photoCredit;
    }

    public function setPhotoCredit(?string $photoCredit): static
    {
        $this->photoCredit = $photoCredit;

        return $this;
    }
}
