<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\Livre;
use App\Models\User;
use App\Models\Auteur;
use App\Models\Forfait;
use App\Models\Wallet_transaction;
use Illuminate\Http\Request;
use Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class LivreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        $user = User::where('id', auth()->user()->id)->first();
        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
                'dev' => "L'utilisateur n'est pas authentifié",
            ], 400);
        }

        $livres = Livre::where([
            'statut' => 1,
            'pays_id' => $user->pays_id
        ])
        ->with(['auteur', 'type_publication', 'categorie', 'editeur', 'langue', 'createdBy'])
        ->get();
    
        if ($livres->isNotEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Liste des livres récupérée avec succès.',
                'livres' => $livres,
            ], 200);
        }
    
        // Aucun livre trouvé
        return response()->json([
            'success' => false,
            'message' => 'Aucun livre trouvé pour cette catégorie.',
        ], 404);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function buyLivreWithWallet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'livre_id' => 'required|numeric|exists:livres,id',
            'type_achat' => 'required|in:gratuit,achat,abonnement,achat_et_abonnement',
            'forfait_id' => 'nullable|numeric|exists:forfaits,id',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
    
        $user = auth()->user();
        $livre = Livre::findOrFail($request->livre_id);
    
        // Vérification du type d'achat en fonction de l'offre du livre
        $typeAchat = $request->type_achat;
    
        // Vérifiez si l'utilisateur a déjà acheté le livre
        if ($user->wallet_transaction()->where('livre_id', $livre->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Livre déjà acheté.'], 400);
        }
    
        // Vérification pour les livres gratuits : seul 'gratuit' est autorisé
        if ($livre->amount == 0 && $typeAchat !== 'gratuit') {
            return response()->json(['success' => false, 'message' => "Ce livre est gratuit, vous ne pouvez pas l'acheter ou vous abonner."], 400);
        }
    
        // Si le livre nécessite un abonnement ou achat_et_abonnement, l'achat et l'abonnement sont permis
        if (($livre->acces_livre == 'abonnement' || $livre->acces_livre == 'achat_et_abonnement') && $typeAchat !== 'abonnement' && $typeAchat !== 'achat_et_abonnement') {
            return response()->json(['success' => false, 'message' => 'Ce livre nécessite un abonnement pour être acheté.'], 400);
        }
    
        // Si le livre nécessite un achat ou achat_et_abonnement, l'achat et l'abonnement sont permis
        if (($livre->acces_livre == 'achat' || $livre->acces_livre == 'achat_et_abonnement') && $typeAchat !== 'achat' && $typeAchat !== 'achat_et_abonnement') {
            return response()->json(['success' => false, 'message' => 'Ce livre nécessite un achat pour en avoir accès.'], 400);
        }
    
        // Si le livre nécessite l'achat et l'abonnement, on permet l'achat ou l'abonnement ou les deux
        if ($livre->acces_livre == 'achat_et_abonnement' && !in_array($typeAchat, ['achat', 'abonnement', 'achat_et_abonnement'])) {
            return response()->json(['success' => false, 'message' => 'Ce livre nécessite à la fois un achat et un abonnement.'], 400);
        }
    
        // Début de la transaction de base de données
        DB::beginTransaction();
    
        try {
            // Si l'utilisateur a choisi "achat"
            if ($typeAchat === 'achat' && $livre->acces_livre == 'achat') {
                if ($user->wallet < $livre->amount) {
                    return response()->json(['success' => false, 'message' => 'Solde insuffisant pour l\'achat.'], 400);
                }
    
                // Déduire le montant du wallet pour un achat
                $user->wallet -= $livre->amount;
                $user->save();
    
            } 
            // Si l'utilisateur a choisi "abonnement"
            elseif ($typeAchat === 'abonnement' && $livre->acces_livre == 'abonnement') {
                $forfait = Forfait::findOrFail($request->forfait_id);
    
                // Vérification du forfait valide
                if (!$forfait) {
                    return response()->json(['success' => false, 'message' => 'Forfait invalide.'], 400);
                }
    
                // Annuler tous les abonnements actifs
                $user->abonnements()
                    ->where('statut', 'actif')
                    ->update(['statut' => 'inactif']);
    
                // Créer un nouvel abonnement avec le nouveau forfait
                $user->abonnements()->create([
                    'forfait_id' => $forfait->id,
                    'date_debut' => now(),
                    'date_fin' => now()->addMonths($forfait->duree),
                    'statut' => 'actif',
                ]);
    
                // Vérification du solde pour l'abonnement
                if ($user->wallet < $livre->amount) {
                    return response()->json(['success' => false, 'message' => 'Solde insuffisant pour l\'abonnement.'], 400);
                }
    
                // Déduire le montant du wallet pour l'abonnement
                $user->wallet -= $livre->amount;
                $user->abonnement_expires_at = now()->addMonths($forfait->duree);
                $user->save();
    
            }
            // Si l'utilisateur choisit "gratuit"
            elseif ($typeAchat === 'gratuit') {
                if ($livre->amount != 0) {
                    return response()->json(['success' => false, 'message' => 'Le livre n\'est pas gratuit.'], 400);
                }
            }
            // Cas "achat_et_abonnement" (l'utilisateur choisit à la fois l'achat et l'abonnement)
            elseif ($typeAchat === 'achat_et_abonnement' && $livre->acces_livre == 'achat_et_abonnement') {
                // Vérifier si l'utilisateur a sélectionné un abonnement avec forfait
                if ($request->has('forfait_id')) {
                    $forfait = Forfait::findOrFail($request->forfait_id);
    
                    // Annuler tous les abonnements actifs
                    $user->abonnements()
                        ->where('statut', 'actif')
                        ->update(['statut' => 'inactif']);
    
                    // Créer un nouvel abonnement avec le forfait sélectionné
                    $user->abonnements()->create([
                        'forfait_id' => $forfait->id,
                        'date_debut' => now(),
                        'date_fin' => now()->addMonths($forfait->duree),
                        'statut' => 'actif',
                    ]);
                }
    
                // Vérification du solde pour l'achat et l'abonnement
                if ($user->wallet < $livre->amount) {
                    return response()->json(['success' => false, 'message' => 'Solde insuffisant.'], 400);
                }
    
                // Déduire le montant du wallet pour l'achat et l'abonnement
                $user->wallet -= $livre->amount;
                $user->abonnement_expires_at = now()->addMonths($forfait->duree);
                $user->save();
    
            } else {
                return response()->json(['success' => false, 'message' => 'Type d\'achat invalide.'], 400);
            }
    
            // Enregistrer la transaction dans tous les cas
            // Créer une fonction pour déterminer le type de transaction
            function getTransactionType(string $typeAchat): string {
                if ($typeAchat == 'achat') {
                    return 'achat_livre';
                } elseif ($typeAchat == 'abonnement') {
                    return 'abonnement';
                } else {
                    return 'gratuit';
                }
            }

            $wallet_transaction = new Wallet_transaction();

            $wallet_transaction->livre_id = $livre->id;
            $wallet_transaction->user_id = $user->id;
            $wallet_transaction->montant = $livre->amount;
            $wallet_transaction->type_transaction = getTransactionType($typeAchat);  // Appel à la fonction
            $wallet_transaction->date_transaction = now();

            $wallet_transaction->save();

    
            // Commit de la transaction
            DB::commit();
    
            return response()->json(['success' => true, 'message' => 'Achat effectué avec succès.']);
    
        } catch (\Exception $e) {
            // Loguer l'exception pour obtenir plus de détails
            \Log::error('Erreur lors de l\'achat livre: '.$e->getMessage(), ['exception' => $e]);
    
            // En cas d'erreur, annuler la transaction
            DB::rollBack();
    
            return response()->json(['success' => false, 'message' => 'Une erreur est survenue, veuillez réessayer.'], 500);
        }
    }
    
    
    
    

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Livre $livre)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Livre $livre)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Livre $livre)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Livre $livre)
    {
        //
    }
}