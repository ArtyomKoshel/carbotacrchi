<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reference catalog (app/Catalog, resources/catalog): makes → models → generations →
 * modifications, plus complectations, independent of any platform; and ref_variants, each
 * platform variant (Encar's make, model, badge group, badge, trim) placed on it.
 *
 * lots.ref_* point at a lot's variant and its place in the catalog, for the filters.
 * DDL only, INSTANT on lots: migrations run before nginx and Railway's healthcheck allows
 * 30 s. Filling is `php artisan catalog:sync` then `catalog:remap` (docs/13-catalog.md);
 * the indexes come in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_makes', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->string('name', 60)->unique();
            $t->timestamps();
        });

        Schema::create('ref_models', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedSmallInteger('make_id');
            $t->string('name', 80);
            $t->string('body', 20)->nullable();
            $t->string('fuel', 20)->nullable();
            $t->timestamps();
            $t->unique(['make_id', 'name']);
            $t->foreign('make_id')->references('id')->on('ref_makes')->cascadeOnDelete();
        });

        Schema::create('ref_generations', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('model_id');
            $t->string('code', 40);  // Korean makes name a facelift with its generation: "Gen 2 · New Impression"
            $t->unsignedTinyInteger('number')->nullable();
            $t->unsignedSmallInteger('year_from')->nullable();
            $t->unsignedSmallInteger('year_to')->nullable();
            $t->string('fuel', 20)->nullable();
            $t->timestamps();
            $t->unique(['model_id', 'code']);
            $t->foreign('model_id')->references('id')->on('ref_models')->cascadeOnDelete();
        });

        // modifications come from the variants the rules read ("530i xDrive" of a G60)
        Schema::create('ref_modifications', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('generation_id');
            $t->string('code', 80);
            $t->string('fuel', 20)->nullable();
            $t->string('drive', 10)->nullable();
            $t->timestamps();
            $t->unique(['generation_id', 'code']);
            $t->foreign('generation_id')->references('id')->on('ref_generations')->cascadeOnDelete();
        });

        Schema::create('ref_complectations', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedSmallInteger('make_id');
            $t->string('name', 100);
            $t->timestamps();
            $t->unique(['make_id', 'name']);
            $t->foreign('make_id')->references('id')->on('ref_makes')->cascadeOnDelete();
        });

        Schema::create('ref_variants', function (Blueprint $t) {
            $t->increments('id');
            $t->string('source', 20);
            $t->char('hash', 40);                       // App\Catalog\CatalogLinker::hash
            $t->string('make', 60)->nullable();         // the platform's own fields
            $t->string('model', 100)->nullable();
            $t->string('badge_group', 150)->nullable();
            $t->string('badge', 150)->nullable();
            $t->string('trim', 150)->nullable();
            $t->string('status', 12);                   // matched | no_engine | unmatched
            $t->boolean('manual')->default(false);      // set by hand: catalog:remap keeps it
            $t->unsignedSmallInteger('make_id')->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->unsignedInteger('generation_id')->nullable();
            $t->unsignedInteger('modification_id')->nullable();
            $t->unsignedInteger('complectation_id')->nullable();
            $t->string('body', 20)->nullable();
            $t->unsignedTinyInteger('seats')->nullable();
            $t->json('editions')->nullable();
            $t->json('leftover')->nullable();
            $t->string('reason', 120)->nullable();
            $t->unsignedInteger('lots')->default(0);    // active lots, as of the last catalog:remap
            $t->timestamps();
            $t->unique(['source', 'hash']);
            $t->index(['status', 'lots']);
        });

        Schema::table('lots', function (Blueprint $t) {
            $t->unsignedInteger('ref_variant_id')->nullable();
            $t->unsignedSmallInteger('ref_make_id')->nullable();
            $t->unsignedInteger('ref_model_id')->nullable();
            $t->unsignedInteger('ref_generation_id')->nullable();
            $t->unsignedInteger('ref_modification_id')->nullable();
            $t->unsignedInteger('ref_complectation_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->dropColumn(['ref_variant_id', 'ref_make_id', 'ref_model_id', 'ref_generation_id', 'ref_modification_id', 'ref_complectation_id']);
        });
        foreach (['ref_variants', 'ref_complectations', 'ref_modifications', 'ref_generations', 'ref_models', 'ref_makes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
