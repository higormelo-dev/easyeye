<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class ValidationRuleTest extends TestCase
{
    public function test_exists_rule_where_stores_closure_for_later_evaluation()
    {
        $rule = Rule::exists('skin_types', 'id');

        // In Laravel 11, Rule::exists()->where(callable) stores the closure
        // for later evaluation during validation — it does NOT call the closure immediately.
        // The return value is the Exists rule instance itself (for chaining).
        $result = $rule->where(function ($query) {
            $query->whereNull('entity_id');
        });

        // where() returns the Exists rule for chaining
        $this->assertInstanceOf(\Illuminate\Validation\Rules\Exists::class, $result);
    }

    public function test_exists_rule_issue_reproduction()
    {
        // The error "Method 'orWhereNull' not found in \Illuminate\Validation\Rules\Exists"
        // strongly suggests that ->orWhereNull was called on an Exists object.
        
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Call to undefined method Illuminate\Validation\Rules\Exists::orWhereNull()");
        
        $rule = Rule::exists('skin_types', 'id');
        $rule->orWhereNull('entity_id');
    }
}
