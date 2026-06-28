<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InvigilatorDistributionArraySheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        protected string $title,
        protected array $headings,
        protected array $rows,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', $this->title) ?: $this->title;

        return function_exists('mb_substr') ? mb_substr($title, 0, 31) : substr($title, 0, 31);
    }
}
