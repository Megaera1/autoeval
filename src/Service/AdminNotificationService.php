<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

class AdminNotificationService
{
    private const ADMIN_EMAIL = 'consultation.neuropsychologue@gmail.com';
    private const FROM_EMAIL  = 'noreply@autoeval.eu';

    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    public function sendNewPatientNotification(User $patient): void
    {
        $email = (new TemplatedEmail())
            ->from(self::FROM_EMAIL)
            ->to(self::ADMIN_EMAIL)
            ->subject(sprintf(
                'Nouvelle inscription patient — %s %s',
                $patient->getFirstName(),
                $patient->getLastName(),
            ))
            ->htmlTemplate('emails/new_patient_notification.html.twig')
            ->textTemplate('emails/new_patient_notification.txt.twig')
            ->context(['patient' => $patient]);

        $this->mailer->send($email);
    }
}
