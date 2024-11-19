<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Verify_code;
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

class AuthController extends Controller
{
    
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('auth:api', ['except' => [
            'login', 'register', 'verifyNumberExist', 'verifyOtp', 
            'verifyNumberPasswordForget', 'passwordForgetUpdate',
            'verifyOtpPasswordForget'
        ]]);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyNumberExist(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'indicatif' => 'required|string',
            'mobile' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'utilisateur avec l'indicatif et le numéro
        $userNumberVerify = User::where('indicatif', $request->indicatif)
                                ->where('mobile', $request->mobile)
                                ->first();

        if (!empty($userNumberVerify)) {
            return response()->json([
                'error' => true,
                'message' => 'Numéro de téléphone existe, veuillez vous connecter.',
            ], 404);
        }

        // Générer le code de confirmation
        $confirmationCode = rand(1000, 9999);
        $mobileWithIndicatif = $request->indicatif . $request->mobile;

        // Construire le message
        $message = strtoupper("Votre code de confirmation: " . $confirmationCode);

        // Envoyer le SMS
        try {
            $smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);

            if ($smsResponse->getStatusCode() !== 200) {
                return response()->json([
                    'success' => false,
                    'message' => 'Échec de l\'envoi du SMS.',
                ], 500);
            }

            // Enregistrer le code de vérification
            $verifyCode = new Verify_code();
            $verifyCode->code = $confirmationCode;
            $verifyCode->mobile = $mobileWithIndicatif;
            $verifyCode->statut = 0;

            if (!$verifyCode->save()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur est survenue lors de l\'enregistrement du code.',
                ], 500);
            }
        } catch (\Exception $e) {
            // Enregistrer l'erreur dans les logs
            \Log::error('Erreur lors de l\'envoi du SMS : ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi du SMS.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Retourner la réponse avec le code de confirmation
        return response()->json([
            'success' => true,
            'message' => 'Code de confirmation envoyé par SMS.',
            'code' => $confirmationCode,
        ], 200);
    }

    public function verifyOtp(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'OTP dans la table Verify_codes
        $verifyCode = Verify_code::where('mobile', $request->mobile)
                                ->where('code', $request->otp)
                                ->where('statut', 0)
                                ->first();

        // Vérifier si l'OTP existe et a le statut 0
        if (empty($verifyCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Le code OTP est invalide ou a déjà été utilisé.',
            ], 404);
        }

        // Mettre à jour le statut de l'OTP pour indiquer qu'il a été utilisé
        $verifyCode->statut = 1;

        if (!$verifyCode->save()) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la validation de l\'OTP.',
            ], 500);
        }

        // Retourner une réponse réussie
        return response()->json([
            'success' => true,
            'message' => 'Le code OTP est valide.',
        ], 200);
    }

    
    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Récupération des données d'authentification
        $credentials = $request->only(['mobile', 'password', 'indicatif']);
        
        // Enlever l'indicatif pour obtenir uniquement le numéro de téléphone
        $mobileWithoutIndicatif = $credentials['mobile']; // Numéro sans l'indicatif
        $user = User::where('mobile', $mobileWithoutIndicatif)->first(); // Recherche sans l'indicatif
    
        // Vérifier si l'utilisateur existe et comparer le mot de passe
        if ($user && Hash::check($request->password, $user->password)) {
            // Authentification réussie
            $token = JWTAuth::fromUser($user);
            return response()->json([
                'success' => true,
                'message' => 'Utilisateur authentifié avec succès.',
                'token' => $token,
                'user' => $user,
            ]);
        }
    
        return response()->json([
            'success' => false,
            'message' => 'Identifiants incorrects. Veuillez vérifier votre numéro et votre mot de passe.',
        ], 401);
    }
    

    /**
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'pays_id' => 'required|exists:pays,id',
            'indicatif' => 'required|string',
            'mobile' => 'required|numeric|unique:users',
            'password' => 'required|string|min:6',
            'otp' => 'required|numeric',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Vérification de l'OTP avec statut 1 (valide)
        $verifyCode = Verify_code::where('mobile', $request->indicatif . $request->mobile)
            ->where('code', $request->otp)
            ->where('statut', 1) // Statut valide
            ->first();

        if (empty($verifyCode)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP invalide ou déjà utilisé.',
            ], 400); // Code 400 pour mauvaise requête
        }
        // Utilisation d'une transaction pour garantir l'intégrité des données
        DB::beginTransaction();
        try {

            // Création de l'utilisateur
            $user = new User();
            $user->uuid = (string) Str::uuid();
            $user->pays_id = $request->pays_id;
            $user->indicatif = $request->indicatif;
            $user->mobile = $request->mobile;
            $user->password = bcrypt($request->password); // Hash sécurisé du mot de passe
            $user->role = 1;
    
            // dd($user);
            $user->save();
            // Commit de la transaction
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => 'Utilisateur enregistré avec succès.',
                'user' => $user,
            ], 201); // Utilisation du code HTTP 201 pour "Created"
        } catch (\Exception $e) {
            // Rollback de la transaction en cas d'erreur
            DB::rollBack();
    
            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de l'utilisateur.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function updateUser(Request $request, $id)
    {
        // dd($request->all());
        // Valider les données de la requête
        $validator = Validator::make($request->all(), [
            'nom' => 'nullable|string|max:255',
            'prenoms' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $id,
            'pays_id' => 'nullable|exists:pays,id',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Avatar (image)
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Rechercher l'utilisateur
        $user = User::find($id);
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 404);
        }
    
        // Mise à jour des champs basiques
        if ($request->filled('nom')) {
            $user->nom = $request->nom;
        }
    
        if ($request->filled('prenoms')) {
            $user->prenoms = $request->prenoms;
        }
    
        if ($request->filled('email')) {
            $user->email = $request->email;
        }
    
        if ($request->filled('pays_id')) {
            $user->pays_id = $request->pays_id;
        }
    
        // Gestion de l'avatar
        if ($request->hasFile('avatar')) {
            // Supprimer l'ancien avatar s'il existe
            if ($user->avatar && \Storage::exists($user->avatar)) {
                \Storage::delete($user->avatar);
            }
    
            // Sauvegarder le nouvel avatar
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $avatarPath;
        }
    
        // Sauvegarder les changements
        try {
            $user->save();
    
            return response()->json([
                'success' => true,
                'message' => 'Utilisateur mis à jour avec succès.',
                'user' => $user,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la mise à jour de l'utilisateur.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function updatePassword(Request $request, $id)
    {
        // Valider les données
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed', // La confirmation est attendue via 'new_password_confirmation'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Récupérer l'utilisateur
        $user = User::find($id);
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 404);
        }
    
        // Vérifier l'ancien mot de passe
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 403);
        }
    
        // Vérifier si le nouveau mot de passe est différent de l'ancien
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Le nouveau mot de passe ne peut pas être identique à l\'ancien mot de passe.',
            ], 422);
        }
    
        // Mettre à jour le mot de passe
        $user->password = bcrypt($request->new_password);
    
        try {
            $user->save();
    
            return response()->json([
                'success' => true,
                'message' => 'Mot de passe mis à jour avec succès.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du mot de passe.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout() 
    {
        auth()->logout();
        return response()->json(['message' => 'User successfully signed out']);
    }
    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh() 
    {
        return $this->createNewToken(auth()->refresh());
    }
    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUser() 
    {
        return response()->json([
            'user' => auth()->user(),
        ]);
    }
    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function createNewToken($token)
    {
        if ($usr->save()) {
            return response()->json([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => auth()->user(),
            ]);
        }
    }

    public function sendMessageConfirmOrder($message, $reciever) 
    {
        $url = "https://api.smscloud.ci/v1/campaigns/";
        $token = "XeETy7GtbpU7PwMwXk2HOPlZmgqhu9C57v4";

        $data = [
            'sender' => 'QLOWO',
            'content' => $message,
            'dlrUrl' => 'https://myreturnhost.com',
            'recipients' => [$reciever] // Utiliser directement le numéro passé en paramètre
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'cache-control: no-cache'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);

        if ($response === false) {
            // Gérer l'erreur de requête
            $error = curl_error($ch);
            return response()->json([
                'error' => true,
                'message' => 'Erreur cURL : ' . $error
            ], 500);
        }

        // Traitement de la réponse
        $responseData = json_decode($response, true);
        return response()->json([
            'message' => 'Message envoyé avec succès',
            'body' => $responseData
        ], 200);
    }

        /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyNumberPasswordForget(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'indicatif' => 'required|string',
            'mobile' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'utilisateur avec l'indicatif et le numéro
        $userNumberVerify = User::where('indicatif', $request->indicatif)
                                ->where('mobile', $request->mobile)
                                ->first();

        if (empty($userNumberVerify)) {
            return response()->json([
                'error' => true,
                'message' => 'Numéro de téléphone existe, veuillez vous connecter.',
            ], 404);
        }

        // Générer le code de confirmation
        $confirmationCode = rand(1000, 9999);
        $mobileWithIndicatif = $request->indicatif . $request->mobile;

        // Construire le message
        $message = strtoupper("Votre code de confirmation: " . $confirmationCode);

        // Envoyer le SMS
        try {
            $smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);

            if ($smsResponse->getStatusCode() !== 200) {
                return response()->json([
                    'success' => false,
                    'message' => 'Échec de l\'envoi du SMS.',
                ], 500);
            }

            // Enregistrer le code de vérification
            $verifyCode = new Verify_code();
            $verifyCode->code = $confirmationCode;
            $verifyCode->mobile = $mobileWithIndicatif;
            $verifyCode->statut = 0;

            if (!$verifyCode->save()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur est survenue lors de l\'enregistrement du code.',
                ], 500);
            }
        } catch (\Exception $e) {
            // Enregistrer l'erreur dans les logs
            \Log::error('Erreur lors de l\'envoi du SMS : ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi du SMS.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Retourner la réponse avec le code de confirmation
        return response()->json([
            'success' => true,
            'message' => 'Code de confirmation envoyé par SMS.',
            'code' => $confirmationCode,
        ], 200);
    }

    public function verifyOtpPasswordForget(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'OTP dans la table Verify_codes
        $verifyCode = Verify_code::where('mobile', $request->mobile)
                                ->where('code', $request->otp)
                                ->where('statut', 0)
                                ->first();

        // Vérifier si l'OTP existe et a le statut 0
        if (empty($verifyCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Le code OTP est invalide ou a déjà été utilisé.',
            ], 404);
        }

        // Mettre à jour le statut de l'OTP pour indiquer qu'il a été utilisé
        $verifyCode->statut = 1;

        if (!$verifyCode->save()) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la validation de l\'OTP.',
            ], 500);
        }

        // Retourner une réponse réussie
        return response()->json([
            'success' => true,
            'message' => 'Le code OTP est valide.',
        ], 200);
    }

    /**
     * Mot de passe oublié
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function passwordForgetUpdate(Request $request)
    {
        // Validation des données
        $validator = Validator::make($request->all(), [
            'code' => 'required|numeric',
            'indicatif' => 'required|numeric',
            'mobile' => 'required|numeric',
            'new_password' => 'required|string|min:6',
            'confirm_password' => 'required|string|min:6|same:new_password', // Vérification directe
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation des données échouée',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        $indicatif = $request->indicatif;
        $mobile = $request->mobile;
    
        // Recherche de l'utilisateur
        $user = User::where(['indicatif' => $indicatif, 'mobile' => $mobile])->first();
    
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Utilisateur introuvable',
            ], 404); // Code HTTP 404 pour "Non trouvé"
        }
    
        // Vérification du code de réinitialisation
        $verifyCode = Verify_code::where([
            'mobile' => $indicatif . $mobile,
            'statut' => 1,
            'code' => $request->code,
        ])->first();
    
        if (!$verifyCode) {
            return response()->json([
                'status' => false,
                'message' => 'Code de vérification invalide ou expiré',
            ], 400); // Code HTTP 400 pour "Mauvaise requête"
        }
    
        // Modification du mot de passe dans une transaction
        try {
            DB::beginTransaction();
            $user->password = bcrypt($request->new_password);
            $user->save();
    
            // Optionnel : Invalider le code de vérification après utilisation
            $verifyCode->statut = 0;
            $verifyCode->save();
    
            DB::commit();
    
            return response()->json([
                'status' => true,
                'message' => 'Votre mot de passe a été modifié avec succès',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la modification du mot de passe',
                'error' => $e->getMessage(), // À supprimer en production
            ], 500); // Code HTTP 500 pour "Erreur serveur"
        }
    }
    
}