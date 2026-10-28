<?php

namespace App\Domain\Projects;

use App\Domain\Projects\Enums\GenerationClassification;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Rules\ConditionEvaluator;
use App\Domain\Rules\Enums\DecisionType;
use App\Domain\Rules\Models\FastTrackRule;
use App\Domain\Rules\Models\GenerationClassificationRule;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Rules\RuleResolver;
use Illuminate\Support\Carbon;

/**
 * Recalcula potências, classificação e elegibilidade Fast Track a partir das regras vigentes,
 * gravando um snapshot imutável (RuleDecision) de cada decisão.
 */
final class ProjectEvaluator
{
    public function __construct(
        private readonly ProjectFactsBuilder $facts,
        private readonly ConditionEvaluator $evaluator,
        private readonly RuleResolver $resolver,
    ) {}

    public function evaluate(SolarProject $project): SolarProject
    {
        $project->unsetRelation('equipment')->load('equipment');
        $at = Carbon::now();

        $modules = $this->facts->modulesPowerKwp($project);
        $inverters = $this->facts->invertersPowerKw($project);
        $project->installed_power_kwp = (string) $modules;
        $project->inverter_power_kw = (string) $inverters;
        $project->considered_power_kw = (string) $this->facts->consideredPower($modules, $inverters);

        $this->classify($project, $at);
        $this->evaluateFastTrack($project, $at);

        $project->save();

        return $project;
    }

    private function classify(SolarProject $project, Carbon $at): void
    {
        $project->classification = null;
        $facts = $this->facts->build($project);
        $distributor = $facts['distributor_code'];

        $rules = $this->resolver->resolve(GenerationClassificationRule::class, $at, $distributor);
        $evaluated = [];
        $matched = null;

        foreach ($rules as $rule) {
            $result = $this->evaluator->evaluate($rule->conditions, $facts);
            $evaluated[] = ['rule_code' => $rule->rule_code, 'version' => $rule->version, 'passed' => $result['passed'], 'failures' => $result['failures']];
            if ($result['passed']) {
                $matched = $rule;
                break;
            }
        }

        $project->classification = $matched ? GenerationClassification::from($matched->classification) : null;

        $decision = RuleDecision::record(DecisionType::Classification, $project, $matched, $facts, [
            'classification' => $project->classification?->value,
            'evaluated' => $evaluated,
            'reason' => $matched
                ? $matched->description
                : 'Nenhuma regra de classificação atendida pelos dados atuais (verifique potências e despachabilidade).',
        ]);

        $project->classification_decision_id = $decision->id;
    }

    private function evaluateFastTrack(SolarProject $project, Carbon $at): void
    {
        $facts = $this->facts->build($project);
        $rules = $this->resolver->resolve(FastTrackRule::class, $at, $facts['distributor_code']);

        $eligible = false;
        $matched = null;
        $reasons = [];

        foreach ($rules as $rule) {
            $result = $this->evaluator->evaluate($rule->conditions, $facts);
            if ($result['passed']) {
                $eligible = true;
                $matched = $rule;
                break;
            }
            foreach ($result['failures'] as $failure) {
                $reasons[] = $failure['message'] ?? "{$failure['fact']} não atende ({$failure['op']} ".json_encode($failure['expected']).').';
            }
            $matched ??= $rule;
        }

        if ($rules->isEmpty()) {
            $reasons[] = 'Não há regra de Fast Track vigente para esta distribuidora.';
        }

        $project->fast_track_eligible = $eligible;

        $decision = RuleDecision::record(DecisionType::FastTrack, $project, $matched, $facts, [
            'eligible' => $eligible,
            'reasons' => array_values(array_unique($reasons)),
        ]);

        $project->fast_track_decision_id = $decision->id;
    }
}
