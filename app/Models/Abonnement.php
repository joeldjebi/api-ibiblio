<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Abonnement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'forfait_id',
        'date_debut',
        'date_fin',
        'statut',
    ];

    // Relation avec l'utilisateur
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relation avec le forfait
    public function forfait()
    {
        return $this->belongsTo(Forfait::class, 'forfait_id');
    }
    
}