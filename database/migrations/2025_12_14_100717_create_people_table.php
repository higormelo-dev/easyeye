<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('nickname')->nullable();
            $table->date('birth_date')->nullable();
            $table->integer('gender')->nullable();
            $table->integer('marital_status')->nullable();
            $table->string('email')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('father_name')->nullable();
            $table->string('national_registry', 50)->nullable();
            $table->string('state_registry', 50)->nullable();
            $table->string('state_registry_agency', 50)->nullable();
            $table->string('state_registry_initial', 10)->nullable();
            $table->date('state_registry_date')->nullable();
            $table->string('telephone', 50)->nullable();
            $table->string('cellphone', 50);
            $table->boolean('whatsapp')->default(false);
            $table->string('zipcode', 9)->nullable();
            $table->string('address')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('photo')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
			$table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
