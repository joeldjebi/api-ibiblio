<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TypePublicationController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\LivreController;
use App\Http\Controllers\PaysController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


// Préfixe pour API v1
Route::prefix('v1')->group(function () {

    // Routes protégées par le middleware d'authentification
    Route::middleware('auth:api')->group(function () {
        // Route de déconnexion
        Route::post('logout', [AuthController::class, 'logout']);
        
        // Route pour obtenir les informations de l'utilisateur
        Route::post('user-infos', [AuthController::class, 'getUser']);
        
        // Route pour mettre à jour les informations de l'utilisateur
        Route::post('/user-update/{id}', [AuthController::class, 'updateUser']);
        
        // Route pour mettre à jour le mot de passe 
        Route::post('/password-update/{id}', [AuthController::class, 'updatePassword']);
        
        // Route pour les type de publications
        Route::post('/type-de-publications', [TypePublicationController::class, 'index']);
        
        // Route pour les type de publications
        Route::post('/get-livre-by-type-de-publications', [TypePublicationController::class, 'getLivreByTypePublication']);
        
        // Route pour les categorie de livre
        Route::post('/categorie-livre', [CategorieController::class, 'index']);
        
        // Route pour les categorie par livre
        Route::post('/get-livre-by-categorie', [CategorieController::class, 'getLivreByCategorie']);
        
        // Route pour afficher les livres
        Route::post('/get-livre-all', [LivreController::class, 'index']);
        
        // Route pour acheter un livre
        Route::post('/buy-livre-with-wallet', [LivreController::class, 'buyLivreWithWallet']);
        

    });

    // Routes publiques
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    
    // Route pour verifier si le numero de telephone existe
    Route::post('otp-register', [AuthController::class, 'verifyNumberExist']);
    
    // Route pour verifier si le otp est correcte
    Route::post('verify-otp-register', [AuthController::class, 'verifyOtp']);
    
    // Route pour verifier si le otp est correcte
    Route::post('verify-mobile-and-otp-password-forget', [AuthController::class, 'verifyNumberPasswordForget']);
    
    // Route pour verifier le otp de mot de passe oublié
    Route::post('verify-otp-password-forget', [AuthController::class, 'verifyOtpPasswordForget']);
    
    // Route pour mettre a jour le mot de passe oublié
    Route::post('update-password-forget', [AuthController::class, 'passwordForgetUpdate']);

    // Route pour les categorie par livre
    Route::post('/get-pays-all', [PaysController::class, 'indexPaysAll']);
});