<?php

namespace App\Domain\Homologations;

use App\Domain\Homologations\Enums\DeadlineType;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessDeadline;
use App\Domain\Projects\ProjectFactsBuilder;
use App\Domain\Rules\ConditionEvaluator;
use App\Domain\Rules\Enums\DecisionType;
use App\Domain\Rules\Models\DeadlineRule;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Rules\RuleResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Abre prazos a partir de deadline_rules vigentes. Nenhum número de dias fica no código.
 */
final class DeadlineCalculator
{
    public function __construct(
        private readonly ProjectFactsBuilder $factsBuilder,
        private readonly ConditionEvaluator $evaluator,
        private readonly RuleResolver $resolver,
    ) {}

    public function open(HomologationProcess $process, DeadlineType $type, ?CarbonInterface $start = null): ?ProcessDeadline
    {
        $start = Carbon::instance($start ?? now());
        $this->close($process, $type, 'SUPERSEDED');

        $facts = $this->factsBuilder->build($process->project, $process);
        $rules = $this->resolver->resolve(
            DeadlineRule::class,
            $start,
            $facts['distributor_code'],
            fn ($q) => $q->where('deadline_type', $type->value),
        );

        $rule = $rules->first(fn (DeadlineRule $r) => $this->evaluator->evaluate($r->conditions, $facts)['passed']);

        if ($rule === null) {
            RuleDecision::record(DecisionType::Deadline, $process, null, $facts, [
                'deadline_type' => $type->value,
                'reason' => 'Nenhuma regra de prazo aplicável.',
            ]);

            return null;
        }

        $due = $this->addDays($start, $rule->days, $rule->day_count);

        $decision = RuleDecision::record(DecisionType::Deadline, $process, $rule, $facts, [
            'deadline_type' => $type->value,
            'days' => $rule->days,
            'day_count' => $rule->day_count,
            'starts_at' => $start->toIso8601String(),
            'due_at' => $due->toDateString(),
        ]);

        return $process->deadlines()->create([
            'deadline_type' => $type,
            'status' => 'OPEN',
            'starts_at' => $start,
            'due_at' => $due,
            'days' => $rule->days,
            'day_count' => $rule->day_count,
            'rule_decision_id' => $decision->id,
        ]);
    }

    /** Encerra o prazo aberto do tipo: MET (atendido no prazo), BREACHED (vencido) ou SUPERSEDED. */
    public function close(HomologationProcess $process, DeadlineType $type, ?string $forceStatus = null): void
    {
        $process->deadlines()
            ->where('deadline_type', $type->value)
            ->where('status', 'OPEN')
            ->get()
            ->each(function (ProcessDeadline $deadline) use ($forceStatus): void {
                $deadline->status = $forceStatus ?? ($deadline->due_at->lt(today()) ? 'BREACHED' : 'MET');
                $deadline->closed_at = now();
                $deadline->save();
            });
    }

    public function addDays(Carbon $start, int $days, string $dayCount): Carbon
    {
        if ($dayCount !== 'BUSINESS') {
            return $start->copy()->startOfDay()->addDays($days);
        }

        $date = $start->copy()->startOfDay();
        $added = 0;
        while ($added < $days) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date;
    }
}
