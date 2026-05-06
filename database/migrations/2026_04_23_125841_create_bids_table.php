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
		Schema::create('bids', function (Blueprint $table) {
			$table->id();
			$table->foreignId('round_id')->constrained()->onDelete('cascade');
			$table->foreignId('user_id')->constrained()->onDelete('cascade');
			$table->decimal('amount', 12, 2);
			$table->string('pseudonym');
			$table->boolean('is_quit')->default(false);
			$table->timestamps();

			$table->unique(['round_id', 'user_id']); // one bid per round per donor
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('bids');
	}
};
