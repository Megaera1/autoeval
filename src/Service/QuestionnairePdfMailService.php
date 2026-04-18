<?php

namespace App\Service;

use App\Entity\Questionnaire;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

class QuestionnairePdfMailService
{
    private const FROM_EMAIL = 'noreply@autoeval.eu';

    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function sendQuestionnairePdf(User $patient, Questionnaire $questionnaire): void
    {
        $path = $this->projectDir . '/var/pdf/questionnaires/' . $questionnaire->getSlug() . '.pdf';

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf(
                'PDF introuvable pour le questionnaire "%s" (chemin attendu : %s).',
                $questionnaire->getTitle(),
                $path,
            ));
        }

        $email = (new TemplatedEmail())
            ->from(self::FROM_EMAIL)
            ->to($patient->getEmail())
            ->subject('Votre questionnaire : ' . $questionnaire->getTitle())
            ->htmlTemplate('emails/questionnaire_pdf.html.twig')
            ->textTemplate('emails/questionnaire_pdf.txt.twig')
            ->context([
                'patient'       => $patient,
                'questionnaire' => $questionnaire,
            ])
            ->attachFromPath($path, $questionnaire->getSlug() . '.pdf', 'application/pdf');

        $this->mailer->send($email);
    }
}
