@props(['size' => 'full'])

@php
    use Carbon\Carbon;
    use Illuminate\Support\Str;

    // ─── Configuration ─────────────────────────────────────────────────────
    $tz = config('app.timezone');
    $dayStartHour  = 8;
    $dayEndHour    = 22;
    $now           = Carbon::now($tz);
    $periodStart   = $now->copy()->startOfDay();
    $periodEnd     = $periodStart->copy()->addDays(3)->endOfDay();

    // ─── Settings ──────────────────────────────────────────────────────────
    $settings = $trmnl['plugin_settings']['custom_fields_values'] ?? [];

    $showHeader = filter_var(
        $settings['display_header'] ?? true,
        FILTER_VALIDATE_BOOLEAN
    );

    $names = collect(explode(',', (string) ($settings['names'] ?? '')))
        ->map(fn ($name) => trim($name))
        ->filter()
        ->values();

    // ─── iCal date parser ─────────────────────────────────────────────────
    // Supports: YYYYMMDD, YYYYMMDDTHHMM(SS)?, optional trailing Z (UTC),
    // and an optional iCal parameter prefix like "TZID=Europe/Warsaw:VALUE".
    $parseDate = function ($value, $default = null) use ($tz) {
        if (! $value) {
            return $default;
        }

        try {
            $value = trim((string) $value);

            // Strip iCal parameter prefix e.g. "TZID=Europe/Warsaw:20260912T140000".
            if (str_contains($value, ':')) {
                [$head, $tail] = explode(':', $value, 2);

                if (str_contains($head, '=')) {
                    $value = $tail;
                }
            }

            if (preg_match('/^\d{8}$/', $value)) {
                return Carbon::createFromFormat('Ymd', $value, $tz)
                    ->startOfDay();
            }

            if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
                return Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC')
                    ->setTimezone($tz);
            }

            if (preg_match('/^\d{8}T\d{6}$/', $value)) {
                return Carbon::createFromFormat('Ymd\THis', $value, $tz);
            }

            if (preg_match('/^\d{8}T\d{4}Z$/', $value)) {
                return Carbon::createFromFormat('Ymd\THi\Z', $value, 'UTC')
                    ->setTimezone($tz);
            }

            if (preg_match('/^\d{8}T\d{4}$/', $value)) {
                return Carbon::createFromFormat('Ymd\THi', $value, $tz);
            }

            return Carbon::parse($value, $tz)->setTimezone($tz);
        } catch (\Throwable $e) {
            return $default;
        }
    };

    // ─── Calendars (with fallback) ────────────────────────────────────────
    $calendarBuckets = collect($data ?? [])
        ->filter(fn ($value, $key) => Str::startsWith((string) $key, 'IDX_') && is_array($value))
        ->sortKeysUsing(fn ($a, $b) => ((int) Str::after($a, 'IDX_')) <=> ((int) Str::after($b, 'IDX_')))
        ->values();

    // If LaraPaper hasn't produced any IDX_* buckets, still render an empty grid.
    if ($calendarBuckets->isEmpty()) {
        $calendarBuckets = collect([['ical' => []]]);
    }

    $calendarCount = $calendarBuckets->count();

    // ─── Collect raw events ───────────────────────────────────────────────
    $events = collect();

    foreach ($calendarBuckets as $calendarIndex => $bucket) {
        $calendarName = $names->get($calendarIndex) ?: 'CAL ' . ($calendarIndex + 1);

        $icalEvents = is_array($bucket['ical'] ?? null) ? $bucket['ical'] : [];

        foreach ($icalEvents as $rawEvent) {
            if (! is_array($rawEvent)) {
                continue;
            }

            $rawStart = $rawEvent['DTSTART'] ?? null;

            if (! $rawStart) {
                continue;
            }

            $start = $parseDate($rawStart);

            if (! $start) {
                continue;
            }

            $isAllDay = ! Str::contains((string) $rawStart, 'T');

            $defaultEnd = $isAllDay
                ? $start->copy()->addDay()
                : $start->copy()->addHour();

            $end = $parseDate($rawEvent['DTEND'] ?? null, $defaultEnd);

            if (! $end || $end->lte($start)) {
                $end = $defaultEnd;
            }

            // Skip events that don't intersect the visible period.
            if ($end->lte($periodStart) || $start->gte($periodEnd)) {
                continue;
            }

            $events->push([
                'summary'        => trim((string) ($rawEvent['SUMMARY'] ?? 'Untitled')),
                'start'          => $start,
                'end'            => $end,
                'all_day'        => $isAllDay,
                'calendar_name'  => $calendarName,
                'calendar_index' => $calendarIndex,
            ]);
        }
    }

    // ─── Days axis (today + next two) ─────────────────────────────────────
    $days = collect(range(0, 2))->map(function ($offset) use ($periodStart, $now) {
        $date = $periodStart->copy()->addDays($offset);

        return [
            'date'     => $date,
            'key'      => $date->format('Y-m-d'),
            'is_today' => $date->isSameDay($now),
        ];
    });

    // ─── Split timed events across day windows [08:00–22:00] ──────────────
    $preparedTimedEvents = collect();

    foreach ($events->where('all_day', false) as $event) {
        foreach ($days as $day) {
            $dayStart = $day['date']->copy()->setTime($dayStartHour, 0, 0);
            $dayEnd   = $day['date']->copy()->setTime($dayEndHour, 0, 0);

            // Clamp event to visible window.
            $visibleStart = $event['start']->greaterThan($dayStart)
                ? $event['start']->copy()
                : $dayStart->copy();

            $visibleEnd = $event['end']->lessThan($dayEnd)
                ? $event['end']->copy()
                : $dayEnd->copy();

            if ($visibleEnd->lte($visibleStart)) {
                continue;
            }

            $totalSeconds = max(1, $dayEnd->timestamp - $dayStart->timestamp);

            $top    = (($visibleStart->timestamp - $dayStart->timestamp) / $totalSeconds) * 100;
            $height = (($visibleEnd->timestamp - $visibleStart->timestamp) / $totalSeconds) * 100;

            $preparedTimedEvents->push([
                'summary'        => $event['summary'],
                'start'          => $event['start'],
                'end'            => $event['end'],
                'calendar_index' => $event['calendar_index'],
                'day_key'        => $day['key'],
                'top'            => max(0, min(100, $top)),
                'height'         => max(0.8, min(100 - $top, $height)),
                'start_minutes'  => $visibleStart->hour * 60 + $visibleStart->minute,
                'end_minutes'    => $visibleEnd->hour * 60 + $visibleEnd->minute,
            ]);
        }
    }

    // ─── Lane assignment per (day, calendar) for overlap handling ─────────
    $eventsByDay = $preparedTimedEvents
        ->groupBy('day_key')
        ->map(function ($dayEvents) {
            return $dayEvents
                ->groupBy('calendar_index')
                ->map(function ($calendarEvents) {
                    $sorted = $calendarEvents
                        ->sortBy(fn ($e) => [$e['start_minutes'], -$e['end_minutes']])
                        ->values();

                    $columns  = [];
                    $assigned = [];

                    foreach ($sorted as $event) {
                        $column = 0;

                        while (
                            isset($columns[$column])
                            && $event['start_minutes'] < $columns[$column]
                        ) {
                            $column++;
                        }

                        $columns[$column] = $event['end_minutes'];

                        $assigned[] = [
                            'event'  => $event,
                            'column' => $column,
                        ];
                    }

                    $columnCount = max(1, count($columns));

                    return collect($assigned)->map(fn ($item) => array_merge(
                        $item['event'],
                        [
                            'column'       => $item['column'],
                            'column_count' => $columnCount,
                        ]
                    ));
                });
        });

    // ─── Per-size UI tuning ───────────────────────────────────────────────
    $sizeConfig = [
        'full' => [
            'dayHeaderHeight'      => 38,
            'calendarHeaderHeight' => 22,
            'fontDay'              => 12,
            'fontDate'             => 16,
            'fontCalendar'         => 8,
            'fontTime'             => 9,
            'fontEvent'            => 10,
            'leftWidth'            => 38,
            'eventPadding'         => 2,
            'minEventHeight'       => 14,
        ],

        'half_horizontal' => [
            'dayHeaderHeight'      => 30,
            'calendarHeaderHeight' => 18,
            'fontDay'              => 9,
            'fontDate'             => 12,
            'fontCalendar'         => 6,
            'fontTime'             => 7,
            'fontEvent'            => 7,
            'leftWidth'            => 28,
            'eventPadding'         => 1,
            'minEventHeight'       => 10,
        ],

        'half_vertical' => [
            'dayHeaderHeight'      => 30,
            'calendarHeaderHeight' => 18,
            'fontDay'              => 9,
            'fontDate'             => 12,
            'fontCalendar'         => 6,
            'fontTime'             => 7,
            'fontEvent'            => 7,
            'leftWidth'            => 28,
            'eventPadding'         => 1,
            'minEventHeight'       => 12,
        ],

        'quadrant' => [
            'dayHeaderHeight'      => 22,
            'calendarHeaderHeight' => 14,
            'fontDay'              => 6,
            'fontDate'             => 9,
            'fontCalendar'         => 5,
            'fontTime'             => 6,
            'fontEvent'            => 6,
            'leftWidth'            => 22,
            'eventPadding'         => 0,
            'minEventHeight'       => 8,
        ],
    ];

    $ui = $sizeConfig[$size] ?? $sizeConfig['full'];

    $periodLabel = $periodStart->format('M j')
        . ' – '
        . $periodStart->copy()->addDays(2)->format('M j');

    $scope = 'three-day-calendar-' . Str::random(6);
@endphp

<x-trmnl::view size="{{ $size }}">
    <x-trmnl::layout class="layout--col w--full h--full" style="padding:0; gap:0;">
        <div id="{{ $scope }}" class="three-day-calendar">
            <style>
                #{{ $scope }} {
                    width: 100%;
                    height: 100%;
                    overflow: hidden;
                    color: #000;
                    background: #fff;
                    font-family: Arial, Helvetica, sans-serif;

                    --day-header-height: {{ $ui['dayHeaderHeight'] }}px;
                    --calendar-header-height: {{ $ui['calendarHeaderHeight'] }}px;
                    --font-day: {{ $ui['fontDay'] }}px;
                    --font-date: {{ $ui['fontDate'] }}px;
                    --font-calendar: {{ $ui['fontCalendar'] }}px;
                    --font-time: {{ $ui['fontTime'] }}px;
                    --font-event: {{ $ui['fontEvent'] }}px;
                    --left-width: {{ $ui['leftWidth'] }}px;
                    --event-padding: {{ $ui['eventPadding'] }}px;
                    --min-event-height: {{ $ui['minEventHeight'] }}px;
                }

                #{{ $scope }} *,
                #{{ $scope }} *::before,
                #{{ $scope }} *::after {
                    box-sizing: border-box;
                }

                #{{ $scope }} .calendar-grid {
                    display: grid;
                    grid-template-columns:
                        var(--left-width)
                        repeat({{ $calendarCount * 3 }}, minmax(0, 1fr));
                    grid-template-rows: auto auto 1fr;
                    width: 100%;
                    height: 100%;
                    position: relative;
                }

                #{{ $scope }} .corner {
                    border-bottom: 1px solid #000;
                }

                #{{ $scope }} .day-header {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                    min-width: 0;
                    height: var(--day-header-height);
                    border-right: 1px solid #000;
                    border-bottom: 1px solid #000;
                    overflow: hidden;
                }

                /*
                 * The current day is always the leftmost block ($days[0]),
                 * highlighted via an absolutely-positioned overlay frame.
                 * Using `position: absolute` (rather than an explicit grid
                 * placement) keeps the frame out of the auto-placement
                 * algorithm — otherwise it would occupy today's grid cells
                 * and push the real day headers / columns elsewhere.
                 * Coordinates: right after the time axis, one third of the
                 * remaining width, full height of the grid.
                 */
                #{{ $scope }} .today-frame {
                    position: absolute;
                    top: 0;
                    left: var(--left-width);
                    width: calc((100% - var(--left-width)) / 3);
                    height: 100%;
                    border: 2px solid #000;
                    pointer-events: none;
                    z-index: 10;
                }

                #{{ $scope }} .day-name {
                    font-size: var(--font-day);
                    font-weight: 700;
                    text-transform: uppercase;
                }

                #{{ $scope }} .day-number {
                    font-size: var(--font-date);
                    font-weight: 700;
                }

                #{{ $scope }} .calendar-header {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 0;
                    height: var(--calendar-header-height);
                    padding: 0 2px;
                    border-right: 1px solid #000;
                    border-bottom: 1px solid #000;
                    overflow: hidden;
                    white-space: nowrap;
                    text-overflow: ellipsis;
                    font-size: var(--font-calendar);
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.03em;
                }

                #{{ $scope }} .time-column,
                #{{ $scope }} .day-column {
                    position: relative;
                    min-height: 0;
                    overflow: hidden;
                }

                #{{ $scope }} .day-column {
                    border-right: 1px solid #000;
                }

                #{{ $scope }} .time-label {
                    position: absolute;
                    left: 0;
                    right: 2px;
                    transform: translateY(-50%);
                    text-align: right;
                    font-size: var(--font-time);
                    line-height: 1;
                    color: #000;
                }

                #{{ $scope }} .hour-line,
                #{{ $scope }} .half-hour-line {
                    position: absolute;
                    left: 0;
                    right: 0;
                    pointer-events: none;
                }

                #{{ $scope }} .hour-line {
                    border-top: 1px solid #888;
                }

                #{{ $scope }} .half-hour-line {
                    border-top: 1px dotted #bbb;
                }

                /*
                 * Events: clean white pill with thin black border and black text.
                 * On today's grey column the white background keeps events legible.
                 */
                #{{ $scope }} .event {
                    position: absolute;
                    z-index: 5;
                    overflow: hidden;
                    padding: var(--event-padding);
                    color: #000;
                    background: #fff;
                    border: 1px solid #000;
                    border-radius: 2px;
                    font-size: var(--font-event);
                    font-weight: 600;
                    line-height: 1.05;
                    overflow-wrap: anywhere;
                    min-height: var(--min-event-height);
                }

                #{{ $scope }} .event-title {
                    overflow: hidden;
                }
            </style>

            <div class="calendar-grid">
                {{-- Row 1: day headers ------------------------------------------------ --}}
                <div class="corner"></div>

                @foreach ($days as $day)
                    <div
                        class="day-header {{ $day['is_today'] ? 'today' : '' }}"
                        style="grid-column: span {{ $calendarCount }};"
                    >
                        <span class="day-name">
                            {{ $day['date']->format('D') }}
                        </span>

                        <span class="day-number">
                            {{ $day['date']->format('j') }}
                        </span>
                    </div>
                @endforeach

                {{-- Row 2: calendar sub-headers ---------------------------------------- --}}
                <div class="corner"></div>

                @foreach ($days as $day)
                    @foreach ($calendarBuckets as $calendarIndex => $bucket)
                        @php
                            $calendarName = $names->get($calendarIndex)
                                ?: 'CAL ' . ($calendarIndex + 1);
                        @endphp

                        <div class="calendar-header {{ $day['is_today'] ? 'today' : '' }}">
                            {{ $calendarName }}
                        </div>
                    @endforeach
                @endforeach

                {{-- Row 3: time axis + day/calendar grids ------------------------------ --}}
                <div class="time-column">
                    @for ($hour = $dayStartHour; $hour <= $dayEndHour; $hour++)
                        @php
                            $top = (($hour - $dayStartHour) / ($dayEndHour - $dayStartHour)) * 100;
                        @endphp

                        <div class="time-label" style="top: {{ $top }}%;">
                            {{ sprintf('%02d:00', $hour) }}
                        </div>
                    @endfor
                </div>

                @foreach ($days as $day)
                    @foreach ($calendarBuckets as $calendarIndex => $bucket)
                        @php
                            $dayEvents = $eventsByDay
                                ->get($day['key'], collect())
                                ->get($calendarIndex, collect());
                        @endphp

                        <div class="day-column {{ $day['is_today'] ? 'today' : '' }}">
                            {{-- Hour + half-hour gridlines --}}
                            @for ($hour = $dayStartHour; $hour < $dayEndHour; $hour++)
                                @php
                                    $top = (($hour - $dayStartHour) / ($dayEndHour - $dayStartHour)) * 100;
                                    $halfTop = $top + (50 / ($dayEndHour - $dayStartHour));
                                @endphp

                                <div class="hour-line" style="top: {{ $top }}%;"></div>
                                <div class="half-hour-line" style="top: {{ $halfTop }}%;"></div>
                            @endfor

                            <div class="hour-line" style="top: 100%;"></div>

                            {{-- Events for this (day, calendar) --}}
                            @foreach ($dayEvents as $event)
                                @php
                                    $width = 100 / max(1, $event['column_count']);
                                    $left  = $event['column'] * $width;
                                @endphp

                                <div
                                    class="event"
                                    style="
                                        top: {{ $event['top'] }}%;
                                        height: {{ $event['height'] }}%;
                                        left: calc({{ $left }}% + 1px);
                                        width: calc({{ $width }}% - 2px);
                                    "
                                >
                                    <div class="event-title">
                                        {{ $event['summary'] }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                @endforeach

                {{-- Bold frame overlay for today (always the first day block). --}}
                <div class="today-frame"></div>
            </div>
        </div>
    </x-trmnl::layout>

    @if ($showHeader)
        <div class="title_bar">
            <span class="title">Calendar</span>
            <span class="instance">{{ $periodLabel }}</span>
        </div>
    @endif
</x-trmnl::view>
