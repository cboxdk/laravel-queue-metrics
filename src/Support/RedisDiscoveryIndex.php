<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

use Carbon\Carbon;

/**
 * A Redis discovery set (`jobs` or `queues`) in which every member ages out on
 * its own.
 *
 * Members live in a sorted set scored by the unix time they were last seen, so
 * a job class or queue that stops running drops out once it has not been seen
 * for the `aggregated` TTL, however busy the rest of the application is. Before
 * v3.5.0 discovery was a plain set whose whole-key TTL every job start
 * refreshed, so no member ever expired while anything else ran.
 *
 * The sorted set lives under a new key (`discovery:{name}:seen`) because the
 * legacy set key cannot hold a sorted set — any sorted-set command on it fails
 * with WRONGTYPE. Readers still merge the legacy set (`discovery:{name}`), so a
 * rolling deploy with older workers writing it loses nothing; nothing writes
 * the legacy set any more, so it expires on its own TTL once the last older
 * worker stops.
 *
 * Every command touches exactly one key, which keeps the index safe on a Redis
 * Cluster, where the two keys can hash to different slots.
 *
 * @internal
 */
final readonly class RedisDiscoveryIndex
{
    public function __construct(
        private RedisMetricsStore $redis,
        private string $name,
    ) {}

    /**
     * The sorted set of members scored by when they were last seen.
     */
    public function seenKey(): string
    {
        return $this->redis->key('discovery', $this->name, 'seen');
    }

    /**
     * The plain set written by versions before v3.5.0, read only.
     */
    public function legacyKey(): string
    {
        return $this->redis->key('discovery', $this->name);
    }

    /**
     * How long a member stays discovered after it was last seen.
     */
    public function retentionSeconds(): int
    {
        return $this->redis->getTtl('aggregated');
    }

    /**
     * Mark a member as seen now.
     *
     * Takes either the store or a pipeline so registration can join the
     * transaction that records a job start. The whole-key EXPIRE is a safety
     * net: if nothing is seen for a full retention period every member is past
     * it anyway, so letting the key go loses nothing.
     */
    public function touch(RedisMetricsStore|PipelineWrapper $writer, string $member): void
    {
        $writer->addToSortedSet(
            $this->seenKey(),
            [$member => Carbon::now()->getTimestamp()],
            $this->retentionSeconds(),
        );
    }

    /**
     * Every member seen within the retention period, merged with the legacy set.
     *
     * Members not seen within the retention period are removed first, using
     * the same boundary as the database driver's per-member expiry: a member
     * seen exactly one retention period ago has expired.
     *
     * @return list<string>
     */
    public function members(): array
    {
        $seenKey = $this->seenKey();
        $cutoff = Carbon::now()->getTimestamp() - $this->retentionSeconds();

        $this->redis->removeSortedSetByScore($seenKey, '-inf', (string) $cutoff);
        $members = $this->redis->getSortedSetByRank($seenKey, 0, -1);

        $legacyKey = $this->legacyKey();
        if ($this->redis->isSet($legacyKey)) {
            $members = [...$members, ...$this->redis->getSetMembers($legacyKey)];
        }

        return array_values(array_unique($members));
    }

    /**
     * Remove members from both the sorted set and the legacy set.
     *
     * @param  array<int, string>  $members
     */
    public function forget(array $members): void
    {
        if ($members === []) {
            return;
        }

        $seenKey = $this->seenKey();
        foreach ($members as $member) {
            $this->redis->removeFromSortedSet($seenKey, $member);
        }

        $legacyKey = $this->legacyKey();
        if ($this->redis->isSet($legacyKey)) {
            $this->redis->removeFromSet($legacyKey, $members);
        }
    }
}
