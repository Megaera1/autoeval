<?php

namespace App\Service;

use App\Entity\Questionnaire;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

class AssignmentNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    /**
     * @param Questionnaire[] $questionnaires Questionnaires nouvellement assignés
     */
    public function notifyPatient(User $patient, array $questionnaires): void
    {
        if (empty($questionnaires)) {
            return;
        }

        $email = (new TemplatedEmail())
            ->to($patient->getEmail())
            ->subject('Nouveaux questionnaires disponibles dans votre espace')
            ->htmlTemplate('emails/questionnaires_assigned.html.twig')
            ->textTemplate('emails/questionnaires_assigned.txt.twig')
            ->context([
                'patient' => $patient,
                'questionnaires' => $questionnaires,
            ]);

        $this->mailer->send($email);
    }
}
