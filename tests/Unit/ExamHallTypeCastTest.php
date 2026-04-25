<?php

namespace Tests\Unit;

use App\Enums\ExamHallType;
use App\Models\ExamHall;
use Tests\TestCase;

class ExamHallTypeCastTest extends TestCase
{
    public function test_empty_hall_type_is_handled_without_throwing(): void
    {
        $hall = new ExamHall();
        $hall->setRawAttributes([
            'hall_type' => '',
        ]);

        $this->assertNull($hall->hall_type);
    }

    public function test_valid_hall_type_is_cast_to_enum(): void
    {
        $hall = new ExamHall();
        $hall->setRawAttributes([
            'hall_type' => ExamHallType::Large->value,
        ]);

        $this->assertSame(ExamHallType::Large, $hall->hall_type);
    }
}
