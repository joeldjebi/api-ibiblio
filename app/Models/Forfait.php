<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Forfait extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'montant',
        'duree', // durée en mois
    ];

    // Relation avec les abonnements
    public function abonnements()
    {
        return $this->hasMany(Abonnement::class, 'forfait_id');
    }
    
}