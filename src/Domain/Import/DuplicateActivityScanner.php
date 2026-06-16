<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ImportSource;
use App\Domain\Import\FileParser\RawActivityFile;
use Doctrine\DBAL\Connection;

final readonly class DuplicateActivityScanner
{
    /**
     * Tolerance (in seconds) applied to both the start time and the moving time
     * when matching a parsed activity against already-imported activities.
     * Re-exports of the same activity (e.g. different FIT exports from Polar)
     * can differ by a few seconds due to rounding/metadata.
     */
    private const int MATCH_TOLERANCE_SECONDS = 5;

    public function __construct(
        private Connection $connection,
        private FileImportRepository $fileImportRepository,
    ) {
    }

    public function isDuplicate(RawActivityFile $file): bool
    {
        // Same file content already imported (file -> file).
        if ($this->fileImportRepository->existsForFileHash($file->getHash())) {
            return true;
        }

        // Same activity already imported from Strava (strava -> file),
        // matched on the uploaded file's name.
        return $this->existsStravaActivityForFilename($file->getPath()->getFilename());
    }

    /**
     * Catches logically-identical activities that differ at the byte level
     * (so their file hash does not match), by matching on the activity's
     * natural identity: start time + sport type + moving time, each within
     * a small tolerance.
     */
    public function isDuplicateActivity(Activity $activity): bool
    {
        $startDateTime = $activity->getStartDate();
        $from = $startDateTime->modify(sprintf('-%d seconds', self::MATCH_TOLERANCE_SECONDS))->format('Y-m-d H:i:s');
        $to = $startDateTime->modify(sprintf('+%d seconds', self::MATCH_TOLERANCE_SECONDS))->format('Y-m-d H:i:s');

        $movingTimeInSeconds = $activity->getMovingTimeInSeconds();

        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('Activity')
            ->andWhere('startDateTime BETWEEN :from AND :to')
            ->andWhere('sportType = :sportType')
            ->andWhere('movingTimeInSeconds BETWEEN :movingTimeFrom AND :movingTimeTo')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('sportType', $activity->getSportType()->value)
            ->setParameter('movingTimeFrom', $movingTimeInSeconds - self::MATCH_TOLERANCE_SECONDS)
            ->setParameter('movingTimeTo', $movingTimeInSeconds + self::MATCH_TOLERANCE_SECONDS)
            ->executeQuery()
            ->fetchOne();

        return (int) $count > 0;
    }

    private function existsStravaActivityForFilename(string $filename): bool
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('Activity')
            ->andWhere('externalReferenceId = :externalReferenceId')
            ->andWhere('importSource = :importSource')
            ->setParameter('externalReferenceId', $filename)
            ->setParameter('importSource', ImportSource::STRAVA_API->value)
            ->executeQuery()
            ->fetchOne();

        return (int) $count > 0;
    }
}
