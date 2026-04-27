<?php

namespace App\Livewire;

use App\Enums\InvigilatorAssignmentStatus;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class InvigilatorLookup extends Component
{
    public string $phone = '';

    public bool $searched = false;

    public array $assignments = [];

    public ?array $invigilator = null;

    public ?string $message = null;

    public function search(): void
    {
        $this->phone = trim($this->phone);
        $this->reset(['assignments', 'invigilator', 'message']);

        try {
            $this->validate();
        } catch (ValidationException $exception) {
            Log::notice('Invalid public invigilator lookup attempt.', [
                'ip' => request()->ip(),
                'phone_length' => mb_strlen($this->phone),
            ]);

            throw $exception;
        }

        $this->searched = true;

        $invigilators = Invigilator::query()
            ->with('college')
            ->where('is_active', true)
            ->whereIn('phone', $this->phoneVariants())
            ->get();

        if ($invigilators->isEmpty()) {
            $this->message = 'لم يتم العثور على مراقب بهذا الرقم.';

            return;
        }

        if ($invigilators->count() > 1) {
            $this->message = 'يوجد أكثر من مراقب مرتبط بهذا الرقم. يرجى مراجعة الكلية لتحديث رقم هاتف فريد.';

            return;
        }

        /** @var Invigilator $invigilator */
        $invigilator = $invigilators->first();
        $settings = $this->settingsForCollege((int) $invigilator->college_id);
        $now = now(config('app.timezone'));

        $this->invigilator = [
            'name' => $invigilator->name,
            'staff_category' => $invigilator->staff_category?->label() ?: 'غير محدد',
            'invigilation_role' => $invigilator->invigilation_role?->label() ?: 'غير محدد',
            'college' => $invigilator->college?->name ?: 'غير محدد',
        ];

        $assignments = InvigilatorAssignment::query()
            ->with(['college', 'examHall', 'invigilator'])
            ->where('college_id', $invigilator->college_id)
            ->where('invigilator_id', $invigilator->getKey())
            ->where('assignment_status', '<>', InvigilatorAssignmentStatus::Cancelled->value)
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get()
            ->filter(fn (InvigilatorAssignment $assignment): bool => $this->isVisible($assignment, $settings, $now))
            ->map(fn (InvigilatorAssignment $assignment): array => $this->toResult($assignment, $settings, $now))
            ->sortBy([
                ['sort_group', 'asc'],
                ['sort_date', 'asc'],
                ['sort_time', 'asc'],
                ['hall', 'asc'],
            ])
            ->values();

        $this->assignments = $assignments->all();

        if ($this->assignments === []) {
            $this->message = 'لا توجد مراقبات متاحة للعرض حاليًا.';
        }
    }

    public function resetSearch(): void
    {
        $this->reset(['phone', 'searched', 'assignments', 'invigilator', 'message']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.invigilator-lookup')->layout('layouts.public', [
            'title' => 'استعلام المراقبين',
        ]);
    }

    protected function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[\pN+\-\s().]+$/u',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'يرجى إدخال رقم الهاتف.',
            'phone.max' => 'رقم الهاتف طويل جدًا. يرجى التأكد منه.',
            'phone.regex' => 'رقم الهاتف يحتوي على رموز غير مسموحة.',
        ];
    }

    protected function phoneVariants(): array
    {
        return collect([
            $this->phone,
            preg_replace('/\s+/u', '', $this->phone),
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function settingsForCollege(int $collegeId): InvigilatorDistributionSetting
    {
        return InvigilatorDistributionSetting::query()
            ->where('college_id', $collegeId)
            ->first()
            ?? InvigilatorDistributionSetting::defaultsForCollege($collegeId);
    }

    protected function isVisible(InvigilatorAssignment $assignment, InvigilatorDistributionSetting $settings, CarbonInterface $now): bool
    {
        if ($settings->show_all_invigilator_assignments) {
            return true;
        }

        $startAt = $this->assignmentDateTime($assignment, $assignment->start_time);

        if (! $startAt) {
            return false;
        }

        $visibleFrom = $startAt->copy()->subMinutes((int) $settings->visibility_before_minutes);
        $visibleUntil = $this->assignmentDateTime($assignment, $assignment->end_time)
            ?? $startAt->copy()->addMinutes((int) $settings->visibility_after_minutes);

        return $now->greaterThanOrEqualTo($visibleFrom) && $now->lessThanOrEqualTo($visibleUntil);
    }

    protected function toResult(InvigilatorAssignment $assignment, InvigilatorDistributionSetting $settings, CarbonInterface $now): array
    {
        $statusKey = $this->statusKey($assignment, $settings, $now);

        return [
            'date' => $assignment->exam_date?->format('Y-m-d') ?: 'غير محدد',
            'time' => $this->formatExamTime($assignment->start_time, $assignment->end_time),
            'hall' => $assignment->examHall?->name ?: 'غير محدد',
            'location' => $assignment->examHall?->location ?: 'غير محدد',
            'role' => $assignment->invigilation_role?->label() ?: 'غير محدد',
            'status_key' => $statusKey,
            'status_label' => $this->statusLabel($statusKey),
            'status_tone' => $this->statusTone($statusKey),
            'sort_group' => $this->sortGroup($statusKey),
            'sort_date' => $assignment->exam_date?->format('Y-m-d') ?? '9999-12-31',
            'sort_time' => (string) ($assignment->start_time ?? '23:59:59'),
        ];
    }

    protected function statusKey(InvigilatorAssignment $assignment, InvigilatorDistributionSetting $settings, CarbonInterface $now): string
    {
        $startAt = $this->assignmentDateTime($assignment, $assignment->start_time);

        if (! $startAt) {
            return 'unspecified';
        }

        $endAt = $this->assignmentDateTime($assignment, $assignment->end_time)
            ?? $startAt->copy()->addMinutes((int) $settings->visibility_after_minutes);

        if ($now->betweenIncluded($startAt, $endAt)) {
            return 'running';
        }

        if ($endAt->lessThan($now)) {
            return 'finished';
        }

        if ($startAt->isSameDay($now)) {
            return 'today';
        }

        if ($startAt->greaterThan($now)) {
            return 'upcoming';
        }

        return 'finished';
    }

    protected function assignmentDateTime(InvigilatorAssignment $assignment, mixed $time): ?CarbonInterface
    {
        if (! $assignment->exam_date || blank($time)) {
            return null;
        }

        return $assignment->exam_date
            ->copy()
            ->timezone(config('app.timezone'))
            ->setTimeFromTimeString((string) $time);
    }

    protected function formatExamTime(mixed $startTime, mixed $endTime): string
    {
        $start = $this->formatTime($startTime);
        $end = $this->formatTime($endTime);

        return match (true) {
            filled($start) && filled($end) => "{$start} - {$end}",
            filled($start) => $start,
            default => 'غير محدد',
        };
    }

    protected function formatTime(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return mb_substr((string) $time, 0, 5);
    }

    protected function statusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'running' => 'جاري الآن',
            'today' => 'اليوم',
            'upcoming' => 'قادم',
            'finished' => 'منتهي',
            default => 'غير محدد',
        };
    }

    protected function statusTone(string $statusKey): string
    {
        return match ($statusKey) {
            'running', 'today' => 'green',
            'upcoming' => 'orange',
            'finished' => 'gray',
            default => 'gray',
        };
    }

    protected function sortGroup(string $statusKey): int
    {
        return match ($statusKey) {
            'running', 'today' => 0,
            'upcoming' => 1,
            'finished' => 2,
            default => 3,
        };
    }
}
