# AutoEval — Contexte projet pour Claude Code

## Contexte métier
Application web de neuropsychologie clinique. Elle permet à des patients
de transmettre leurs données avant un bilan neuropsychologique :
- Remplissage d'une anamnèse (historique médical, plaintes, contexte de vie)
- Passation de questionnaires cliniques standardisés (échelles d'anxiété,
  dépression, fatigue cognitive, etc.)

Deux types d'utilisateurs :
- **Patient** (ROLE_PATIENT) : accès à son propre espace, ses formulaires
- **Neuropsychologue** (ROLE_NEUROPSYCHOLOGUE) : accès à tous les dossiers patients

Les données sont médicales et sensibles. La confidentialité est prioritaire.

## Stack technique
- Symfony 7.4 / PHP 8.2
- MariaDB 11.8
- Twig pour les templates
- DDEV pour l'environnement local
- URL locale : https://autoeval.ddev.site

## Commandes utiles
- `ddev start` / `ddev stop`
- `ddev exec php bin/console [commande]`
- `ddev exec php bin/console doctrine:migrations:migrate`
- `ddev exec php bin/console make:entity`

## Conventions du projet
- Attributs PHP #[...] pour Doctrine et les routes (pas d'annotations YAML)
- Services dans src/Service/
- Un contrôleur par espace : PatientController, AdminController, SecurityController
- Toutes les routes patient sous /patient/*, toutes les routes admin sous /admin/*
- Données des formulaires stockées en JSON dans la base (flexibilité des champs)

## Ce qu'il ne faut PAS faire
- Ne jamais exposer les données d'un patient à un autre patient
- Ne pas stocker les mots de passe en clair
- Ne pas créer de route admin accessible sans ROLE_NEUROPSYCHOLOGUE
