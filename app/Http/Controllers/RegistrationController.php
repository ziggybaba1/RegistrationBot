<?php

namespace App\Http\Controllers;

use App\Jobs\RegisterUser;

class RegistrationController extends Controller
{
    public function register()
    {
        // Dispatch the job
        RegisterUser::dispatch();

        return response()->json(['message' => 'Registration process started']);
    }
}
