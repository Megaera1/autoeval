# TODO — AutoEval

## ÉTAPE 1 — Dépendances [ ]
- [ ] Doctrine ORM + migrations
- [ ] Twig + extensions
- [ ] Security bundle
- [ ] Forms + Validator
- [ ] Mailer
- [ ] MakerBundle
- [ ] AssetMapper

## ÉTAPE 2 — Entités & base de données [ ]
- [ ] Entité User (email, password, roles, nom, prénom, âge, sexe)
- [ ] Entité Anamnesis (relation User, data JSON, isComplete)
- [ ] Entité Questionnaire (title, slug, questions JSON)
- [ ] Entité QuestionnaireResponse (relation User + Questionnaire, answers JSON, score)
- [ ] Migration créée et appliquée
- [ ] Validation du schéma doctrine:schema:validate

## ÉTAPE 3 — Authentification [ ]
- [ ] security.yaml configuré (2 firewalls, access_control)
- [ ] SecurityController (login / logout)
- [ ] RegistrationController (inscription patient)
- [ ] Templates login et register
- [ ] Test : connexion patient fonctionne
- [ ] Test : connexion neuropsychologue fonctionne

## ÉTAPE 4 — Espace patient [ ]
- [ ] PatientController + dashboard
- [ ] Page et formulaire anamnèse (sauvegarde JSON)
- [ ] Page liste des questionnaires
- [ ] Formulaire dynamique questionnaire
- [ ] Historique des passations
- [ ] PatientService

## ÉTAPE 5 — Espace neuropsychologue [ ]
- [ ] AdminController + dashboard liste patients
- [ ] Page profil patient (lecture seule)
- [ ] Export TXT téléchargeable
- [ ] NeuropsychologueService
- [ ] Test : un patient ne peut pas accéder à /admin/*
