<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AnamnesisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motif_consultation', TextareaType::class, [
                'label' => 'Motif de consultation',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'Décrivez le motif de votre consultation...'],
            ])
            ->add('antecedents_medicaux', TextareaType::class, [
                'label' => 'Antécédents médicaux personnels',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'Maladies, hospitalisations, chirurgies...'],
            ])
            ->add('antecedents_familiaux', TextareaType::class, [
                'label' => 'Antécédents familiaux',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'Maladies neurologiques ou psychiatriques dans la famille...'],
            ])
            ->add('traitements_en_cours', TextareaType::class, [
                'label' => 'Traitements en cours',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Médicaments, posologie...'],
            ])
            ->add('niveau_etudes', ChoiceType::class, [
                'label' => 'Niveau d\'études',
                'required' => false,
                'placeholder' => 'Sélectionner',
                'choices' => [
                    'Sans diplôme' => 'sans_diplome',
                    'Brevet / BEP / CAP' => 'brevet_bep_cap',
                    'Baccalauréat' => 'baccalaureat',
                    'Bac +2 / BTS / DUT' => 'bac_plus_2',
                    'Licence (Bac +3)' => 'licence',
                    'Master (Bac +5)' => 'master',
                    'Doctorat / Bac +8' => 'doctorat',
                ],
            ])
            ->add('precision_etudes', TextareaType::class, [
                'label' => 'Précisions sur la scolarité',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Difficultés scolaires, redoublements, filière...'],
            ])
            ->add('situation_professionnelle', ChoiceType::class, [
                'label' => 'Situation professionnelle',
                'required' => false,
                'placeholder' => 'Sélectionner',
                'choices' => [
                    'En activité' => 'en_activite',
                    'Arrêt de travail' => 'arret_travail',
                    'Invalidité' => 'invalidite',
                    'Recherche d\'emploi' => 'recherche_emploi',
                    'Retraité(e)' => 'retraite',
                    'Étudiant(e)' => 'etudiant',
                    'Sans activité' => 'sans_activite',
                ],
            ])
            ->add('situation_familiale', ChoiceType::class, [
                'label' => 'Situation familiale',
                'required' => false,
                'placeholder' => 'Sélectionner',
                'choices' => [
                    'Célibataire' => 'celibataire',
                    'En couple' => 'en_couple',
                    'Marié(e)' => 'marie',
                    'Divorcé(e)' => 'divorce',
                    'Veuf/Veuve' => 'veuf',
                ],
            ])
            ->add('plaintes_cognitives', TextareaType::class, [
                'label' => 'Plaintes cognitives actuelles',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'Troubles de mémoire, attention, orientation, langage...'],
            ])
            ->add('sommeil', ChoiceType::class, [
                'label' => 'Qualité du sommeil',
                'required' => false,
                'placeholder' => 'Sélectionner',
                'choices' => [
                    'Normal' => 'normal',
                    'Perturbé' => 'perturbe',
                    'Insomnie' => 'insomnie',
                ],
            ])
            ->add('precision_sommeil', TextareaType::class, [
                'label' => 'Précisions sur le sommeil',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Durée, réveils nocturnes, apnée...'],
            ])
            ->add('lateralite', ChoiceType::class, [
                'label' => 'Latéralité',
                'required' => false,
                'placeholder' => 'Sélectionner',
                'choices' => [
                    'Droitier(ère)' => 'droitier',
                    'Gaucher(ère)' => 'gaucher',
                    'Ambidextre' => 'ambidextre',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
