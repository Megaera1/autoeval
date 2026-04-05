<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class WelcomeMailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
    ) {}

    public function sendWelcomeMail(User $user, string $plainPassword): void
    {
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Bienvenue sur AutoEval — vos identifiants de connexion')
            ->htmlTemplate('emails/welcome.html.twig')
            ->textTemplate('emails/welcome.txt.twig')
            ->context([
                'user' => $user,
                'plainPassword' => $plainPassword,
            ]);

        $this->mailer->send($email);
    }
}
