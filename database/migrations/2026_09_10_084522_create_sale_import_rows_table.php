<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_import_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('sale_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->unsignedBigInteger('line_number');
            $table->string('external_id', 100)->nullable();

            $table->enum('status', [
                'processed',
                'ignored',
                'error',
            ])->index();

            $table->text('message')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique([
                'sale_import_id',
                'line_number',
            ]);

            $table->index([
                'sale_import_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_import_rows');
    }
};
