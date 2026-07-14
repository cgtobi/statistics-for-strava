<?php

namespace App\Tests\Application\Import\CalculateActivityMetrics\Pipeline;

use App\Application\Import\CalculateActivityMetrics\Pipeline\GenerateActivitySplits;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Split\ActivitySplitRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Activity\Split\ActivitySplitBuilder;
use App\Tests\Domain\Activity\Stream\ActivityStreamBuilder;
use App\Tests\SpyOutput;

class GenerateActivitySplitsTest extends ContainerTestCase
{
    private GenerateActivitySplits $generateActivitySplits;
    private ActivitySplitRepository $activitySplitRepository;
    private ActivityStreamRepository $activityStreamRepository;
    private ActivityRepository $activityRepository;

    public function testItGeneratesMetricAndImperialSplitsForRun(): void
    {
        $activityId = ActivityId::fromUnprefixed('run-1');
        $this->addActivity($activityId, SportType::RUN);
        $this->addDistanceTimeAltitudeStreams(
            $activityId,
            distances: [0, 250, 500, 750, 1000, 1250, 1500, 1750, 2000],
            times: [0, 50, 100, 150, 200, 250, 300, 350, 400],
            altitudes: [10, 12, 14, 16, 18, 20, 22, 24, 26],
        );

        $output = new SpyOutput();
        $this->generateActivitySplits->process($output);

        $metricSplits = $this->activitySplitRepository->findBy($activityId, UnitSystem::METRIC);
        $this->assertCount(2, $metricSplits->toArray());
        $this->assertEqualsWithDelta(1000.0, $metricSplits->toArray()[0]->getDistance()->toFloat(), 0.001);

        $imperialSplits = $this->activitySplitRepository->findBy($activityId, UnitSystem::IMPERIAL);
        $this->assertCount(2, $imperialSplits->toArray());
        // Greedy, non-interpolating bucketing: with 250 m samples the first mile (1609.344 m)
        // split closes at the first sample where cumulative distance >= the target, i.e. 1750 m.
        $this->assertEqualsWithDelta(1750.0, $imperialSplits->toArray()[0]->getDistance()->toFloat(), 0.001);
    }

    public function testItSkipsActivityThatAlreadyHasSplits(): void
    {
        $activityId = ActivityId::fromUnprefixed('run-existing');
        $this->addActivity($activityId, SportType::RUN);
        $this->addDistanceTimeAltitudeStreams(
            $activityId,
            distances: [0, 250, 500, 750, 1000, 1250, 1500, 1750, 2000],
            times: [0, 50, 100, 150, 200, 250, 300, 350, 400],
            altitudes: [10, 12, 14, 16, 18, 20, 22, 24, 26],
        );
        $this->activitySplitRepository->add(
            ActivitySplitBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withUnitSystem(UnitSystem::METRIC)
                ->withSplitNumber(1)
                ->withDistanceInMeter(1000.0)
                ->build()
        );

        $output = new SpyOutput();
        $this->generateActivitySplits->process($output);

        $metricSplits = $this->activitySplitRepository->findBy($activityId, UnitSystem::METRIC);
        $this->assertCount(1, $metricSplits->toArray());
    }

    public function testItSkipsUnsupportedSportType(): void
    {
        $activityId = ActivityId::fromUnprefixed('swim-1');
        $this->addActivity($activityId, SportType::SWIM);
        $this->addDistanceTimeAltitudeStreams(
            $activityId,
            distances: [0, 250, 500, 750, 1000, 1250, 1500, 1750, 2000],
            times: [0, 50, 100, 150, 200, 250, 300, 350, 400],
            altitudes: [10, 12, 14, 16, 18, 20, 22, 24, 26],
        );

        $output = new SpyOutput();
        $this->generateActivitySplits->process($output);

        $metricSplits = $this->activitySplitRepository->findBy($activityId, UnitSystem::METRIC);
        $this->assertSame([], $metricSplits->toArray());
    }

    public function testItSkipsActivityWithoutDistanceStream(): void
    {
        $activityId = ActivityId::fromUnprefixed('run-no-distance');
        $this->addActivity($activityId, SportType::RUN);
        $this->activityStreamRepository->add(
            ActivityStreamBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withStreamType(StreamType::TIME)
                ->withData([0, 50, 100, 150, 200])
                ->build()
        );

        $output = new SpyOutput();
        $this->generateActivitySplits->process($output);

        $metricSplits = $this->activitySplitRepository->findBy($activityId, UnitSystem::METRIC);
        $this->assertSame([], $metricSplits->toArray());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->generateActivitySplits = $this->getContainer()->get(GenerateActivitySplits::class);
        $this->activitySplitRepository = $this->getContainer()->get(ActivitySplitRepository::class);
        $this->activityStreamRepository = $this->getContainer()->get(ActivityStreamRepository::class);
        $this->activityRepository = $this->getContainer()->get(ActivityRepository::class);
    }

    private function addActivity(ActivityId $activityId, SportType $sportType): void
    {
        $this->activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withSportType($sportType)
                ->build(),
            [],
        ));
    }

    /**
     * @param list<int|float> $distances
     * @param list<int|float> $times
     * @param list<int|float> $altitudes
     */
    private function addDistanceTimeAltitudeStreams(ActivityId $activityId, array $distances, array $times, array $altitudes): void
    {
        $this->activityStreamRepository->add(
            ActivityStreamBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withStreamType(StreamType::DISTANCE)
                ->withData($distances)
                ->build()
        );
        $this->activityStreamRepository->add(
            ActivityStreamBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withStreamType(StreamType::TIME)
                ->withData($times)
                ->build()
        );
        $this->activityStreamRepository->add(
            ActivityStreamBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withStreamType(StreamType::ALTITUDE)
                ->withData($altitudes)
                ->build()
        );
    }
}
