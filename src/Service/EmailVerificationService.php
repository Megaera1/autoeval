<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Génère un jeton de confirmation d'email et envoie le lien d'activation.
 * Le compte reste inactif (connexion refusée par UserChecker) tant que le lien n'a pas été cliqué.
 */
class EmailVerificationService
{
    public const TOKEN_LIFETIME = '+24 hours';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function sendVerificationMail(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $user->setVerificationToken($token);
        $user->setVerificationTokenExpiresAt(new \DateTimeImmutable(self::TOKEN_LIFETIME));
        $this->em->flush();

        $verifyLink = $this->urlGenerator->generate(
            'app_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Confirmez votre adresse email — AutoEval')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->textTemplate('emails/verify_email.txt.twig')
            ->context([
                'user' => $user,
                'verifyLink' => $verifyLink,
            ]);

        $this->mailer->send($email);
    }

    /**
     * Quelqu'un a tenté de s'inscrire avec l'email d'un compte déjà actif.
     * On prévient le titulaire par email plutôt que d'afficher « compte existant »
     * à l'écran (ce qui révélerait qui est inscrit sur la plateforme).
     */
    public function sendAlreadyRegisteredMail(User $user): void
    {
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Vous avez déjà un compte AutoEval')
            ->htmlTemplate('emails/already_registered.html.twig')
            ->textTemplate('emails/already_registered.txt.twig')
            ->context([
                'user' => $user,
                'loginLink' => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'resetLink' => $this->urlGenerator->generate('app_reset_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        $this->mailer->send($email);
    }
}
