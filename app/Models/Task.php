<?php

namespace App\Models;

use App\Enums\CompanySettingsEnum;
use App\Enums\StatusPhaseEnum;
use App\Helpers\PejotaHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NunoMazer\Samehouse\BelongsToTenants;
use Parallax\FilamentComments\Models\Traits\HasFilamentComments;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Tags\HasTags;

/**
 * Class Model Task
 *
 * @property int $id
 * @property string $title
 * @property int|null $client_id
 * @property int|null $project_id
 * @property int $status_id
 * @property int|null $parent_id
 * @property \Illuminate\Support\Carbon|null $planned_start
 * @property \Illuminate\Support\Carbon|null $planned_end
 * @property \Illuminate\Support\Carbon|null $actual_start
 * @property \Illuminate\Support\Carbon|null $actual_end
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property array|null $checklist
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Task extends Model
{
    use BelongsToTenants,
        HasFactory,
        HasFilamentComments,
        HasTags,
        LogsActivity;

    public const LOG_NAME = 'task';

    public const LOG_EVENT_STATUS_CHANGED = 'status_changed';

    protected $guarded = ['id'];

    protected $casts = [
        'planned_start' => 'date',
        'planned_end' => 'date',
        'actual_start' => 'date',
        'actual_end' => 'date',
        'due_date' => 'date',
        'checklist' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if ($model->isDirty('status_id')) {
                self::setStartEndDates($model);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    protected static function setStartEndDates(Model $model): void
    {
        $settings = auth()->user()->company
            ->settings();

        $status = Status::find($model->status_id);

        if ($status) {
            if (
                $status->phase == StatusPhaseEnum::IN_PROGRESS->value &&
                $settings->get(CompanySettingsEnum::TASKS_FILL_ACTUAL_START_DATE_WHEN_IN_PROGRESS->value)
            ) {
                $model->actual_start = $model->actual_start ?? now()->format('Y-m-d');
            }

            if (
                $status->phase == StatusPhaseEnum::CLOSED->value &&
                $settings->get(CompanySettingsEnum::TASKS_FILL_ACTUAL_END_DATE_WHEN_CLOSED->value)
            ) {
                $model->actual_end = $model->actual_end ?? now()->format('Y-m-d');
            }
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status.name'])
            ->useLogName(self::LOG_NAME)
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty();
    }

    public function scopeByProject(Builder $query, Project|int|null $project)
    {
        if ($project) {
            $query->where('project_id', $project);
        }
    }

    public function scopeOpened(Builder $query)
    {
        $query->whereHas('status', function (Builder $query) {
            $query->whereIn('phase', [
                StatusPhaseEnum::TODO,
                StatusPhaseEnum::IN_PROGRESS,
            ]);
        });
    }

    public function scopeClosed(Builder $query)
    {
        $query->whereHas('status', function (Builder $query) {
            $query->whereIn('phase', [
                StatusPhaseEnum::CLOSED,
            ]);
        });
    }

    /**
     * Postpones the specified field by the given interval.
     *
     * @param string $field The name of the field to be postponed.
     * @param string $interval The interval by which the field should be postponed.
     * @param bool $fromNow Whether to postpone from now or from the current value of the field.
     *
     * @return void
     */
    public function postpone(string $field, string $interval, bool $fromNow = true): void
    {
        if ($this->{$field}) {
            $now = now()->tz(PejotaHelper::getUserTimeZone());
            if ($interval == 'today') {
                $this->{$field} = $now->format('Y-m-d');
            } else {
                if (in_array($field, ['planned_end', 'due_date'])) {
                    if (!$this->{$field} || $this->{$field}->startOfDay()->lte($now->startOfDay())) {
                        $nextDate = $now->copy()->add($interval);
                    } else {
                        $nextDate = $this->{$field}->copy()->add($interval);
                    }
                } else {
                    $nextDate = $fromNow ? $now->copy()->add($interval) : $this->{$field}->copy()->add($interval);
                }

                $this->{$field} = $nextDate;
            }
            $this->save();
        }
    }
}
