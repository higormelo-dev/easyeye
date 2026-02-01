<?php

namespace Tests\Feature\Models;

use App\Enums\ExamCategory;
use App\Models\ExamType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_sequential_code_on_creation(): void
    {
        $examType1 = ExamType::create([
            'name'     => 'Exam 1',
            'category' => 1,
            'active'   => true,
        ]);

        $this->assertEquals('ETP-00001', $examType1->code);

        $examType2 = ExamType::create([
            'name'     => 'Exam 2',
            'category' => 1,
            'active'   => true,
        ]);

        $this->assertEquals('ETP-00002', $examType2->code);
    }

    public function test_it_does_not_overwrite_existing_code(): void
    {
        $examType = ExamType::create([
            'code'     => 'CUSTOM-01',
            'name'     => 'Custom Exam',
            'category' => 1,
            'active'   => true,
        ]);

        $this->assertEquals('CUSTOM-01', $examType->code);
    }

    public function test_it_increments_from_last_etp_code(): void
    {
        ExamType::create([
            'code'     => 'ETP-00010',
            'name'     => 'Exam 10',
            'category' => 1,
            'active'   => true,
        ]);

        $nextExamType = ExamType::create([
            'name'     => 'Exam 11',
            'category' => 1,
            'active'   => true,
        ]);

        $this->assertEquals('ETP-00011', $nextExamType->code);
    }

    public function test_it_casts_category_to_enum(): void
    {
        $examType = ExamType::create([
            'name'     => 'Enum Test',
            'category' => 3, // Retina e Vítreo
            'active'   => true,
        ]);

        $this->assertInstanceOf(ExamCategory::class, $examType->category);
        $this->assertEquals(ExamCategory::RETINA_AND_VITREOUS, $examType->category);
        $this->assertEquals('Retina e Vítreo', $examType->category->label());
    }
}
