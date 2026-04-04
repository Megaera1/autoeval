<?php

namespace App\Entity;

use App\Repository\QuestionnaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuestionnaireRepository::class)]
class Questionnaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: Types::JSON)]
    private array $questions = [];

    #[ORM\Column]
    private bool $isActive = true;

    /** @var Collection<int, QuestionnaireResponse> */
    #[ORM\OneToMany(targetEntity: QuestionnaireResponse::class, mappedBy: 'questionnaire', cascade: ['persist'])]
    private Collection $questionnaireResponses;

    public function __construct()
    {
        $this->questionnaireResponses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getQuestions(): array
    {
        return $this->questions;
    }

    public function setQuestions(array $questions): static
    {
        $this->questions = $questions;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

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
            $questionnaireResponse->setQuestionnaire($this);
        }

        return $this;
    }

    public function removeQuestionnaireResponse(QuestionnaireResponse $questionnaireResponse): static
    {
        if ($this->questionnaireResponses->removeElement($questionnaireResponse)) {
            if ($questionnaireResponse->getQuestionnaire() === $this) {
                $questionnaireResponse->setQuestionnaire(null);
            }
        }

        return $this;
    }
}
