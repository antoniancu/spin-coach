<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DynamicDJService
{
    private const CACHE_PREFIX = 'dj:';
    private const HR_THRESHOLD_HIGH = 160;
    private const HR_THRESHOLD_MAX = 180;
    private const MIN_BPM = 90;
    private const MAX_BPM = 180;

    public function __construct(
        private readonly SpotifyService $spotify,
    ) {}

    /**
     * Calculate target music BPM from rider state.
     *
     * Base = cadence RPM (natural 1:1 mapping for cycling).
     * If HR is elevated, gradually reduce target to help recovery.
     */
    public function calculateTargetBPM(int $cadenceRpm, ?int $heartRateBpm = null): int
    {
        $target = (float) $cadenceRpm;

        // Scale down if heart rate is high
        if ($heartRateBpm !== null && $heartRateBpm > self::HR_THRESHOLD_HIGH) {
            $overBy = $heartRateBpm - self::HR_THRESHOLD_HIGH;
            $range = self::HR_THRESHOLD_MAX - self::HR_THRESHOLD_HIGH;
            // Linear reduction: up to 20% slower at max HR
            $reduction = min(1.0, $overBy / $range) * 0.20;
            $target *= (1.0 - $reduction);
        }

        return (int) max(self::MIN_BPM, min(self::MAX_BPM, round($target)));
    }

    /**
     * Get the next track recommendation and queue it.
     *
     * @return array{queued: bool, track: ?array, target_bpm: int}
     */
    public function queueNextTrack(
        int $sessionId,
        int $cadenceRpm,
        ?int $heartRateBpm = null,
        ?string $deviceId = null,
    ): array {
        $targetBpm = $this->calculateTargetBPM($cadenceRpm, $heartRateBpm);
        $recentKey = self::CACHE_PREFIX . $sessionId . ':recent';
        $recentUris = Cache::get($recentKey, []);

        // Get current track as seed
        $seedTrackIds = [];
        try {
            $current = $this->spotify->getNowPlayingFull();
            if ($current && !empty($current['id'])) {
                $seedTrackIds[] = $current['id'];
            }
        } catch (\RuntimeException $e) {
            Log::warning('DJ: could not get current track', ['error' => $e->getMessage()]);
        }

        // Adjust energy based on workout intensity
        $minEnergy = $cadenceRpm > 90 ? 0.6 : 0.4;

        try {
            $recommendations = $this->spotify->getRecommendations(
                targetTempo: (float) $targetBpm,
                seedTrackIds: $seedTrackIds,
                seedGenres: empty($seedTrackIds) ? ['work-out', 'electronic', 'pop'] : [],
                minEnergy: $minEnergy,
                limit: 20,
            );
        } catch (\RuntimeException $e) {
            Log::warning('DJ: recommendation request failed', ['error' => $e->getMessage()]);
            return ['queued' => false, 'track' => null, 'target_bpm' => $targetBpm];
        }

        if (empty($recommendations)) {
            return ['queued' => false, 'track' => null, 'target_bpm' => $targetBpm];
        }

        // Get audio features to find best BPM match
        $trackIds = array_map(fn ($t) => $t['id'], $recommendations);
        $features = $this->spotify->getAudioFeatures($trackIds);

        // Score and pick best track not recently played
        $bestTrack = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($recommendations as $track) {
            if (in_array($track['uri'], $recentUris, true)) {
                continue;
            }

            $trackFeatures = $features[$track['id']] ?? null;
            if (!$trackFeatures) {
                continue;
            }

            // Score = distance from target BPM (also consider half-time matches)
            $tempo = $trackFeatures['tempo'];
            $directDiff = abs($tempo - $targetBpm);
            $halfTimeDiff = abs(($tempo / 2) - $targetBpm);
            $doubleTimeDiff = abs(($tempo * 2) - $targetBpm);
            $bpmDiff = min($directDiff, $halfTimeDiff, $doubleTimeDiff);

            // Prefer higher energy tracks
            $energyBonus = $trackFeatures['energy'] * -5;

            $score = $bpmDiff + $energyBonus;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestTrack = $track;
                $bestTrack['tempo'] = $tempo;
                $bestTrack['energy'] = $trackFeatures['energy'];
            }
        }

        if (!$bestTrack) {
            return ['queued' => false, 'track' => null, 'target_bpm' => $targetBpm];
        }

        // Queue it
        try {
            $this->spotify->queueTrack($bestTrack['uri'], $deviceId);
        } catch (\RuntimeException $e) {
            Log::warning('DJ: queue failed', ['error' => $e->getMessage()]);
            return ['queued' => false, 'track' => $bestTrack, 'target_bpm' => $targetBpm];
        }

        // Track recently played (keep last 30)
        $recentUris[] = $bestTrack['uri'];
        if (count($recentUris) > 30) {
            $recentUris = array_slice($recentUris, -30);
        }
        Cache::put($recentKey, $recentUris, now()->addHours(2));

        return ['queued' => true, 'track' => $bestTrack, 'target_bpm' => $targetBpm];
    }

    /**
     * Clear DJ state for a session.
     */
    public function clearSession(int $sessionId): void
    {
        Cache::forget(self::CACHE_PREFIX . $sessionId . ':recent');
    }
}
