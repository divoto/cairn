<?php

declare(strict_types=1);

use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Store the Core Web Vitals the beacon has been sending since 1.0.
 *
 * The beacon measured LCP, INP and CLS and put all three in every payload; the
 * collect endpoint validated four other fields and dropped these on the floor.
 * Nothing new is asked of a visitor here — the numbers were already leaving the
 * browser, and this is the first release that keeps them.
 *
 * Three nullable columns rather than a table of their own: they are facts about
 * one page render, they arrive on the same submission as time on page, and they
 * age out with the entry that carries them.
 *
 * CLS is a ratio, typically between 0 and 0.5. It is stored multiplied by a
 * thousand so a rollup can sum it as an integer — averaging a float column
 * across a bucket invites the drift that {@see Metric}
 * exists to prevent.
 *
 * All three are nullable and stay null on an installation with the beacon off,
 * which is what lets the widget say "not measured" rather than report a zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->table(Tables::entries(), function (Blueprint $table): void {
            // Largest Contentful Paint, in milliseconds. Clamped to 60s on the
            // way in, but given a full integer: a clamp is a validation rule
            // and may be relaxed, while a column width is a rewrite.
            $table->unsignedInteger('lcp_ms')->nullable()->after('scroll_depth');

            // Interaction to Next Paint, in milliseconds.
            $table->unsignedSmallInteger('inp_ms')->nullable()->after('lcp_ms');

            // Cumulative Layout Shift, times a thousand.
            $table->unsignedSmallInteger('cls_milli')->nullable()->after('inp_ms');
        });
    }

    public function down(): void
    {
        $this->schema()->table(Tables::entries(), function (Blueprint $table): void {
            $table->dropColumn(['lcp_ms', 'inp_ms', 'cls_milli']);
        });
    }

    private function schema(): Builder
    {
        return Schema::connection(Tables::connection());
    }
};
