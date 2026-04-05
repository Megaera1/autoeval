<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    #[ORM\Column(nullable: true)]
    private ?int $age = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 100, nullable: true, unique: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $consentAccepted = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consentAcceptedAt = null;

    /** @var Collection<int, Anamnesis> */
    #[ORM\OneToMany(targetEntity: Anamnesis::class, mappedBy: 'patient', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $anamneses;

    /** @var Collection<int, QuestionnaireResponse> */
    #[ORM\OneToMany(targetEntity: QuestionnaireResponse::class, mappedBy: 'patient', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $questionnaireResponses;

    public function __construct()
    {
        $this->anamneses = new ArrayCollection();
        $this->questionnaireResponses = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(?int $age): static
    {
        $this->age = $age;

        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): static
    {
        $this->gender = $gender;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Anamnesis> */
    public function getAnamneses(): Collection
    {
        return $this->anamneses;
    }

    public function addAnamnesis(Anamnesis $anamnesis): static
    {
        if (!$this->anamneses->contains($anamnesis)) {
            $this->anamneses->add($anamnesis);
            $anamnesis->setPatient($this);
        }

        return $this;
    }

    public function removeAnamnesis(Anamnesis $anamnesis): static
    {
        if ($this->anamneses->removeElement($anamnesis)) {
            if ($anamnesis->getPatient() === $this) {
                $anamnesis->setPatient(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, QuestionnaireResponse> */
    public function getQuestionnaireResponses(): Collection
    {
        return $this->questionnaireResponses;
    }

    public function addQuestionnaireResponse(QuestionnaireResponse $questionnaireResponse): static
    {
        if (!$this->questionnaireResponses->contains($questionnaireResponse)) {
            $this->questionnaireResponses->add($questionnaireResponse);
            $questionnaireResponse->setPatient($this);
        }

        return $this;
    }

    public function removeQuestionnaireResponse(QuestionnaireResponse $questionnaireResponse): static
    {
        if ($this->questionnaireResponses->removeElement($questionnaireResponse)) {
            if ($questionnaireResponse->getPatient() === $this) {
                $questionnaireResponse->setPatient(null);
            }
        }

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    /**
     * Age exact (en années) à une date donnée, calculé depuis birthDate.
     * Retourne null si birthDate n'est pas renseignée.
     */
    public function getAgeAtDate(\DateTimeInterface $date): ?int
    {
        if ($this->birthDate === null) {
            return null;
        }

        return (int) $this->birthDate->diff($date)->y;
    }

    /**
     * Age actuel. Utilisé pour l'affichage dans le profil admin.
     */
    public function getCurrentAge(): ?int
    {
        return $this->getAgeAtDate(new \DateTimeImmutable());
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function isConsentAccepted(): bool
    {
        return $this->consentAccepted;
    }

    public function setConsentAccepted(bool $consentAccepted): static
    {
        $this->consentAccepted = $consentAccepted;

        return $this;
    }

    public function getConsentAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->consentAcceptedAt;
    }

    public function setConsentAcceptedAt(?\DateTimeImmutable $consentAcceptedAt): static
    {
        $this->consentAcceptedAt = $consentAcceptedAt;

        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;

        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;

        return $this;
    }
}
