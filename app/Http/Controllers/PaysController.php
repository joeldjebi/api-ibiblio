<?php

namespace App\Http\Controllers;

use App\Models\Pays;
use App\Models\Devise;
use App\Models\User;
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

class PaysController extends Controller
{

    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('auth:api', ['except' => [
            'indexPaysAll'
        ]]);
    }

    /**
     * Display a listing of the resource.
     */
    public function indexPaysAll()
    {
        $pays = Pays::orderBy('id', 'desc')
        ->with('devise')
        ->get();

        if (!empty($pays)) {
            return response()->json([
                'success' => true,
                'message' => 'Les pays.',
                'pays' => $pays
            ], 200);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
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
    public function show(Pays $pays)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Pays $pays)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Pays $pays)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pays $pays)
    {
        //
    }
}