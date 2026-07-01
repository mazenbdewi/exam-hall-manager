<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class ReportArraySheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        protected string $title,
        protected array $rows,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', $this->title) ?: $this->title;
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $title = $title !== '' ? $title : __('exam.reports.report');

        return function_exists('mb_substr') ? mb_substr($title, 0, 31) : substr($title, 0, 31);
    }
}
