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
		Schema::create('groups', function (Blueprint $table) {
			$table->id();
			$table->foreignId('round_id')->constrained()->onDelete('cascade');
			$table->string('group_name'); // A, B, C...
			$table->decimal('min_amount', 12, 2)->default(0);
			$table->string('match_ratio')->default('1:1'); // e.g. 1:3
			$table->decimal('total_amount', 12, 2)->default(0);
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('groups');
	}
};
