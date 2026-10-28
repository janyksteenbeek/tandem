<?php

namespace App\Services\Automations;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Interprets the organization's `business_hours` schedule:
 *
 *  {
 *    "timezone": "Europe/Amsterdam",
 *    "days": { "mon": [{"start": "09:00", "end": "17:30"}], ..., "sun": [] },
 *    "holidays": ["2026-12-25"]
 *  }
 *
 * An organization without a schedule is considered always open.
 */
class BusinessHours
{
    /** @var array<int, string> */
    protected const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function hasSchedule(Organization $organization): bool
    {
        $days = $organization->business_hours['days'] ?? null;

        return is_array($days) && collect($days)->contains(fn ($segments) => is_array($segments) && $segments !== []);
    }

    public function timezone(Organization $organization): string
    {
        $timezone = $organization->business_hours['timezone'] ?? $organization->timezone ?? null;

        return is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    public function isOpen(Organization $organization, ?CarbonInterface $at = null): bool
    {
        if (! $this->hasSchedule($organization)) {
            return true;
        }

        $local = $this->localize($organization, $at);

        if ($this->isHoliday($organization, $local)) {
            return false;
        }

        $minutes = $local->hour * 60 + $local->minute;

        foreach ($this->segmentsFor($organization, $local) as [$start, $end]) {
            if ($minutes >= $start && $minutes < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * The moment business hours next start (or $at itself when already open).
     */
    public function nextOpening(Organization $organization, ?CarbonInterface $at = null): CarbonImmutable
    {
        $local = $this->localize($organization, $at);

        if ($this->isOpen($organization, $local)) {
            return $local;
        }

        $cursor = $local;

        for ($day = 0; $day < 60; $day++) {
            if (! $this->isHoliday($organization, $cursor)) {
                $minutes = $day === 0 ? $cursor->hour * 60 + $cursor->minute : -1;

                foreach ($this->segmentsFor($organization, $cursor) as [$start, $end]) {
                    if ($start > $minutes) {
                        return $cursor->startOfDay()->addMinutes($start);
                    }
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $local;
    }

    /**
     * The start of the next day (strictly after $at) with any business hours. Falls back to the
     * next weekday when the organization has no schedule.
     */
    public function nextBusinessDay(Organization $organization, ?CarbonInterface $at = null): CarbonImmutable
    {
        $cursor = $this->localize($organization, $at)->addDay()->startOfDay();

        for ($day = 0; $day < 60; $day++) {
            $hasHours = $this->hasSchedule($organization)
                ? $this->segmentsFor($organization, $cursor) !== []
                : $cursor->isWeekday();

            if ($hasHours && ! $this->isHoliday($organization, $cursor)) {
                return $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $cursor;
    }

    /**
     * Open/closed transitions that happened in the (from, to] window, in chronological order.
     * The window is clamped to 24 hours so a stalled scheduler never scans unbounded ranges.
     *
     * @return array<int, array{at: CarbonImmutable, open: bool}>
     */
    public function transitionsBetween(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->hasSchedule($organization)) {
            return [];
        }

        $to = $this->localize($organization, $to)->startOfMinute();
        $from = $this->localize($organization, $from)->startOfMinute();

        if ($from->lt($to->subDay())) {
            $from = $to->subDay();
        }

        if ($from->gte($to)) {
            return [];
        }

        $transitions = [];
        $state = $this->isOpen($organization, $from);
        $cursor = $from->addMinute();

        while ($cursor->lte($to)) {
            $open = $this->isOpen($organization, $cursor);

            if ($open !== $state) {
                $transitions[] = ['at' => $cursor, 'open' => $open];
                $state = $open;
            }

            $cursor = $cursor->addMinute();
        }

        return $transitions;
    }

    public function localize(Organization $organization, ?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone($organization));
    }

    protected function isHoliday(Organization $organization, CarbonImmutable $local): bool
    {
        $holidays = $organization->business_hours['holidays'] ?? [];

        return is_array($holidays) && in_array($local->toDateString(), $holidays, true);
    }

    /**
     * Opening segments for the weekday of $local as [startMinute, endMinute] pairs.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    protected function segmentsFor(Organization $organization, CarbonImmutable $local): array
    {
        $key = self::DAY_KEYS[$local->dayOfWeekIso - 1];
        $raw = $organization->business_hours['days'][$key] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $segments = [];

        foreach ($raw as $segment) {
            $start = $this->minutes($segment['start'] ?? null);
            $end = $this->minutes($segment['end'] ?? null);

            if ($start === null || $end === null || $end <= $start) {
                continue;
            }

            $segments[] = [$start, $end];
        }

        usort($segments, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $segments;
    }

    protected function minutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 24 || $minutes > 59 || Str::length($m[2]) !== 2) {
            return null;
        }

        return min($hours * 60 + $minutes, 24 * 60);
    }
}
