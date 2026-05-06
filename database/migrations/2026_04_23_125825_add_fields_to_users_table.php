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
		Schema::table('users', function (Blueprint $table) {
			$table->enum('role', ['host', 'donor'])->default('donor')->after('email');
			$table->string('avatar')->nullable()->after('role');
			$table->string('pseudonym')->nullable()->after('avatar');
			$table->string('phone')->nullable()->after('pseudonym');
		});
	}

	public function down(): void
	{
		Schema::table('users', function (Blueprint $table) {
			$table->dropColumn(['role', 'avatar', 'pseudonym', 'phone']);
		});
	}
};
