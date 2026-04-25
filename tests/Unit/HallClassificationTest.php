<?php

namespace Tests\Unit;

use App\Enums\ExamHallType;
use App\Models\HallSetting;
use App\Support\HallClassification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HallClassificationTest extends TestCase
{
    #[Test]
    public function it_classifies_small_halls_below_the_large_threshold(): void
    {
        $settings = new HallSetting([
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
        ]);

        $this->assertSame(ExamHallType::Small, HallClassification::expectedTypeForCapacity(80, $settings));
    }

    #[Test]
    public function it_classifies_large_halls_between_both_thresholds(): void
    {
        $settings = new HallSetting([
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
        ]);

        $this->assertSame(ExamHallType::Large, HallClassification::expectedTypeForCapacity(150, $settings));
    }

    #[Test]
    public function it_classifies_amphitheaters_at_or_above_the_amphitheater_threshold(): void
    {
        $settings = new HallSetting([
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
        ]);

        $this->assertSame(ExamHallType::Amphitheater, HallClassification::expectedTypeForCapacity(220, $settings));
    }
}
