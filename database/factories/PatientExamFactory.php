<?php

namespace Database\Factories;

use App\Models\{ExamType, Patient};
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PatientExam>
 */
class PatientExamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id'  => Patient::factory(),
            'exam_id'     => ExamType::factory(),
            'doctor_id'   => null,
            'schedule_id' => null,
            'archive'     => 'exams/test-' . Str::uuid() . '.jpg',
            'name'        => fake()->optional(0.6)->sentence(3),
            'active'      => true,
        ];
    }
}
