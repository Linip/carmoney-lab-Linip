<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\LtvCalculator;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use PHPUnit\Framework\TestCase;

final class AssessmentServiceTest extends TestCase
{
    private AssessmentService $service;

    protected function setUp(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $age = new VehicleAge((int) date('Y'));

        $this->service = new AssessmentService(
            new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
            new LtvCalculator(),
            new DecisionEngine($rules['ltv']),
            $age,
            $rules,
        );
    }

    /** @return array<string,mixed> */
    private function payload(int $amount, int $marketValue, int $mileage = 96000, ?int $year = null): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => $year ?? (int) date('Y') - 4,
            'mileage' => $mileage,
            'market_value' => $marketValue,
            'requested_amount' => $amount,
            'term_months' => 24,
        ];
    }

    public function testApprovesLowLtvAndSetsLimitToRequestedAmount(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertSame(450000, $result['approved_limit']);
        self::assertSame(4, $result['vehicle_age']);
    }

    public function testSendsMiddleLtvToReviewWithZeroLimit(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000));

        self::assertSame(75.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testRejectsHighLtv(): void
    {
        $result = $this->service->assess($this->payload(855000, 900000));

        self::assertSame(95.0, $result['ltv']);
        self::assertSame(DecisionEngine::REJECT, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsApproveWhenMileageBelowReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 399999));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertSame(450000, $result['approved_limit']);
    }

    public function testKeepsApproveAtReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400000));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertSame(450000, $result['approved_limit']);
    }

    public function testDowngradesApproveToReviewAboveReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400001));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsReviewByLtvAtReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000, 400000));

        self::assertSame(75.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsReviewWhenMileageAboveReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000, 450000));

        self::assertSame(75.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsRejectWhenMileageAboveReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(855000, 900000, 450000));

        self::assertSame(95.0, $result['ltv']);
        self::assertSame(DecisionEngine::REJECT, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testDowngradesApproveToReviewAtHardCeiling(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 500000));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testDowngradedReviewHasSameShapeAsReviewByLtv(): void
    {
        $byMileage = $this->service->assess($this->payload(450000, 900000, 400001));
        $byLtv = $this->service->assess($this->payload(675000, 900000, 400000));

        self::assertSame(DecisionEngine::REVIEW, $byMileage['decision']);
        self::assertSame(DecisionEngine::REVIEW, $byLtv['decision']);
        self::assertSame(array_keys($byLtv), array_keys($byMileage));
        self::assertArrayNotHasKey('reason', $byMileage);
        self::assertArrayNotHasKey('reason', $byLtv);
    }

    public function testReviewThresholdDoesNotDependOnVehicleAge(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $currentYear = (int) date('Y');
        $minYear = (int) $rules['vehicle']['min_year'];
        $youngYear = max($currentYear - 2, $minYear);
        $oldYear = $currentYear - 15;

        $young = $this->service->assess($this->payload(450000, 900000, 400001, $youngYear));
        $old = $this->service->assess($this->payload(450000, 900000, 400001, $oldYear));

        self::assertSame(DecisionEngine::REVIEW, $young['decision']);
        self::assertSame(0, $young['approved_limit']);
        self::assertSame(DecisionEngine::REVIEW, $old['decision']);
        self::assertSame(0, $old['approved_limit']);
    }

    public function testMileageDoesNotChangeLtv(): void
    {
        $below = $this->service->assess($this->payload(450000, 900000, 399999));
        $above = $this->service->assess($this->payload(450000, 900000, 400001));

        self::assertSame($below['ltv'], $above['ltv']);
        self::assertSame($below['vehicle_age'], $above['vehicle_age']);

        $apiFields = ['vehicle_age', 'ltv', 'decision', 'approved_limit'];
        $diff = [];
        foreach ($apiFields as $field) {
            if ($below[$field] !== $above[$field]) {
                $diff[$field] = [$below[$field], $above[$field]];
            }
        }
        self::assertSame(['decision' => [DecisionEngine::APPROVE, DecisionEngine::REVIEW], 'approved_limit' => [450000, 0]], $diff);
    }

    public function testReviewThresholdDoesNotExceedHardCeiling(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';

        self::assertLessThanOrEqual(
            $rules['vehicle']['max_mileage_km'],
            $rules['vehicle']['max_mileage_review_km'],
        );
    }
}
