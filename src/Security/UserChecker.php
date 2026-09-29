<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion tant que l'adresse email n'a pas été confirmée.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    /**
     * Contrôle fait APRÈS la vérification du mot de passe : un mauvais mot de passe
     * donne toujours « identifiants invalides », sans révéler qu'un compte non
     * confirmé existe pour cet email.
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            throw new CustomUserMessageAccountStatusException(
                'Votre adresse email n\'a pas encore été confirmée. Cliquez sur le lien reçu par email pour activer votre compte.'
            );
        }
    }
}
