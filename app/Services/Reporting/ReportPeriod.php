<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportPeriod
{
    public readonly CarbonImmutable $start;
    public readonly CarbonImmutable $end;

    public function __construct(public readonly string $from, public readonly string $to, public readonly string $timezone)
    {
        $this->start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $this->end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay();
        if ($this->start >= $this->end || $this->start->diffInDays($this->end) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose an ordered date range of at most 366 calendar days.']);
        }
    }

    public static function fromRequest(Request $request, string $timezone): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
        ]);
        $to = $data['to'] ?? CarbonImmutable::now($timezone)->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to, $timezone)->subDays(29)->toDateString();

        return new self($from, $to, $timezone);
    }

    public function days(): array
    {
        $days = [];
        for ($day = $this->start; $day < $this->end; $day = $day->addDay()->startOfDay()) {
            $days[$day->toDateString()] = $day->addDay()->startOfDay()->utc()->toDateTimeString();
        }

        return $days;
    }

    public function constrain($query, string $column)
    {
        return $query->where($column, '>=', $this->start->utc()->toDateTimeString())
            ->where($column, '<', $this->end->utc()->toDateTimeString());
    }

    public function filters(): array { return ['from' => $this->from, 'to' => $this->to]; }
}
