<?php

namespace Obelaw\Runner\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunnerLog extends Model
{
    protected $table = 'runner_logs';

    protected $fillable = [
        'runner_name',
        'tag',
        'type',
        'status',
        'output',
        'error',
        'execution_time',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the runner execution record.
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(RunnerModel::class, 'runner_name', 'name');
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by runner name.
     */
    public function scopeForRunner($query, string $runnerName)
    {
        return $query->where('runner_name', $runnerName);
    }

    /**
     * Scope to filter by tag.
     */
    public function scopeWithTag($query, string $tag)
    {
        return $query->where('tag', $tag);
    }

    /**
     * Scope to get failed executions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get completed executions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Get execution time in seconds.
     */
    public function getExecutionTimeInSeconds(): ?float
    {
        return $this->execution_time ? $this->execution_time / 1000 : null;
    }

    /**
     * Mark log as started.
     */
    public static function logStart(string $runnerName, array $data = []): self
    {
        return static::create(array_merge([
            'runner_name' => $runnerName,
            'status' => 'started',
            'started_at' => now(),
        ], $data));
    }

    /**
     * Mark log as completed.
     */
    public function markCompleted(mixed $output = null): bool
    {
        $startTime = $this->started_at?->timestamp ?? microtime(true);
        $executionTime = max(0, (int) ((microtime(true) - $startTime) * 1000));

        $serializedOutput = match (true) {
            $output === null => null,
            is_string($output) => $output,
            default => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string) $output,
        };

        return $this->update([
            'status' => 'completed',
            'output' => $serializedOutput,
            'completed_at' => now(),
            'execution_time' => $executionTime,
        ]);
    }

    /**
     * Mark log as failed.
     */
    public function markFailed(string $error): bool
    {
        $startTime = $this->started_at?->timestamp ?? microtime(true);
        $executionTime = (int)((microtime(true) - $startTime) * 1000);

        return $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
            'execution_time' => $executionTime,
        ]);
    }

    /**
     * Get recent logs for a runner.
     */
    public static function getRecentLogs(string $runnerName, int $limit = 10)
    {
        return static::forRunner($runnerName)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed logs.
     */
    public static function getFailedLogs(int $limit = 50)
    {
        return static::failed()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}