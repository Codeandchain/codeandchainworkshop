<?php

use Illuminate\Support\Facades\Route;

/*
 * L'interface Vue, compilée par `npm run build` dans public/spa.
 * Toute URL qui n'est pas /api/… renvoie la page (les vues #banc et
 * #classique sont choisies dans le navigateur).
 */
Route::get('/{chemin?}', function () {
    $page = public_path('spa/index.html');
    if (! is_file($page)) {
        return response('Interface non compilée : lance « npm run build » à la racine du projet.', 503)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    return response()->file($page, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
})->where('chemin', '^(?!api/).*$');
