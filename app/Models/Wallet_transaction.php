<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet_transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'livre_id',
        'user_id',
        'montant',
        'type_transaction',
        'date_transaction',
    ];

}