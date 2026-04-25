<?php

namespace App\Livewire;

use App\Models\ExamStudent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class StudentExamLookup extends Component
{
    public string $studentNumber = '';

    public bool $searched = false;

    public array $results = [];

    public ?array $student = null;

    public ?string $message = null;

    public function search(): void
    {
        $this->studentNumber = trim($this->studentNumber);
        $this->reset(['results', 'student', 'message']);

        try {
            $this->validate();
        } catch (ValidationException $exception) {
            Log::notice('Invalid public student exam lookup attempt.', [
                'ip' => request()->ip(),
                'student_number_length' => mb_strlen($this->studentNumber),
            ]);

            throw $exception;
        }

        $this->searched = true;

        $examStudents = ExamStudent::query()
            ->where('student_number', $this->studentNumber)
            ->with([
                'subjectExamOffering.subject.college',
                'subjectExamOffering.subject.department',
                'hallAssignment.hallAssignment.examHall',
            ])
            ->orderBy('subject_exam_offering_id')
            ->get();

        if ($examStudents->isEmpty()) {
            $this->message = 'لم يتم العثور على طالب بهذا الرقم الامتحاني.';

            return;
        }

        $firstStudent = $examStudents->first();
        $firstOffering = $firstStudent?->subjectExamOffering;
        $firstSubject = $firstOffering?->subject;

        $this->student = [
            'name' => $firstStudent?->full_name,
            'student_number' => $firstStudent?->student_number,
            'college' => $firstSubject?->college?->name,
            'department' => $firstSubject?->department?->name,
        ];

        $hasExamEndTimeColumn = Schema::hasColumn('subject_exam_offerings', 'exam_end_time');

        $this->results = $examStudents
            ->map(fn (ExamStudent $examStudent): array => $this->toResult($examStudent, $hasExamEndTimeColumn))
            ->sortBy([
                ['sort_group', 'asc'],
                ['sort_date', 'asc'],
                ['sort_time', 'asc'],
                ['subject', 'asc'],
            ])
            ->values()
            ->all();

        if ($this->results === []) {
            $this->message = 'لا توجد مواد امتحانية مرتبطة بهذا الرقم حاليًا.';
        }
    }

    public function resetSearch(): void
    {
        $this->reset(['studentNumber', 'searched', 'results', 'student', 'message']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.student-exam-lookup')->layout('layouts.public', [
            'title' => 'استعلام الطلاب',
        ]);
    }

    protected function rules(): array
    {
        return [
            'studentNumber' => [
                'required',
                'string',
                'max:30',
                'regex:/^[\pL\pN_.\-\/]+$/u',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'studentNumber.required' => 'يرجى إدخال الرقم الامتحاني.',
            'studentNumber.max' => 'الرقم الامتحاني طويل جدًا. يرجى التأكد منه.',
            'studentNumber.regex' => 'الرقم الامتحاني يحتوي على رموز غير مسموحة.',
        ];
    }

    protected function toResult(ExamStudent $examStudent, bool $hasExamEndTimeColumn): array
    {
        $offering = $examStudent->subjectExamOffering;
        $subject = $offering?->subject;
        $studentHallAssignment = $examStudent->hallAssignment;
        $hall = $studentHallAssignment?->hallAssignment?->examHall;
        $statusKey = $offering?->exam_status_key ?? 'unspecified';
        $isAssigned = filled($hall?->getKey());

        if (! $isAssigned) {
            $statusKey = 'unassigned';
        }

        return [
            'subject' => $subject?->name ?: 'غير محدد',
            'date' => $offering?->exam_date?->format('Y-m-d') ?: 'غير محدد',
            'time' => $this->formatExamTime($offering?->exam_start_time, $hasExamEndTimeColumn ? $offering?->getAttribute('exam_end_time') : null),
            'hall' => $hall?->name,
            'location' => $hall?->location,
            'assigned' => $isAssigned,
            'status_key' => $statusKey,
            'status_label' => $this->statusLabel($statusKey),
            'status_tone' => $this->statusTone($statusKey),
            'sort_group' => $this->sortGroup($statusKey),
            'sort_date' => $offering?->exam_date?->format('Y-m-d') ?? '9999-12-31',
            'sort_time' => (string) ($offering?->exam_start_time ?? '23:59:59'),
        ];
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
            'today' => 'امتحان اليوم',
            'upcoming' => 'قادم',
            'finished' => 'منتهي',
            'unassigned' => 'لم يتم التوزيع بعد',
            default => 'غير محدد',
        };
    }

    protected function statusTone(string $statusKey): string
    {
        return match ($statusKey) {
            'running', 'today' => 'green',
            'upcoming' => 'orange',
            'unassigned' => 'red',
            'finished' => 'gray',
            default => 'gray',
        };
    }

    protected function sortGroup(string $statusKey): int
    {
        return match ($statusKey) {
            'running', 'today' => 0,
            'upcoming' => 1,
            'unassigned' => 2,
            'finished' => 3,
            default => 4,
        };
    }
}
