<?php

use App\Enums\IssueStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('github_issue_number');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('github_author');
            $table->string('status')->default(IssueStatus::Pending->value);
            $table->string('spec_session_id')->nullable();
            $table->string('dev_session_id')->nullable();
            $table->string('feature_branch')->nullable();
            $table->timestamps();

            $table->unique('github_issue_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
