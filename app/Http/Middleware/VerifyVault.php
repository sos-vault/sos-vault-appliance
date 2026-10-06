<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use App\Providers\VaultTools;
use App\Helpers\sosVaultHelper;

class VerifyVault
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = ($request->user()) ? $request->user() : auth()->user();

        $vtools = new VaultTools($user);

        if (!$vtools->vaultExists()) {
            Log::error("no vault associated to {$user->username}");
            $message = "Your vault was automatically closed. Please logout and login again to open it.";
            return redirect()->route("wave.dashboard")->with('message', $message)->with('message_type', 'error');
        }

        if (!$vtools->isOpen()) {
            Log::error("user vault is closed: {$user->username}");
            $message = "Your vault was automatically closed. Please logout and login again to open it.";
            return redirect()->route("wave.dashboard")->with('message', $message)->with('message_type', 'error');
        }

        return $next($request);
    }
}
