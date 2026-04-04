<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access only to authenticated patients (ROLE_PATIENT without ROLE_NEUROPSYCHOLOGUE).
 * Prevents neuropsychologists from accessing patient routes despite role hierarchy inheritance.
 */
class PatientVoter extends Voter
{
    public const PATIENT_ONLY = 'PATIENT_ONLY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::PATIENT_ONLY;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_NEUROPSYCHOLOGUE', $user->getRoles(), true)) {
            return false;
        }

        return in_array('ROLE_PATIENT', $user->getRoles(), true);
    }
}
