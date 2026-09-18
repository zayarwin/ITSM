<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->renameColumn('protocol', 'facility');
            $table->unsignedTinyInteger('severity')->nullable()->after('facility');
            $table->string('mnemonic')->nullable()->after('severity');
            $table->string('new_state')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['severity', 'mnemonic']);
            $table->renameColumn('facility', 'protocol');
        });
    }
};
