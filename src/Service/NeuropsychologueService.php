<?php

namespace App\Service;

use App\Entity\AssignedQuestionnaire;
use App\Entity\User;
use App\Repository\AnamnesisRepository;
use App\Repository\AssignedQuestionnaireRepository;
use App\Repository\QuestionnaireRepository;
use App\Repository\QuestionnaireResponseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class NeuropsychologueService
{
    private const ANAMNESIS_LABELS = [
        'motif_consultation'       => 'Motif de consultation',
        'antecedents_medicaux'     => 'Antécédents médicaux personnels',
        'antecedents_familiaux'    => 'Antécédents familiaux',
        'traitements_en_cours'     => 'Traitements en cours',
        'niveau_etudes'            => 'Niveau d\'études',
        'precision_etudes'         => 'Précisions sur la scolarité',
        'situation_professionnelle'=> 'Situation professionnelle',
        'situation_familiale'      => 'Situation familiale',
        'plaintes_cognitives'      => 'Plaintes cognitives actuelles',
        'sommeil'                  => 'Qualité du sommeil',
        'precision_sommeil'        => 'Précisions sur le sommeil',
        'lateralite'               => 'Latéralité',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private AnamnesisRepository $anamnesisRepository,
        private QuestionnaireResponseRepository $responseRepository,
        private QuestionnaireRepository $questionnaireRepository,
        private AssignedQuestionnaireRepository $assignedQuestionnaireRepository,
        #[Autowire('%kernel.project_dir%')] private string $projectDir = '',
    ) {
    }

    private function loadAnamnesisDefinition(string $formType): ?array
    {
        $path = $this->projectDir . '/questionnaires/anamnese_' . $formType . '.json';
        if (!file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true);
    }

    /**
     * Build a flat id→label map from a JSON definition (for legacy rendering).
     */
    private function buildLabelMapFromDefinition(array $definition): array
    {
        $map = [];
        foreach ($definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $map[$field['id']] = $field['label'];
            }
        }

        return $map;
    }

    /** @return array<int, array{patient: User, anamnesis: ?\App\Entity\Anamnesis, completedResponseCount: int}> */
    public function getAllPatients(): array
    {
        $patients = $this->userRepository->findAllPatients();
        $result = [];

        foreach ($patients as $patient) {
            $anamnesis = $this->anamnesisRepository->findOneBy(['patient' => $patient]);
            $completedCount = $this->responseRepository->count(['patient' => $patient, 'isComplete' => true]);

            $result[] = [
                'patient'               => $patient,
                'anamnesis'             => $anamnesis,
                'completedResponseCount'=> $completedCount,
            ];
        }

        return $result;
    }

    /** @return array{totalPatients: int, totalResponses: int, pctAnamneses: int} */
    public function getStats(): array
    {
        $totalPatients   = count($this->userRepository->findAllPatients());
        $totalResponses  = $this->responseRepository->count(['isComplete' => true]);
        $totalAnamneses  = $this->anamnesisRepository->count([]);
        $completeAnamneses = $this->anamnesisRepository->count(['isComplete' => true]);
        $pctAnamneses    = $totalAnamneses > 0 ? (int) round($completeAnamneses / $totalAnamneses * 100) : 0;

        return [
            'totalPatients'  => $totalPatients,
            'totalResponses' => $totalResponses,
            'pctAnamneses'   => $pctAnamneses,
        ];
    }

    /**
     * @return array{patient: User, anamnesis: ?\App\Entity\Anamnesis, responses: array, anamnesisLabels: array, anamnesisDefinition: ?array, questionnairesGrouped: array, assignedIds: int[]}
     */
    public function getPatientFullProfile(int $id): array
    {
        $patient = $this->userRepository->find($id);
        if (!$patient) {
            throw new \RuntimeException('Patient introuvable.');
        }

        $anamnesis = $this->anamnesisRepository->findOneBy(['patient' => $patient]);
        $responses = $this->responseRepository->findBy(
            ['patient' => $patient],
            ['startedAt' => 'DESC'],
        );

        $allQuestionnaires = $this->questionnaireRepository->findBy(
            ['isActive' => true],
            ['category' => 'ASC', 'title' => 'ASC']
        );

        $questionnairesGrouped = [];
        foreach ($allQuestionnaires as $q) {
            $cat = $q->getCategory() ?? 'Autre';
            $questionnairesGrouped[$cat][] = $q;
        }

        $assignedIds = $this->assignedQuestionnaireRepository->findAssignedIds($patient);

        // Load the JSON-driven anamnesis definition if a form type is known
        $anamnesisDefinition = null;
        if ($anamnesis) {
            $formType = $anamnesis->getData()['_form_type'] ?? null;
            if ($formType) {
                $anamnesisDefinition = $this->loadAnamnesisDefinition($formType);
            }
        }

        return [
            'patient'               => $patient,
            'anamnesis'             => $anamnesis,
            'responses'             => $responses,
            'anamnesisLabels'       => self::ANAMNESIS_LABELS,
            'anamnesisDefinition'   => $anamnesisDefinition,
            'questionnairesGrouped' => $questionnairesGrouped,
            'assignedIds'           => $assignedIds,
        ];
    }

    /**
     * Synchronise les questionnaires assignés à un patient.
     * Ajoute les nouveaux, supprime ceux décochés.
     *
     * @param int[] $newIds
     * @return \App\Entity\Questionnaire[] Questionnaires nouvellement assignés lors de cette action
     */
    public function assignQuestionnaires(User $patient, array $newIds, User $assignedBy): array
    {
        $existing    = $this->assignedQuestionnaireRepository->findBy(['patient' => $patient]);
        $existingIds = array_map(
            static fn (AssignedQuestionnaire $a) => $a->getQuestionnaire()->getId(),
            $existing
        );

        // Remove unchecked assignments
        foreach ($existing as $assignment) {
            if (!\in_array($assignment->getQuestionnaire()->getId(), $newIds, true)) {
                $this->em->remove($assignment);
            }
        }

        // Add newly checked assignments
        $newlyAssigned = [];
        foreach ($newIds as $qId) {
            if (!\in_array($qId, $existingIds, true)) {
                $questionnaire = $this->questionnaireRepository->find($qId);
                if ($questionnaire) {
                    $this->em->persist(new AssignedQuestionnaire($patient, $questionnaire, $assignedBy));
                    $newlyAssigned[] = $questionnaire;
                }
            }
        }

        $this->em->flush();

        return $newlyAssigned;
    }

    public function deletePatientAccount(User $patient): void
    {
        if (\in_array('ROLE_NEUROPSYCHOLOGUE', $patient->getRoles(), true)) {
            throw new \LogicException('Impossible de supprimer le compte d\'un neuropsychologue via cette méthode.');
        }

        foreach ($this->responseRepository->findBy(['patient' => $patient]) as $response) {
            $this->em->remove($response);
        }
        foreach ($this->assignedQuestionnaireRepository->findBy(['patient' => $patient]) as $assigned) {
            $this->em->remove($assigned);
        }
        $anamnesis = $this->anamnesisRepository->findOneBy(['patient' => $patient]);
        if ($anamnesis) {
            $this->em->remove($anamnesis);
        }
        $this->em->remove($patient);
        $this->em->flush();
    }

    public function exportPatientTxt(User $patient): string
    {
        $anamnesis = $this->anamnesisRepository->findOneBy(['patient' => $patient]);
        $responses = $this->responseRepository->findBy(
            ['patient' => $patient, 'isComplete' => true],
            ['startedAt' => 'DESC'],
        );

        $sep   = str_repeat('=', 64);
        $lines = [];

        $lines[] = $sep;
        $lines[] = 'DOSSIER PATIENT — AutoEval';
        $lines[] = 'Date d\'export : ' . (new \DateTime())->format('d/m/Y à H:i');
        $lines[] = $sep;
        $lines[] = '';
        $lines[] = '— INFORMATIONS PERSONNELLES —';
        $lines[] = 'Nom         : ' . $patient->getLastName();
        $lines[] = 'Prénom      : ' . $patient->getFirstName();
        $lines[] = 'Email       : ' . $patient->getEmail();
        $birthDate = $patient->getBirthDate();
        $lines[] = 'Date de naiss.: ' . ($birthDate ? $birthDate->format('d/m/Y') : 'Non renseignée');
        $lines[] = 'Âge actuel  : ' . ($patient->getCurrentAge() !== null
            ? $patient->getCurrentAge() . ' ans'
            : ($patient->getAge() !== null ? $patient->getAge() . ' ans (saisi)' : 'Non renseigné'));
        $lines[] = 'Genre       : ' . ($patient->getGender() ?? 'Non renseigné');
        $lines[] = 'Inscription : ' . ($patient->getCreatedAt()?->format('d/m/Y') ?? '—');
        $lines[] = '';

        $lines[] = $sep;
        $lines[] = 'ANAMNÈSE';
        $lines[] = $sep;

        if ($anamnesis && !empty($anamnesis->getData())) {
            $anamnesisData = $anamnesis->getData();
            $formType      = $anamnesisData['_form_type'] ?? null;
            $definition    = $formType ? $this->loadAnamnesisDefinition($formType) : null;
            $labelMap      = $definition
                ? $this->buildLabelMapFromDefinition($definition)
                : self::ANAMNESIS_LABELS;

            $lines[] = 'Type de formulaire  : ' . ($definition['title'] ?? 'Standard');
            $lines[] = 'Statut              : ' . ($anamnesis->isComplete() ? 'Complète' : 'En cours');
            $lines[] = 'Dernière modification: ' . ($anamnesis->getUpdatedAt()?->format('d/m/Y à H:i') ?? '—');
            $lines[] = '';

            foreach ($anamnesisData as $key => $value) {
                if ($key === '_form_type' || (string) $value === '') {
                    continue;
                }
                $label   = $labelMap[$key] ?? $key;
                $lines[] = $label . ' :';
                $lines[] = '  ' . ($value ?: '—');
                $lines[] = '';
            }
        } else {
            $lines[] = 'Anamnèse non renseignée.';
            $lines[] = '';
        }

        $lines[] = $sep;
        $lines[] = 'QUESTIONNAIRES COMPLÉTÉS (' . count($responses) . ')';
        $lines[] = $sep;

        if (empty($responses)) {
            $lines[] = 'Aucun questionnaire complété.';
        }

        foreach ($responses as $i => $response) {
            $q       = $response->getQuestionnaire();
            $lines[] = '';
            $lines[] = ($i + 1) . '. ' . $q->getTitle();
            $lines[] = '   Catégorie  : ' . ($q->getCategory() ?? '—');
            $lines[] = '   Commencé   : ' . $response->getStartedAt()->format('d/m/Y à H:i');
            $lines[] = '   Terminé    : ' . ($response->getCompletedAt()?->format('d/m/Y à H:i') ?? '—');
            $ageAtStart = $response->getPatientAgeAtStart();
            $lines[] = '   Âge patient: ' . ($ageAtStart !== null ? $ageAtStart . ' ans' : '—');
            $lines[] = '   Score      : ' . ($response->getScore() !== null ? $response->getScore() : '—');
            $lines[] = '';

            $questions = $q->getQuestions();
            $answers   = $response->getAnswers();

            // Parent mode: output meta header block
            if (!empty($questions['parent_mode'])) {
                $lines[] = '   — INFORMATIONS ÉVALUATION —';
                if (!empty($answers['_meta_evaluateur'])) {
                    // Nouveau format : info_fields (3 champs JSON)
                    $lines[] = '   Évaluateur    : ' . $answers['_meta_evaluateur'];
                    $lines[] = '   Lien parenté  : ' . ($answers['_meta_lien_parente'] ?? '—');
                    $lines[] = '   Date éval.    : ' . ($answers['_meta_date_evaluation'] ?? '—');
                } else {
                    // Format legacy (parent_mode sans info_fields)
                    $lienMap = [
                        'mere'   => 'Mère',
                        'pere'   => 'Père',
                        'tuteur' => 'Tuteur/Tutrice',
                        'autre'  => 'Autre',
                    ];
                    $lienLabel = $lienMap[$answers['_meta_lien'] ?? ''] ?? '—';
                    if (($answers['_meta_lien'] ?? '') === 'autre' && !empty($answers['_meta_lien_autre'])) {
                        $lienLabel .= ' (' . $answers['_meta_lien_autre'] . ')';
                    }
                    $lines[] = '   Enfant        : ' . trim(($answers['_meta_enfant_prenom'] ?? '') . ' ' . ($answers['_meta_enfant_nom'] ?? '—'));
                    $lines[] = '   Classe        : ' . ($answers['_meta_classe'] ?? '—');
                    $lines[] = '   Évaluateur    : ' . trim(($answers['_meta_evaluateur_prenom'] ?? '') . ' ' . ($answers['_meta_evaluateur_nom'] ?? '—'));
                    $lines[] = '   Lien          : ' . $lienLabel;
                    $lines[] = '   Praticien     : ' . ($answers['_meta_praticien'] ?? '—');
                    $lines[] = '   Date passation: ' . ($answers['_meta_date_admin'] ?? '—');
                    $lines[] = '   Date naissance: ' . ($answers['_meta_date_naissance'] ?? '—');
                    if (!empty($answers['_meta_raisons'])) {
                        $lines[] = '   Raisons       : ' . $answers['_meta_raisons'];
                    }
                }
                $lines[] = '';
            }

            // Teacher mode: output meta header block
            if (!empty($questions['teacher_mode'])) {
                $durationMap = [
                    'moins_1_mois'  => "Moins d'un mois",
                    '1_2_mois'      => '1 à 2 mois',
                    '3_5_mois'      => '3 à 5 mois',
                    '6_11_mois'     => '6 à 11 mois',
                    '12_mois_plus'  => '12 mois ou plus',
                ];
                $lines[] = '   — INFORMATIONS ÉVALUATION —';
                $lines[] = '   Élève         : ' . trim(($answers['_meta_eleve_prenom'] ?? '') . ' ' . ($answers['_meta_eleve_nom'] ?? '—'));
                $lines[] = '   Sexe          : ' . ($answers['_meta_sexe'] ?? '—');
                $lines[] = '   Classe        : ' . ($answers['_meta_classe'] ?? '—');
                $lines[] = '   Évaluateur    : ' . ($answers['_meta_evaluateur'] ?? '—');
                $lines[] = '   Praticien     : ' . ($answers['_meta_praticien'] ?? '—');
                $lines[] = '   Connaissance  : ' . ($durationMap[$answers['_meta_duree_connaissance'] ?? ''] ?? '—');
                $lines[] = '   Date passation: ' . trim(($answers['_meta_date_admin_jour'] ?? '') . '/' . ($answers['_meta_date_admin_mois'] ?? '') . '/' . ($answers['_meta_date_admin_annee'] ?? ''), '/');
                $lines[] = '   Date naissance: ' . trim(($answers['_meta_dn_jour'] ?? '') . '/' . ($answers['_meta_dn_mois'] ?? '') . '/' . ($answers['_meta_dn_annee'] ?? ''), '/');
                if (!empty($answers['_meta_raisons'])) {
                    $lines[] = '   Raisons       : ' . $answers['_meta_raisons'];
                }
                $lines[] = '';
            }

            $lines[] = '   Réponses détaillées :';

            // DIVA 2.0 format
            if (!empty($questions['diva_mode'])) {
                foreach ($questions['sections'] as $section) {
                    $lines[] = '';
                    $lines[] = '   === ' . strtoupper($section['label']) . ' ===';
                    foreach ($section['items'] as $item) {
                        if ($section['has_periods']) {
                            $aVal     = $answers[$item['id'] . '_adult'] ?? '—';
                            $cVal     = $answers[$item['id'] . '_child'] ?? '—';
                            $aComment = $answers[$item['id'] . '_adult_comment'] ?? '';
                            $cComment = $answers[$item['id'] . '_child_comment'] ?? '';
                            $lines[] = '   ' . $item['id'] . '. ' . $item['text'];
                            $lines[] = '      Adulte  : ' . strtoupper($aVal) . ($aComment ? ' — "' . $aComment . '"' : '');
                            $lines[] = '      Enfance : ' . strtoupper($cVal) . ($cComment ? ' — "' . $cComment . '"' : '');
                        } else {
                            $val     = $answers[$item['id']] ?? '—';
                            $comment = $answers[$item['id'] . '_comment'] ?? '';
                            $lines[] = '   ' . $item['id'] . '. ' . $item['text'];
                            $lines[] = '      Réponse : ' . strtoupper($val) . ($comment ? ' — "' . $comment . '"' : '');
                        }
                    }
                }
                $lines[] = '';
                $lines[] = '   === SCORES ===';
                foreach ($questions['sections'] as $section) {
                    if ($section['has_periods']) {
                        $aScore = $answers['_score_' . $section['id'] . '_adult'] ?? '—';
                        $cScore = $answers['_score_' . $section['id'] . '_child'] ?? '—';
                        $n      = count($section['items']);
                        $lines[] = '   ' . $section['short_label'] . ' adulte  : ' . $aScore . '/' . $n
                            . ($aScore !== '—' && $aScore >= 6 ? ' ⚠ Seuil atteint' : '');
                        $lines[] = '   ' . $section['short_label'] . ' enfance : ' . $cScore . '/' . $n
                            . ($cScore !== '—' && $cScore >= 6 ? ' ⚠ Seuil atteint' : '');
                    } else {
                        $fScore = $answers['_score_' . $section['id']] ?? '—';
                        $n      = count($section['items']);
                        $lines[] = '   ' . $section['short_label'] . ' : ' . $fScore . '/' . $n . ' domaine(s) impacté(s)'
                            . ($fScore !== '—' && $fScore >= 2 ? ' ⚠ Retentissement ≥2 domaines' : '');
                    }
                }
                $lines[] = '';
                $lines[] = '   === PROPOSITION DSM-IV ===';
                $ia = $answers['_score_inattention_adult']   ?? null;
                $ha = $answers['_score_hyperactivite_adult'] ?? null;
                $ic = $answers['_score_inattention_child']   ?? null;
                $hc = $answers['_score_hyperactivite_child'] ?? null;
                $ff = $answers['_score_fonctionnement']      ?? null;
                if ($ia !== null && $ha !== null) {
                    if ($ia >= 6 && $ha >= 6) {
                        $lines[] = '   Type : Combiné (314.01)';
                    } elseif ($ia >= 6) {
                        $lines[] = '   Type : Inattention prédominante (314.00)';
                    } elseif ($ha >= 6) {
                        $lines[] = '   Type : Hyperactivité/Impulsivité prédominante (314.01)';
                    } else {
                        $lines[] = '   Type : Critères adultes non atteints';
                    }
                    $lines[] = '   Inattention adulte ≥6    : ' . ($ia >= 6 ? 'OUI' : 'NON') . ' (' . $ia . '/9)';
                    $lines[] = '   Hyperactivité adulte ≥6  : ' . ($ha >= 6 ? 'OUI' : 'NON') . ' (' . $ha . '/9)';
                    if ($ic !== null) {
                        $lines[] = '   Inattention enfance ≥6   : ' . ($ic >= 6 ? 'OUI' : 'NON') . ' (' . $ic . '/9)';
                    }
                    if ($hc !== null) {
                        $lines[] = '   Hyperactivité enfance ≥6 : ' . ($hc >= 6 ? 'OUI' : 'NON') . ' (' . $hc . '/9)';
                    }
                    if ($ff !== null) {
                        $lines[] = '   Retentissement ≥2 dom.   : ' . ($ff >= 2 ? 'OUI' : 'NON') . ' (' . $ff . '/5)';
                    }
                    $lines[] = '   ⚠ Résultat indicatif. Le diagnostic final appartient au clinicien.';
                }
            // RAADS-R mode
            } elseif (!empty($questions['raads_mode'])) {
                $total      = $answers['_score_total'] ?? '—';
                $seuilTotal = $questions['scoring']['seuil_total'] ?? 65;
                $scoreMax   = $questions['scoring']['score_max']   ?? 240;

                // Find interpretation label
                $interp = '—';
                if (is_numeric($total) && isset($questions['scoring']['interpretations'])) {
                    foreach ($questions['scoring']['interpretations'] as $rule) {
                        if ($total >= ($rule['min'] ?? 0) && $total <= ($rule['max'] ?? PHP_INT_MAX)) {
                            $interp = $rule['label'];
                            break;
                        }
                    }
                }

                $lines[] = '   Score total : ' . $total . ' / ' . $scoreMax;
                $lines[] = '   Seuil TSA   : ' . $seuilTotal
                    . (is_numeric($total) ? (' — ' . ($total >= $seuilTotal ? '⚠ Seuil atteint' : '✓ En dessous du seuil')) : '');
                $lines[] = '   Interprétation : ' . $interp;
                $lines[] = '';
                $lines[] = '   Sous-scores :';

                foreach ($questions['sous_echelles'] as $se) {
                    $seScore = $answers['_score_' . $se['id']] ?? '—';
                    $seSeuil = $se['seuil'] ?? '—';
                    $lines[] = '   ' . $se['label'] . ' : ' . $seScore . ' / ' . $se['score_max']
                        . (is_numeric($seScore) && is_numeric($seSeuil)
                            ? (' (seuil ' . $seSeuil . ($seScore >= $seSeuil ? ' — ⚠ Seuil atteint' : ' — ✓ OK') . ')')
                            : '');
                }
                $lines[] = '';

                // Build option label map
                $optionLabels = [];
                foreach ($questions['options'] as $opt) {
                    $optionLabels[$opt['clef']] = $opt['label'];
                }
                $clusterLabels = [];
                foreach ($questions['sous_echelles'] as $se) {
                    $clusterLabels[$se['id']] = $se['label'];
                }

                $items = $questions['items'];
                usort($items, static fn ($a, $b) => ($a['ordre_affichage'] ?? 0) <=> ($b['ordre_affichage'] ?? 0));

                foreach ($items as $item) {
                    $rawAnswer   = $answers[$item['id']] ?? null;
                    $answerLabel = $rawAnswer !== null ? ($optionLabels[$rawAnswer] ?? $rawAnswer) : '—';
                    $score       = ($rawAnswer !== null && isset($item['scores'][$rawAnswer]))
                        ? $item['scores'][$rawAnswer]
                        : '—';
                    $cluster = $clusterLabels[$item['sous_echelle']] ?? $item['sous_echelle'];
                    $lines[] = '   [' . $cluster . '] ' . $item['texte'];
                    $lines[] = '      → ' . $answerLabel . ' (' . $score . ')';
                }

            // Rich format (HAD-like): items stored under 'items' key, keyed by item id
            } elseif (isset($questions['items'])) {
                $items = $questions['items'];
                usort($items, static fn($a, $b) => ($a['ordre_affichage'] ?? 0) <=> ($b['ordre_affichage'] ?? 0));

                // Build cluster label map
                $clusterLabels = [];
                foreach ($questions['sous_echelles'] ?? [] as $se) {
                    $clusterLabels[$se['id']] = strtoupper($se['label']);
                }

                // Sub-scores
                if (isset($questions['sous_echelles'])) {
                    $hasCorrection      = !empty($questions['omission_correction']);
                    $hasOmissionDefault = isset($questions['omission_default_score']);
                    $totalBrut          = 0;
                    foreach ($questions['sous_echelles'] as $se) {
                        $seScore = $answers['_score_' . $se['id']] ?? '—';
                        if (is_numeric($seScore)) {
                            $totalBrut += $seScore;
                        }
                        if ($hasCorrection) {
                            $omissions = $answers['_score_' . $se['id'] . '_omissions'] ?? '—';
                            $corrKey   = '_score_' . $se['id'] . '_corrected';
                            $corrected = array_key_exists($corrKey, $answers)
                                ? ($answers[$corrKey] !== null ? $answers[$corrKey] : 'Non interprétable')
                                : '—';
                            $lines[] = '   ' . $se['label'] . ' — Brut : ' . $seScore . ' / ' . $se['score_max']
                                . ' | Omissions : ' . $omissions
                                . ' | Corrigé : ' . $corrected;
                        } elseif ($hasOmissionDefault) {
                            $omissions = $answers['_score_' . $se['id'] . '_omissions'] ?? '—';
                            $lines[] = '   ' . $se['label'] . ' — Brut : ' . $seScore . ' / ' . $se['score_max']
                                . ' | Omissions (×' . $questions['omission_default_score'] . ') : ' . $omissions;
                        } else {
                            $lines[] = '   Score ' . $se['label'] . ' : ' . $seScore . ' / ' . $se['score_max'];
                        }
                    }
                    if ($hasCorrection || $hasOmissionDefault) {
                        $lines[] = '   TOTAL BRUT : ' . $totalBrut;
                    }
                    $lines[] = '';
                }

                foreach ($items as $item) {
                    $rawAnswer = $answers[$item['id']] ?? null;
                    // Resolve numeric value to label
                    $answerLabel = '—';
                    if ($rawAnswer !== null) {
                        foreach ($item['options'] ?? [] as $opt) {
                            if ((string) $opt['valeur'] === (string) $rawAnswer) {
                                $answerLabel = $opt['label'] . ' (' . $opt['valeur'] . ')';
                                break;
                            }
                        }
                    }
                    $clusterLabel = $clusterLabels[$item['sous_echelle'] ?? ''] ?? strtoupper($item['sous_echelle'] ?? '?');
                    $lines[] = '   [' . $clusterLabel . '] ' . $item['texte'];
                    $lines[] = '      → ' . $answerLabel;
                }
            } else {
                // Legacy simple format
                foreach ($questions as $idx => $question) {
                    $answer = $answers[(string) $idx] ?? '—';
                    if (is_array($answer)) {
                        $answer = implode(', ', $answer);
                    }
                    $lines[] = '   Q' . ($idx + 1) . '. ' . ($question['text'] ?? $question['label'] ?? '?');
                    $lines[] = '      Réponse : ' . $answer;
                }
            }

            $lines[] = '   ' . str_repeat('—', 40);
        }

        $lines[] = '';
        $lines[] = $sep;
        $lines[] = 'Fin du dossier';
        $lines[] = $sep;

        return implode("\n", $lines);
    }
}
