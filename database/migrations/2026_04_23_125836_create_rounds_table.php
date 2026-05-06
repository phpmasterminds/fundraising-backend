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
		Schema::create('rounds', function (Blueprint $table) {
			$table->id();
			$table->foreignId('event_id')->constrained()->onDelete('cascade');
			$table->integer('round_number');
			$table->enum('status', ['waiting', 'open', 'closed'])->default('waiting');
			$table->timestamp('opened_at')->nullable();
			$table->timestamp('closed_at')->nullable();
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('rounds');
	}
};
