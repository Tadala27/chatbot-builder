<?php

namespace App\Services\Bot;

class HandoverAvailability
{
    /**
     * @param array|null $operatingHours BotConfiguration::operating_hours — keyed by
     *                                   lowercase day name, each a { enabled, open, close, timezone } shape
     *                                   (see resources/ts/composables/botSettings.ts::DaySchedule).
     */
    public function isInHours(?array $operatingHours): bool
    {
        // No schedule configured — don't block handover on an unset feature.
        if (empty($operatingHours)) {
            return true;
        }

        $dayKey = strtolower(now()->format('l'));
        $schedule = $operatingHours[$dayKey] ?? null;

        if (!$schedule || !($schedule['enabled'] ?? false)) {
            return false;
        }

        $open = $schedule['open'] ?? null;
        $close = $schedule['close'] ?? null;

        if (!$open || !$close) {
            return false;
        }

        $timezone = $schedule['timezone'] ?? config('app.timezone');
        $now = now($timezone);

        [$openHour, $openMinute] = array_pad(array_map('intval', explode(':', $open)), 2, 0);
        [$closeHour, $closeMinute] = array_pad(array_map('intval', explode(':', $close)), 2, 0);

        $openAt = $now->copy()->setTime($openHour, $openMinute, 0);
        $closeAt = $now->copy()->setTime($closeHour, $closeMinute, 0);

        // Overnight windows (e.g. open 22:00, close 02:00) — if close is
        // earlier than open, treat it as spilling into the next day.
        if ($closeAt->lessThanOrEqualTo($openAt)) {
            $closeAt->addDay();
        }

        return $now->between($openAt, $closeAt);
    }
}