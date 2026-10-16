<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La « base de données » du faux Bitnob (mode simulation). Séparée des
 * factures de l'application : le faux Bitnob ne connaît que ce qu'un
 * vrai Bitnob connaîtrait, dont la preimage, son secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitnob_simulation_factures', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('reference')->unique();
            $table->string('payment_hash');
            $table->string('preimage');                        // le secret du nœud
            $table->unsignedBigInteger('montant_sats');
            $table->string('description', 120);
            $table->text('request');                           // la chaîne BOLT11
            $table->unsignedBigInteger('payer_a')->index();    // quand la « payer » (ms)
            $table->unsignedBigInteger('expire_a');
            $table->boolean('payee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitnob_simulation_factures');
    }
};
