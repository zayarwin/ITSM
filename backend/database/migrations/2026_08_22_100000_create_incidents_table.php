<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('protocol'); // ospf | bgp
            $table->string('previous_state')->nullable();
            $table->string('new_state');
            $table->text('raw_message');
            $table->string('status')->default('detected'); // detected | analyzing | analyzed | failed
            $table->longText('diagnostic_context')->nullable();
            $table->longText('ai_analysis')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
