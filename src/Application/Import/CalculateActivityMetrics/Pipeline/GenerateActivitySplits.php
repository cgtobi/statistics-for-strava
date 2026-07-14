<?php

declare(strict_types=1);

namespace App\Application\Import\CalculateActivityMetrics\Pipeline;

use App\Domain\Activity\Split\ActivitySplitCalculator;
use App\Domain\Activity\Split\ActivitySplitRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\Console\ProgressIndicator;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 35)]
final readonly class GenerateActivitySplits implements CalculateActivityMetricsStep
{
    public function __construct(
        private ActivityStreamRepository $activityStreamRepository,
        private ActivitySplitRepository $activitySplitRepository,
    ) {
    }

    public function process(OutputInterface $output): void
    {
        $progressIndicator = new ProgressIndicator($output);
        $progressIndicator->start('=> Generated splits for 0 activities');

        $calculator = ActivitySplitCalculator::create();
        $countActivitiesProcessed = 0;

        foreach ($this->activitySplitRepository->findActivityIdsWithoutSplits() as $activityId) {
            $streams = $this->activityStreamRepository->findByActivityId($activityId);
            $distances = $this->toFloatList($streams->filterOnType(StreamType::DISTANCE)?->getData() ?? []);
            $times = $this->toFloatList($streams->filterOnType(StreamType::TIME)?->getData() ?? []);
            if ([] === $distances || [] === $times) {
                continue;
            }
            $altitudes = $this->toFloatList($streams->filterOnType(StreamType::ALTITUDE)?->getData() ?? []);
            $movings = $this->toBoolList($streams->filterOnType(StreamType::MOVING)?->getData() ?? []);

            $splits = [
                ...$calculator->calculate($activityId, UnitSystem::METRIC, $distances, $times, $altitudes, $movings),
                ...$calculator->calculate($activityId, UnitSystem::IMPERIAL, $distances, $times, $altitudes, $movings),
            ];
            if ([] === $splits) {
                continue;
            }

            foreach ($splits as $split) {
                $this->activitySplitRepository->add($split);
            }

            ++$countActivitiesProcessed;
            $progressIndicator->updateMessage(sprintf('=> Generated splits for %d activities', $countActivitiesProcessed));
        }

        $progressIndicator->finish(sprintf('=> Generated splits for %d activities', $countActivitiesProcessed));
    }

    /**
     * @param array<int, mixed> $data
     *
     * @return list<float>
     */
    private function toFloatList(array $data): array
    {
        return array_values(array_map(static fn (mixed $value): float => (float) $value, $data));
    }

    /**
     * @param array<int, mixed> $data
     *
     * @return list<bool>
     */
    private function toBoolList(array $data): array
    {
        return array_values(array_map(static fn (mixed $value): bool => (bool) $value, $data));
    }
}
