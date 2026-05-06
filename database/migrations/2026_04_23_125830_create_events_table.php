<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
	{
		Schema::create('events', function (Blueprint $table) {
			$table->id();
			$table->foreignId('host_id')->constrained('users')->onDelete('cascade');
			$table->string('name');
			$table->string('charity_name');
			$table->text('description')->nullable();
			$table->string('logo')->nullable();
			$table->decimal('target_amount', 12, 2)->default(0);
			$table->string('join_code', 10)->unique();
			$table->enum('status', ['draft', 'live', 'finished'])->default('draft');
			$table->integer('rounds_count')->default(3);
			$table->integer('group_size')->default(4);
			$table->timestamp('started_at')->nullable();
			$table->timestamp('ended_at')->nullable();
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('events');
	}
};
