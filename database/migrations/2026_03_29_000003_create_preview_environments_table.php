<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain');
            $table->string('workspace_path');
            $table->string('cloned_db_name')->nullable();
            $table->timestamps();

            $table->unique('issue_id');
            $table->unique('subdomain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_environments');
    }
};
