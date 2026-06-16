<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Import\DbalFileImportRepository;
use App\Domain\Import\DuplicateActivityScanner;
use App\Domain\Import\FileImportRepository;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Infrastructure\ValueObject\String\ExternalReferenceId;
use App\Infrastructure\ValueObject\String\Path;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

class DuplicateActivityScannerTest extends ContainerTestCase
{
    private DuplicateActivityScanner $duplicateActivityScanner;
    private ActivityRepository $activityRepository;
    private FileImportRepository $fileImportRepository;

    public function testItIsDuplicateWhenFileHashAlreadyImported(): void
    {
        $file = RawActivityFile::from(Path::fromString('ride.fit'), 'raw-fit-bytes');

        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileHash($file->getHash())
                ->build()
        );

        $this->assertTrue($this->duplicateActivityScanner->isDuplicate($file));
    }

    public function testItIsDuplicateWhenStravaActivityWithSameFilenameExists(): void
    {
        $this->addActivity(ImportSource::STRAVA_API, ExternalReferenceId::fromString('ride.fit'));

        $file = RawActivityFile::from(Path::fromString('ride.fit'), 'raw-fit-bytes');

        $this->assertTrue($this->duplicateActivityScanner->isDuplicate($file));
    }

    public function testItIsNotDuplicateWhenFilenameMatchesFileImportedActivity(): void
    {
        $this->addActivity(ImportSource::FIT_FILE, ExternalReferenceId::fromString('ride.fit'));

        $file = RawActivityFile::from(Path::fromString('ride.fit'), 'raw-fit-bytes');

        $this->assertFalse($this->duplicateActivityScanner->isDuplicate($file));
    }

    public function testItIsNotDuplicateWhenNothingMatches(): void
    {
        $file = RawActivityFile::from(Path::fromString('ride.fit'), 'raw-fit-bytes');

        $this->assertFalse($this->duplicateActivityScanner->isDuplicate($file));
    }

    public function testItIsDuplicateActivityWhenStartSportAndDurationMatch(): void
    {
        $this->addActivityWithDetails(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3600,
        );

        $candidate = $this->buildActivity(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3600,
        );

        $this->assertTrue($this->duplicateActivityScanner->isDuplicateActivity($candidate));
    }

    public function testItIsDuplicateActivityWhenStartAndDurationWithinTolerance(): void
    {
        $this->addActivityWithDetails(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3600,
        );

        $candidate = $this->buildActivity(
            SerializableDateTime::fromString('2025-01-01 10:00:03'),
            SportType::RIDE,
            3603,
        );

        $this->assertTrue($this->duplicateActivityScanner->isDuplicateActivity($candidate));
    }

    public function testItIsNotDuplicateActivityWhenSportDiffers(): void
    {
        $this->addActivityWithDetails(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3600,
        );

        $candidate = $this->buildActivity(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RUN,
            3600,
        );

        $this->assertFalse($this->duplicateActivityScanner->isDuplicateActivity($candidate));
    }

    public function testItIsNotDuplicateActivityWhenStartOutsideTolerance(): void
    {
        $this->addActivityWithDetails(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3600,
        );

        $candidate = $this->buildActivity(
            SerializableDateTime::fromString('2025-01-01 10:01:00'),
            SportType::RIDE,
            3600,
        );

        $this->assertFalse($this->duplicateActivityScanner->isDuplicateActivity($candidate));
    }

    public function testItIsNotDuplicateActivityWhenDurationOutsideTolerance(): void
    {
        $this->addActivityWithDetails(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3600,
        );

        $candidate = $this->buildActivity(
            SerializableDateTime::fromString('2025-01-01 10:00:00'),
            SportType::RIDE,
            3700,
        );

        $this->assertFalse($this->duplicateActivityScanner->isDuplicateActivity($candidate));
    }

    private function buildActivity(
        SerializableDateTime $startDateTime,
        SportType $sportType,
        int $movingTimeInSeconds,
    ): \App\Domain\Activity\Activity {
        return ActivityBuilder::fromDefaults()
            ->withStartDateTime($startDateTime)
            ->withSportType($sportType)
            ->withMovingTimeInSeconds($movingTimeInSeconds)
            ->build();
    }

    private function addActivityWithDetails(
        SerializableDateTime $startDateTime,
        SportType $sportType,
        int $movingTimeInSeconds,
    ): void {
        $this->activityRepository->add(ActivityWithRawData::fromState(
            $this->buildActivity($startDateTime, $sportType, $movingTimeInSeconds),
            ['raw' => 'data'],
        ));
    }

    private function addActivity(ImportSource $importSource, ExternalReferenceId $externalReferenceId): void
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withImportSource($importSource)
            ->withExternalReferenceId($externalReferenceId)
            ->build();

        $this->activityRepository->add(ActivityWithRawData::fromState($activity, ['raw' => 'data']));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->activityRepository = new DbalActivityRepository(
            $this->getConnection(),
        );
        $this->fileImportRepository = new DbalFileImportRepository(
            $this->getConnection(),
        );
        $this->duplicateActivityScanner = new DuplicateActivityScanner(
            $this->getConnection(),
            $this->fileImportRepository,
        );
    }
}
