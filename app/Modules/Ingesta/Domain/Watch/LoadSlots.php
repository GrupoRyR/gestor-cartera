<?php

namespace App\Modules\Ingesta\Domain\Watch;

use DateTimeZone;
use UnexpectedValueException;

/**
 * The load slots of etl/horarios.json: when the robot runs (and later the
 * Bases SAP reader), in which timezone, and how many minutes after each
 * slot ingesta:vigilar-carga checks that its load arrived. The file shape:
 *
 *     {"zona": "America/Bogota", "vigilante_min": 30, "horarios": [
 *       {"hora": "05:00"}, {"hora": "16:00", "dias": ["wed"], "antes_de": "…"}]}
 *
 * Validated strictly: a bad time, day, zone or delay, a repeated slot or a
 * watch that would fall after midnight throws UnexpectedValueException
 * with what to fix. Without the file, the four DEFAULT slots apply.
 */
final readonly class LoadSlots
{
    /** The slots of the versioned etl/horarios.json. */
    public const array DEFAULT = [
        'zona' => 'America/Bogota',
        'vigilante_min' => 30,
        'horarios' => [
            ['hora' => '00:00'],
            ['hora' => '05:00'],
            ['hora' => '12:00'],
            ['hora' => '16:00', 'dias' => ['wed'], 'antes_de' => 'el cobro semanal de las 17:00'],
        ],
    ];

    /**
     * @param  list<LoadSlot>  $slots
     */
    private function __construct(
        public string $timezone,
        public int $watchDelay,
        public array $slots,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray(self::DEFAULT);
    }

    /**
     * The slots of the JSON file at $path; the defaults when it does not exist.
     *
     * @throws UnexpectedValueException When the file is unreadable, not JSON or invalid.
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return self::defaults();
        }

        $json = @file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)) {
            throw new UnexpectedValueException("The load slots file [{$path}] is not valid JSON.");
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<mixed>  $data  The decoded file; zona and vigilante_min default to Bogotá and 30.
     *
     * @throws UnexpectedValueException When a value is invalid.
     */
    public static function fromArray(array $data): self
    {
        $zone = $data['zona'] ?? 'America/Bogota';

        if (! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            throw new UnexpectedValueException('Load slots: "zona" must be a timezone such as America/Bogota.');
        }

        $delay = $data['vigilante_min'] ?? 30;

        if (! is_int($delay) || $delay < 1 || $delay > 180) {
            throw new UnexpectedValueException('Load slots: "vigilante_min" must be a whole number of minutes from 1 to 180.');
        }

        $entries = $data['horarios'] ?? null;

        if (! is_array($entries) || $entries === []) {
            throw new UnexpectedValueException('Load slots: "horarios" must list at least one slot.');
        }

        $slots = [];

        foreach (array_values($entries) as $index => $entry) {
            $slot = self::slot(is_array($entry) ? $entry : [], $index + 1);

            if (isset($slots[$slot->time])) {
                throw new UnexpectedValueException("Load slots: the slot {$slot->time} is listed twice.");
            }

            if ($slot->hour() * 60 + $slot->minute() + $delay >= 24 * 60) {
                throw new UnexpectedValueException("Load slots: the watch of the slot {$slot->time} would run after midnight; move the slot or shorten vigilante_min.");
            }

            $slots[$slot->time] = $slot;
        }

        return new self($zone, $delay, array_values($slots));
    }

    /**
     * The slot at $time (HH:MM); null when there is none.
     */
    public function find(string $time): ?LoadSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->time === $time) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $entry
     */
    private static function slot(array $entry, int $position): LoadSlot
    {
        $time = $entry['hora'] ?? null;

        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new UnexpectedValueException("Load slots: slot {$position} needs \"hora\" as a HH:MM time, such as 05:00.");
        }

        $days = $entry['dias'] ?? [];

        if (! is_array($days)) {
            throw new UnexpectedValueException("Load slots: \"dias\" of the slot {$time} must be a list of day names.");
        }

        foreach ($days as $day) {
            if (! is_string($day) || ! array_key_exists($day, LoadSlot::DAYS)) {
                throw new UnexpectedValueException("Load slots: the slot {$time} has an unknown day; use ".implode(', ', array_keys(LoadSlot::DAYS)).'.');
            }
        }

        $before = $entry['antes_de'] ?? null;

        if ($before !== null && (! is_string($before) || trim($before) === '')) {
            throw new UnexpectedValueException("Load slots: \"antes_de\" of the slot {$time} must be a text.");
        }

        /** @var list<string> $days */
        $days = array_values(array_unique($days));

        return new LoadSlot($time, $days, $before === null ? null : trim($before));
    }
}
