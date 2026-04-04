<?php

namespace App\Entity;

use App\Repository\AssignedQuestionnaireRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssignedQuestionnaireRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_patient_questionnaire', columns: ['patient_id', 'questionnaire_id'])]
class AssignedQuestionnaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $patient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Questionnaire $questionnaire;

    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy = null;

    public function __construct(User $patient, Questionnaire $questionnaire, ?User $assignedBy = null)
    {
        $this->patient       = $patient;
        $this->questionnaire = $questionnaire;
        $this->assignedBy    = $assignedBy;
        $this->assignedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPatient(): User
    {
        return $this->patient;
    }

    public function getQuestionnaire(): Questionnaire
    {
        return $this->questionnaire;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getAssignedBy(): ?User
    {
        return $this->assignedBy;
    }
}
