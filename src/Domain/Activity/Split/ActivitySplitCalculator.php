<?php

declare(strict_types=1);

namespace App\Domain\Activity\Split;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\ValueObject\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Measurement\Velocity\MetersPerSecond;

final readonly class ActivitySplitCalculator
{
    private const float METRIC_SPLIT_LENGTH_IN_METERS = 1000.0;
    private const float IMPERIAL_SPLIT_LENGTH_IN_METERS = 1609.344;

    public static function create(): self
    {
        return new self();
    }

    /**
     * Split distances are approximate: bucketing is greedy and non-interpolating, so a split
     * closes at the first sample where cumulative distance >= the split length and may slightly
     * overshoot the target length (unlike CalculateGap, which interpolates across samples). This
     * is acceptable for the ~1 Hz sampling of FIT/GPX streams.
     *
     * minAverageSpeed/maxAverageSpeed are derived from per-sample instantaneous speed
     * (deltaDistance/deltaTime over moving intervals), NOT Strava's per-split min/max average
     * speed. As a result these values are noisier and not directly comparable to Strava-sourced
     * splits.
     *
     * @param list<int|float> $distances cumulative distance in meters
     * @param list<int|float> $times     timestamps or elapsed seconds
     * @param list<int|float> $altitudes altitude in meters (may be empty)
     * @param list<bool>      $movings   moving flags (may be empty)
     *
     * @return list<ActivitySplit>
     */
    public function calculate(
        ActivityId $activityId,
        UnitSystem $unitSystem,
        array $distances,
        array $times,
        array $altitudes,
        array $movings,
    ): array {
        $sampleCount = min(count($distances), count($times));
        if ($sampleCount < 2) {
            return [];
        }

        $splitLength = UnitSystem::IMPERIAL === $unitSystem
            ? self::IMPERIAL_SPLIT_LENGTH_IN_METERS
            : self::METRIC_SPLIT_LENGTH_IN_METERS;

        $hasAltitude = count($altitudes) >= $sampleCount;
        $hasMoving = [] !== $movings;

        $splits = [];
        $splitNumber = 1;
        $startIndex = 0;
        $movingSeconds = 0.0;
        $minSpeed = null;
        $maxSpeed = null;

        for ($i = 1; $i < $sampleCount; ++$i) {
            $deltaDistance = (float) $distances[$i] - (float) $distances[$i - 1];
            $deltaTime = (float) $times[$i] - (float) $times[$i - 1];
            $isMoving = $hasMoving ? (bool) ($movings[$i] ?? false) : true;

            if ($isMoving && $deltaTime > 0.0) {
                $movingSeconds += $deltaTime;
                $speed = $deltaDistance / $deltaTime;
                if ($speed >= 0.0) {
                    $minSpeed = null === $minSpeed ? $speed : min($minSpeed, $speed);
                    $maxSpeed = null === $maxSpeed ? $speed : max($maxSpeed, $speed);
                }
            }

            $coveredDistance = (float) $distances[$i] - (float) $distances[$startIndex];
            $isLastSample = ($i === $sampleCount - 1);

            if ($coveredDistance >= $splitLength - 0.00001 || $isLastSample) {
                $splits[] = $this->buildSplit(
                    activityId: $activityId,
                    unitSystem: $unitSystem,
                    splitNumber: $splitNumber,
                    distanceInMeters: $coveredDistance,
                    elapsedSeconds: (float) $times[$i] - (float) $times[$startIndex],
                    movingSeconds: $movingSeconds,
                    elevationDifference: $hasAltitude
                        ? (float) $altitudes[$i] - (float) $altitudes[$startIndex]
                        : 0.0,
                    minSpeed: $minSpeed,
                    maxSpeed: $maxSpeed,
                );

                ++$splitNumber;
                $startIndex = $i;
                $movingSeconds = 0.0;
                $minSpeed = null;
                $maxSpeed = null;
            }
        }

        return $splits;
    }

    private function buildSplit(
        ActivityId $activityId,
        UnitSystem $unitSystem,
        int $splitNumber,
        float $distanceInMeters,
        float $elapsedSeconds,
        float $movingSeconds,
        float $elevationDifference,
        ?float $minSpeed,
        ?float $maxSpeed,
    ): ActivitySplit {
        $elapsed = (int) round($elapsedSeconds);
        $moving = $movingSeconds > 0.0 ? (int) round($movingSeconds) : $elapsed;

        $averageSpeed = $moving > 0 ? $distanceInMeters / $moving : 0.0;
        $min = $minSpeed ?? $averageSpeed;
        $max = $maxSpeed ?? $averageSpeed;

        return ActivitySplit::create(
            activityId: $activityId,
            unitSystem: $unitSystem,
            splitNumber: $splitNumber,
            distance: Meter::from($distanceInMeters),
            elapsedTimeInSeconds: $elapsed,
            movingTimeInSeconds: $moving,
            elevationDifference: Meter::from($elevationDifference),
            averageSpeed: MetersPerSecond::from($averageSpeed),
            minAverageSpeed: MetersPerSecond::from($min),
            maxAverageSpeed: MetersPerSecond::from($max),
            paceZone: 0,
        );
    }
}
