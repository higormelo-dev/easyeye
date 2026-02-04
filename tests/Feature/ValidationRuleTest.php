<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class ValidationRuleTest extends TestCase
{
    public function test_exists_rule_closure_receives_something()
    {
        $rule = Rule::exists('skin_types', 'id');
        
        $receivedObject = null;
        // In Laravel 11, Rule::exists() returns Exists object.
        // If we call ->where() on it, does it call the closure immediately?
        // Let's check how Exists::where is implemented in this version.
        
        try {
            $rule->where(function ($query) use (&$receivedObject) {
                $receivedObject = $query;
                $query->orWhereNull('test');
            });
        } catch (\Throwable $e) {
            echo "\nCaught: " . get_class($e) . ": " . $e->getMessage() . "\n";
        }

        $this->assertInstanceOf(\Illuminate\Validation\Rules\Exists::class, $receivedObject);
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
