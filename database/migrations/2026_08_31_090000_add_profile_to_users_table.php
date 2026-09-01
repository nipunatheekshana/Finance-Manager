<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A public-ish identity for the account: a unique handle and a picture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 30)->nullable()->unique()->after('email');
            $table->string('avatar_path')->nullable()->after('handle');
        });

        // Existing accounts get one derived from their name, so nobody has to
        // pick a handle before they can use the app they already signed up for.
        DB::table('users')->orderBy('id')->select('id', 'name', 'email')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'handle' => $this->uniqueHandle($user->name ?: Str::before($user->email, '@'), $user->id),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['handle', 'avatar_path']);
        });
    }

    private function uniqueHandle(string $from, int $userId): string
    {
        $base = Str::of($from)->lower()->replaceMatches('/[^a-z0-9]+/', '')->limit(24, '')->value();
        $base = $base === '' ? 'user' : $base;

        $handle = $base;
        $suffix = 1;

        while (DB::table('users')->where('handle', $handle)->where('id', '!=', $userId)->exists()) {
            $handle = $base.(++$suffix);
        }

        return $handle;
    }
};
