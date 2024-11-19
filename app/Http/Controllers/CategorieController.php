<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\Livre;
use App\Models\User;
use App\Models\Auteur;
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

class CategorieController extends Controller
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

        $categorieLivres = Categorie::all();

        if (!empty($categorieLivres)) {
            return response()->json([
                'success' => true,
                'message' => 'Les catégories de livre.',
                'categorieLivress' => $categorieLivres
            ], 200);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function getLivreByCategorie(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'categorie_id' => 'required|exists:categories,id',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Vérifier si l'utilisateur est authentifié
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }
    
        // Récupérer les livres correspondant au type de publication
        $livres = Livre::where([
            'statut' => 1,
            'categorie_id' => $request->categorie_id,
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
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Categorie $categorie)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Categorie $categorie)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Categorie $categorie)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Categorie $categorie)
    {
        //
    }
}