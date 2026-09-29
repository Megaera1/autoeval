<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

class WelcomeMailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    public function sendWelcomeMail(User $user): void
    {
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Bienvenue sur AutoEval — votre espace patient est activé')
            ->htmlTemplate('emails/welcome.html.twig')
            ->textTemplate('emails/welcome.txt.twig')
            ->context([
                'user' => $user,
            ]);

        $this->mailer->send($email);
    }
}
