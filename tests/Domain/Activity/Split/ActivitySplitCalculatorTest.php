<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Split;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Split\ActivitySplitCalculator;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use PHPUnit\Framework\TestCase;

final class ActivitySplitCalculatorTest extends TestCase
{
    private function activityId(): ActivityId
    {
        return ActivityId::fromUnprefixed('split-calc-test');
    }

    public function testItSplitsSteadyTwoKilometerRunIntoTwoMetricSplits(): void
    {
        // 9 samples every 250 m / 50 s => steady 5 m/s, 2000 m total.
        $distances = [0, 250, 500, 750, 1000, 1250, 1500, 1750, 2000];
        $times = [0, 50, 100, 150, 200, 250, 300, 350, 400];

        $splits = ActivitySplitCalculator::create()->calculate(
            $this->activityId(), UnitSystem::METRIC, $distances, $times, [], [],
        );

        self::assertCount(2, $splits);

        self::assertSame(1, $splits[0]->getSplitNumber());
        self::assertEqualsWithDelta(1000.0, $splits[0]->getDistance()->toFloat(), 0.001);
        self::assertSame(200, $splits[0]->getElapsedTimeInSeconds());
        self::assertSame(200, $splits[0]->getMovingTimeInSeconds());
        self::assertEqualsWithDelta(5.0, $splits[0]->getAverageSpeed()->toFloat(), 0.001);
        self::assertEqualsWithDelta(5.0, $splits[0]->getMinAverageSpeed()->toFloat(), 0.001);
        self::assertEqualsWithDelta(5.0, $splits[0]->getMaxAverageSpeed()->toFloat(), 0.001);
        self::assertSame(0, $splits[0]->getPaceZone());

        self::assertSame(2, $splits[1]->getSplitNumber());
        self::assertEqualsWithDelta(1000.0, $splits[1]->getDistance()->toFloat(), 0.001);
        self::assertSame(200, $splits[1]->getElapsedTimeInSeconds());
    }

    public function testItEmitsRemainderAsFinalMetricSplit(): void
    {
        // 1250 m total: one full 1000 m split + 250 m remainder.
        $distances = [0, 250, 500, 750, 1000, 1250];
        $times = [0, 50, 100, 150, 200, 250];

        $splits = ActivitySplitCalculator::create()->calculate(
            $this->activityId(), UnitSystem::METRIC, $distances, $times, [], [],
        );

        self::assertCount(2, $splits);
        self::assertEqualsWithDelta(1000.0, $splits[0]->getDistance()->toFloat(), 0.001);
        self::assertEqualsWithDelta(250.0, $splits[1]->getDistance()->toFloat(), 0.001);
        self::assertSame(50, $splits[1]->getElapsedTimeInSeconds());
    }

    public function testItUsesOneMileBoundaryForImperialSplits(): void
    {
        // Samples land exactly on the mile boundary (1609.344 m).
        $distances = [0, 804.672, 1609.344, 2000.0];
        $times = [0, 100, 200, 300];

        $splits = ActivitySplitCalculator::create()->calculate(
            $this->activityId(), UnitSystem::IMPERIAL, $distances, $times, [], [],
        );

        self::assertCount(2, $splits);
        self::assertEqualsWithDelta(1609.344, $splits[0]->getDistance()->toFloat(), 0.001);
        self::assertSame(200, $splits[0]->getElapsedTimeInSeconds());
        self::assertEqualsWithDelta(390.656, $splits[1]->getDistance()->toFloat(), 0.001);
    }

    public function testItSetsElevationDifferenceToZeroWhenNoAltitudeStream(): void
    {
        $distances = [0, 500, 1000];
        $times = [0, 100, 200];

        $splits = ActivitySplitCalculator::create()->calculate(
            $this->activityId(), UnitSystem::METRIC, $distances, $times, [], [],
        );

        self::assertCount(1, $splits);
        self::assertEqualsWithDelta(0.0, $splits[0]->getElevationDifference()->toFloat(), 0.001);
    }

    public function testItComputesNetElevationDifferencePerSplit(): void
    {
        // Split 1: 100 -> 130 = +30. Split 2: 130 -> 110 = -20.
        $distances = [0, 500, 1000, 1500, 2000];
        $times = [0, 100, 200, 300, 400];
        $altitudes = [100.0, 120.0, 130.0, 125.0, 110.0];

        $splits = ActivitySplitCalculator::create()->calculate(
            $this->activityId(), UnitSystem::METRIC, $distances, $times, $altitudes, [],
        );

        self::assertCount(2, $splits);
        self::assertEqualsWithDelta(30.0, $splits[0]->getElevationDifference()->toFloat(), 0.001);
        self::assertEqualsWithDelta(-20.0, $splits[1]->getElevationDifference()->toFloat(), 0.001);
    }

    public function testItExcludesPausedSamplesFromMovingTimeAndSpeedRange(): void
    {
        // Interval at index 2 is a 100 s stop with no distance and moving=false.
        $distances = [0, 250, 250, 750, 1000];
        $times = [0, 50, 150, 200, 250];
        $movings = [true, true, false, true, true];

        $splits = ActivitySplitCalculator::create()->calculate(
            $this->activityId(), UnitSystem::METRIC, $distances, $times, [], $movings,
        );

        self::assertCount(1, $splits);
        self::assertSame(250, $splits[0]->getElapsedTimeInSeconds()); // wallclock incl. stop
        self::assertSame(150, $splits[0]->getMovingTimeInSeconds());  // stop excluded (250-100)
        // Moving intervals: 250/50=5, 500/50=10, 250/50=5 => min 5, max 10. Stop (0/100) excluded.
        self::assertEqualsWithDelta(5.0, $splits[0]->getMinAverageSpeed()->toFloat(), 0.001);
        self::assertEqualsWithDelta(10.0, $splits[0]->getMaxAverageSpeed()->toFloat(), 0.001);
    }

    public function testItReturnsEmptyWhenFewerThanTwoSamples(): void
    {
        $calculator = ActivitySplitCalculator::create();

        self::assertSame([], $calculator->calculate(
            $this->activityId(), UnitSystem::METRIC, [0], [0], [], [],
        ));
        self::assertSame([], $calculator->calculate(
            $this->activityId(), UnitSystem::METRIC, [], [], [], [],
        ));
    }
}
