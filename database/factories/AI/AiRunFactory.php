<?php

namespace Database\Factories\AI;

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Models\{Entity, User};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\AI\Models\AiRun>
 */
class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    public function definition(): array
    {
        return [
            'entity_id'         => Entity::factory(),
            'patient_id'        => null,
            'medical_record_id' => null,
            'requested_by'      => User::factory(),
            'approved_by'       => null,
            'workflow'          => 'exam_report_draft',
            'mode'              => AiRunMode::Validated->value,
            'risk_level'        => AiRiskLevel::Low->value,
            'status'            => AiRunStatus::Pending->value,
            'estimated_credits' => 5,
            'reserved_credits'  => 0,
            'consumed_credits'  => 0,
            'input_summary'     => null,
            'final_output'      => null,
            'safety_notes'      => null,
            'approved_at'       => null,
            'rejected_at'       => null,
            'error_message'     => null,
        ];
    }

    public function economy(): static
    {
        return $this->state(['mode' => AiRunMode::Economy->value]);
    }

    public function consensus(): static
    {
        return $this->state(['mode' => AiRunMode::Consensus->value]);
    }

    public function waitingApproval(): static
    {
        return $this->state(['status' => AiRunStatus::WaitingApproval->value]);
    }

    public function approved(): static
    {
        return $this->state([
            'status'      => AiRunStatus::Approved->value,
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status'      => AiRunStatus::Rejected->value,
            'rejected_at' => now(),
        ]);
    }
}
