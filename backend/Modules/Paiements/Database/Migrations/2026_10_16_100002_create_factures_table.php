<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les factures de l'application. Les dates sont en millisecondes
 * (comme Date.now() côté navigateur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->string('id')->primary();                  // id donné par Bitnob
            $table->string('reference')->unique();            // clé d'idempotence côté Bitnob
            $table->string('payment_hash')->nullable();
            $table->unsignedBigInteger('montant_sats');
            $table->string('description', 120);
            $table->text('payment_request');                  // la chaîne BOLT11
            $table->string('status')->default('pending')->index(); // pending|paid|expired|failed
            $table->string('preimage')->nullable();           // la preuve, connue une fois payée
            $table->unsignedBigInteger('expire_a');
            $table->boolean('traitee')->default(false);       // a-t-elle déjà produit son effet ?
            $table->boolean('vu_paye_par_nav')->default(false);
            $table->unsignedBigInteger('paye_a')->nullable();
            $table->boolean('mock')->default(false);
            $table->timestamps();
        });

        // Les event_id déjà traités : un webhook rejoué est ignoré
        Schema::create('evenements_webhook', function (Blueprint $table) {
            $table->string('event_id')->primary();
            $table->timestamp('recu_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements_webhook');
        Schema::dropIfExists('factures');
    }
};
