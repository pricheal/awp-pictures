<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Helpers;
use Laravel\Lumen\Routing\Controller;

/*
 * file - api/app/Http/Controllers/UserController.php
 * author - Patrick Richeal
 * 
 * User controller file, has all the functions to do the various
 * actions related to a user
 */

class UserController extends Controller
{
    /*
     * Checks to see if the given username is taken
     */
    public function usernameTaken(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string'
        ]);

        // Check to see if username is already taken
        $username_taken = app('db')->table('users')->where('username', $request->input('username'))->exists();

        return response()->json(['taken' => $username_taken]);
    }

    /**
     * Register a new user, returns user info and api token via json response
     */
    public function register(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string|min:4|max:32|unique:users',
            'password' => 'required|string|min:8|max:256',
            'email' => 'required|string|email|min:3|max:256'
        ]);

        $email_verification_code = $this->getUniqueUserEmailVerificationCode();

        // Insert user into users table
        $user_id = app('db')->table('users')->insertGetId([
            'username' => $request->input('username'),
            'password_hash' => password_hash($request->input('password'), PASSWORD_DEFAULT),
            'email' => $request->input('email'),
            'email_verification_code' => $email_verification_code
        ]);

        // Send verification email
        mail($request->input('email'), 'Verify email address', "Use the following link to verify your email address: http://elvis.rowan.edu/~richealp7/awp/awp-pictures/client/build/verify-email/".$email_verification_code);

        // Add user info and api token to response
        return response()->json([
            'user_id' => (string) $user_id,
            'username' => $request->input('username'),
            'api_token' => $this->generateApiToken($user_id)
        ]);
    }

    /**
     * Returns an unused user email verification code
     * 
     * @return string The unique verification code
     */
    private function getUniqueUserEmailVerificationCode(): string {
        // Create a unique code that has never been used
        $already_exists = true;
        while ($already_exists) {
            $verification_code = Helpers::generateRandomString(32);
            $already_exists = app('db')->table('users')->where('email_verification_code', $verification_code)->exists();
        }

        return $verification_code;
    }

    /**
     * Verify a user email
     */
    public function verifyEmail(Request $request)
    {
        $this->validate($request, [
            'verification_code' => 'required|string|size:32'
        ]);

        // Check what user this verification code is for (if any)
        $email = app('db')->table('users')->where('email_verification_code', $request->input('verification_code'))->value('email');
        
        // If verification code is valid, mark email as verified
        if ($email) {
            app('db')->table('users')
                ->where('email_verification_code', $request->input('verification_code'))
                ->update(['email_verified' => 1]);
            
            return response()->json(['email' => $email]);
        }

        return response()->json(['error' => 'Verification code not found']);
    }

    /**
     * Validate a username and password, returns user info and api token via json response
     */
    public function login(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Determine if given username and password is valid
        $user = app('db')->table('users')
            ->select('id', 'password_hash')
            ->where('username', $request->input('username'))
            ->first();
        
        if ($user) {
            // If the given password matches the given users password
            if (password_verify($request->input('password'), $user->password_hash)) {
                // Add user info and api token to response
                return response()->json([
                    'user_id' => (string) $user->id,
                    'username' => $request->input('username'),
                    'api_token' => $this->generateApiToken($user->id)
                ]);
            }
        }

        return response()->json(['error' => 'Invalid login credentials']);
    }

    /**
     * Generates a new api token for the given user
     * 
     * @param string $user_id The user to generate the api token for
     * @return string The generated api token
     */
    private function generateApiToken($user_id): string {
        // Create a unique token that has never been used
        $already_exists = true;
        while ($already_exists) {
            $api_token = Helpers::generateRandomString(32);
            $already_exists = app('db')->table('api_tokens')->where('token', $api_token)->exists();
        }

        // Add token to api_tokens table for given user
        app('db')->table('api_tokens')->insert([
            'token' => $api_token,
            'user_id' => $user_id
        ]);

        return $api_token;
    }

    /**
     * Update a user's password
     */
    public function updatePassword(Request $request)
    {
        $this->validate($request, [
            'password' => 'required|string|min:8|max:256',
            'current_password' => 'required_without:forgot_password_code|string',
            'user_id' => 'required_with:current_password|string',
            'forgot_password_code' => 'required_without:current_password|string|size:32'
        ]);

        // If current password is set, make sure it is the right password for the given user id
        if ($request->has('current_password')) {
            $password_hash = app('db')->table('users')
                ->select('password_hash')
                ->where('id', $request->input('user_id'))
                ->value('password_hash');
            
            // Ensure current password is correct
            if (!password_verify($request->input('current_password'), $password_hash)) {
                return response()->json(['error' => 'Current password is incorrect']);
            }
        } else {
            // If the current password isn't set, that means forgot password code is, see
            // if we can find what user the code is for
            $user_id = app('db')->table('forgot_password_codes')
                ->where('code', $request->input('forgot_password_code'))
                ->where('used', 0)
                ->value('user_id');
            
            if ($user_id) {
                // Forgot pass code is valid, mark it as used
                app('db')->table('forgot_password_codes')
                    ->where('code', $request->input('forgot_password_code'))
                    ->where('used', 0)
                    ->where('user_id', $user_id)
                    ->update(['used' => 1]);
            } else {
                return response()->json(['error' => 'Forgot password code not found']);
            }
        }

        // Change password
        app('db')->table('users')
            ->where('id', $request->input('user_id') ?? $user_id)
            ->update(['password_hash' => password_hash($request->input('password'), PASSWORD_DEFAULT)]);

        return response()->json((object)[]);
    }

    /**
     * Create a forgot password request, sends an email to the supplied email with instructions
     * to reset password
     */
    public function forgotPassword(Request $request) {
        $this->validate($request, [
            'username' => 'required|string',
            'email' => 'required|string'
        ]);

        // Look for user with supplied username and email
        $user = app('db')->table('users')
            ->select('id', 'email')
            ->where('username', $request->input('username'))
            ->where('email', $request->input('email'))
            ->first();

        if ($user) {
            // Generate unique forgot password code for this user
            $forgot_password_code = $this->generateForgotPasswordCode($user->id);

            // Send forgot password email
            mail($user->email, 'Forgot password', "Use the following link to reset your password: http://elvis.rowan.edu/~richealp7/awp/awp-pictures/client/build/change-password/".$forgot_password_code);

            return response()->json((object)[]);
        }
        
        return response()->json(['error' => 'Could not find that user']);
    }

    /**
     * Generates a new forgot password code for the given user
     * 
     * @param string $user_id The user to generate the forgot password code for
     * @return string The generated forgot password code
     */
    private function generateForgotPasswordCode($user_id): string {
        // Create a unique forgot password code that has never been used
        $already_exists = true;
        while ($already_exists) {
            $forgot_password_code = Helpers::generateRandomString(32);
            $already_exists = app('db')->table('forgot_password_codes')->where('code', $forgot_password_code)->exists();
        }

        // Add forgot password code to forgot_password_codes table for given user
        app('db')->table('forgot_password_codes')->insert([
            'code' => $forgot_password_code,
            'user_id' => $user_id
        ]);

        return $forgot_password_code;
    }

    /*
     * Delete a user
     */
    public function delete(Request $request, $user_id) {
        // Make sure they are allowed to delete user
        if ($user_id != $request->user()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Delete user
        app('db')->table('users')
            ->where('id', $user_id)
            ->delete();
        
        return response()->json((object)[]);
    }
}
