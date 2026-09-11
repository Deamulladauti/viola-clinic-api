<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_groups', function (Blueprint $table) {
            $table->id();

            // A joined visit belongs to one client. Keep this nullable so
            // deleting/merging a client never destroys the appointment audit.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Useful for audit without making the group itself responsible for
            // appointment/package accounting.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Null means the existing single-appointment behaviour. Deleting a
            // group only ungroups the visit; it never deletes its treatments.
            $table->foreignId('booking_group_id')
                ->nullable()
                ->constrained('booking_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_group_id');
        });

        Schema::dropIfExists('booking_groups');
    }
};
