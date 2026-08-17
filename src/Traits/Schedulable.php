<?php

namespace Obelaw\Runner\Traits;

use Carbon\Carbon;
use Cron\CronExpression;
use Obelaw\Runner\Models\RunnerModel;

trait Schedulable
{
    /**
     * The cron expression for scheduling.
     * Examples:
     * - '* * * * *' (every minute)
     * - '0 * * * *' (hourly)
     * - '0 0 * * *' (daily at midnight)
     * - '0 0 * * 0' (weekly on Sunday)
     * - '0 0 1 * *' (monthly on the 1st)
     * - '0 8-23,0-3 * * *' (at minute 0, hours 8-23 and 0-3)
     *
     * @var string|null
     */

    /**
     * The cron expression for scheduling.
     * @var string|null
     */
    protected ?string $schedule = null;

    /**
     * Check if the runner should run based on schedule.
     *
     * @return bool
     */
    public function shouldRunBySchedule(): bool
    {
        if (!$this->schedule) {
            return true; // No schedule defined, always run
        }

        $lastRun = $this->getLastRunTime();

        // If never run, check if current time matches schedule
        if (!$lastRun) {
            return $this->isDue();
        }

        // Check if enough time has passed based on schedule
        return $this->isDue() && !$this->hasRunInCurrentPeriod($lastRun);
    }

    /**
     * Check if the current time matches the cron schedule.
     *
     * @return bool
     */
    protected function isDue(): bool
    {
        if (!$this->schedule) {
            return true;
        }

        try {
            $cron = new CronExpression($this->schedule);
            return $cron->isDue(Carbon::now());
        } catch (\Exception $e) {
            // Invalid cron expression
            return false;
        }
    }

    /**
     * Check if the runner has already run in the current scheduled period.
     *
     * @param Carbon $lastRun
     * @return bool
     */
    protected function hasRunInCurrentPeriod(Carbon $lastRun): bool
    {
        if (!$this->schedule) {
            return false;
        }

        try {
            $cron = new CronExpression($this->schedule);
            $now = Carbon::now();
            
            // Get the previous run time according to the cron schedule
            $previousRunTime = Carbon::instance($cron->getPreviousRunDate($now));
            
            // Check if the last run was after or at the previous scheduled run time
            return $lastRun->greaterThanOrEqualTo($previousRunTime);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the next run time based on the schedule.
     *
     * @return Carbon|null
     */
    public function getNextRunTime(): ?Carbon
    {
        if (!$this->schedule) {
            return null;
        }

        try {
            $cron = new CronExpression($this->schedule);
            return Carbon::instance($cron->getNextRunDate());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the previous run time based on the schedule.
     *
     * @return Carbon|null
     */
    public function getPreviousRunTime(): ?Carbon
    {
        if (!$this->schedule) {
            return null;
        }

        try {
            $cron = new CronExpression($this->schedule);
            return Carbon::instance($cron->getPreviousRunDate());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the last run time from database.
     *
     * @return Carbon|null
     */
    protected function getLastRunTime(): ?Carbon
    {
        $runnerName = $this->getRunnerName();

        if (!$runnerName) {
            return null;
        }

        $runner = RunnerModel::where('name', $runnerName)->first();

        return $runner?->executed_at;
    }

    /**
     * Validate the cron expression.
     *
     * @return bool
     */
    public function isValidSchedule(): bool
    {
        if (!$this->schedule) {
            return false;
        }

        return CronExpression::isValidExpression($this->schedule);
    }

    /**
     * Set the schedule using cron expression.
     *
     * @param string $expression
     * @return $this
     */
    public function cron(string $expression): self
    {
        $this->schedule = $expression;
        return $this;
    }

    /**
     * Schedule to run every minute.
     *
     * @return $this
     */
    public function everyMinute(): self
    {
        $this->schedule = '* * * * *';
        return $this;
    }

    /**
     * Schedule to run every N minutes.
     *
     * @param int $minutes
     * @return $this
     */
    public function everyMinutes(int $minutes): self
    {
        $this->schedule = "*/{$minutes} * * * *";
        return $this;
    }

    /**
     * Schedule to run hourly.
     *
     * @return $this
     */
    public function hourly(): self
    {
        $this->schedule = '0 * * * *';
        return $this;
    }

    /**
     * Schedule to run every N hours.
     *
     * @param int $hours
     * @return $this
     */
    public function everyHours(int $hours): self
    {
        $this->schedule = "0 */{$hours} * * *";
        return $this;
    }

    /**
     * Schedule to run daily at midnight.
     *
     * @return $this
     */
    public function daily(): self
    {
        $this->schedule = '0 0 * * *';
        return $this;
    }

    /**
     * Schedule to run daily at specific time.
     *
     * @param string $time (HH:MM format)
     * @return $this
     */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time);
        $this->schedule = "{$minute} {$hour} * * *";
        return $this;
    }

    /**
     * Schedule to run weekly on Sunday at midnight.
     *
     * @return $this
     */
    public function weekly(): self
    {
        $this->schedule = '0 0 * * 0';
        return $this;
    }

    /**
     * Schedule to run weekly on specific day.
     *
     * @param int $day (0 = Sunday, 6 = Saturday)
     * @return $this
     */
    public function weeklyOn(int $day): self
    {
        $this->schedule = "0 0 * * {$day}";
        return $this;
    }

    /**
     * Schedule to run monthly on the first day at midnight.
     *
     * @return $this
     */
    public function monthly(): self
    {
        $this->schedule = '0 0 1 * *';
        return $this;
    }

    /**
     * Schedule to run monthly on specific day.
     *
     * @param int $day
     * @return $this
     */
    public function monthlyOn(int $day): self
    {
        $this->schedule = "0 0 {$day} * *";
        return $this;
    }

    /**
     * Get the schedule expression.
     *
     * @return string|null
     */
    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    /**
     * Get multiple next run dates.
     *
     * @param int $count Number of next run dates to get
     * @return array Array of Carbon instances
     */
    public function getNextRunDates(int $count = 5): array
    {
        if (!$this->schedule) {
            return [];
        }

        try {
            $cron = new CronExpression($this->schedule);
            $dates = [];
            $currentDate = Carbon::now();

            for ($i = 0; $i < $count; $i++) {
                $nextDate = Carbon::instance($cron->getNextRunDate($currentDate));
                $dates[] = $nextDate;
                $currentDate = $nextDate;
            }

            return $dates;
        } catch (\Exception $e) {
            return [];
        }
    }
}
