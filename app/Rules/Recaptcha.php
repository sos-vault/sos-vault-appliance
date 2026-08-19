<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class Recaptcha implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $g_response = null;
            $g_response = Http::asForm()->post("https://www.google.com/recaptcha/api/siteverify",
                [
                    "secret" => config("services.recaptcha.secret_key"),
                    "response" => $value,
                    "remoteip" => \request()->ip()
                ]
            );

            if(!$g_response->json(key: 'success')) {
                $message = "The {$attribute} is invalid";
                Log::error($message);
                $fail("The {$attribute} is invalid");
            }
        } catch(ConnectionException $e) {
            $message = "connection: " . $e->getMessage();
            Log::error($message);
        } catch(RequestException $e) {
            $message = "request: " . $e->getMessage();
            Log::error($message);
        } catch(Exception $e) {
            $message = "error: " . $e->getMessage();
            Log::error($message);
        }
    }
}
