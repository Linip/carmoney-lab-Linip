<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Предварительная оценка заявки:
 *   валидация -> LTV -> решение -> подавление approve при пробеге выше порога -> лимит.
 *
 * Лимит сейчас равен запрошенной сумме при approve и нулю в остальных случаях.
 * Расчёт лимита по максимальному LTV для возраста авто (справочник
 * rules.ltv_by_age) — задача LOAN-12, она ещё не сделана.
 */
final class AssessmentService
{
    /** @param array<string,mixed> $rules */
    public function __construct(
        private readonly ApplicationValidator $validator,
        private readonly LtvCalculator $ltvCalculator,
        private readonly DecisionEngine $decisionEngine,
        private readonly VehicleAge $vehicleAge,
        private readonly array $rules,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{vehicle_age:int,ltv:float,decision:string,approved_limit:int,input:array<string,mixed>}
     */
    public function assess(array $payload): array
    {
        $input = $this->validator->validate($payload);

        $ltv = $this->ltvCalculator->calculate($input['requested_amount'], $input['market_value']);
        $decision = $this->decisionEngine->decide($ltv);

        if ($decision === DecisionEngine::APPROVE
            && $input['mileage'] > $this->rules['vehicle']['max_mileage_review_km']) {
            $decision = DecisionEngine::REVIEW;
        }

        return [
            'vehicle_age' => $this->vehicleAge->inYears($input['year']),
            'ltv' => $ltv,
            'decision' => $decision,
            'approved_limit' => $decision === DecisionEngine::APPROVE ? $input['requested_amount'] : 0,
            'input' => $input,
        ];
    }
}
