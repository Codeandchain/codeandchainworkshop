<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le journal de l'interface classique
        Schema::create('journal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('at');
            $table->string('type');
            $table->string('titre');
            $table->text('detail');
            $table->boolean('ok');
        });

        // Le fil des échanges de l'interface Banc (n = numéro d'ordre)
        Schema::create('echanges', function (Blueprint $table) {
            $table->id('n');
            $table->unsignedBigInteger('at');
            $table->json('contenu');
        });

        // Petites valeurs (ex. le dernier webhook, pour la démo du rejeu)
        Schema::create('reglages', function (Blueprint $table) {
            $table->string('cle')->primary();
            $table->text('valeur')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglages');
        Schema::dropIfExists('echanges');
        Schema::dropIfExists('journal');
    }
};
