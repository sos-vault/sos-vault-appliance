<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'input_tokens_used',
        'output_tokens_used',
        'total_tokens_used',
        'input_tokens_available',
        'output_tokens_available',
        'total_tokens_available',
        'queries_made',
        'reports_made',

        'input_tokens_used_current_session',
        'output_tokens_used_current_session',
        'total_tokens_used_current_session',
        'queries_per_current_session',
        'reports_per_current_session',

        'input_tokens_used_last_session',
        'output_tokens_used_last_session',
        'total_tokens_used_last_session',
        'queries_per_last_session',
        'reports_per_last_session',

        'number_of_sessions',

        'average_input_tokens_used_per_session',
        'average_output_tokens_used_per_session',
        'average_total_tokens_used_per_session',
        'average_queries_per_session',
        'average_reports_per_session',
        'error_average_input_tokens_used_per_session',
        'error_average_output_tokens_used_per_session',
        'error_average_total_tokens_used_per_session',
        'error_average_queries_per_session',
        'error_average_reports_per_session',
    ];

    public static function addTokens($userId, $inputTokens, $outputTokens, $report = false) {
        $userToken = self::firstOrCreate(['user_id' => $userId]);

        $userToken->increment('input_tokens_used', $inputTokens);
        $userToken->increment('output_tokens_used', $outputTokens);
        $userToken->increment('total_tokens_used', $inputTokens + $outputTokens);
        $userToken->increment('queries_made', 1);

        $userToken->increment('input_tokens_used_current_session', $inputTokens);
        $userToken->increment('output_tokens_used_current_session', $outputTokens);
        $userToken->increment('total_tokens_used_current_session', $inputTokens + $outputTokens);
        $userToken->increment('queries_per_current_session', 1);

        $userToken->decrement('input_tokens_available', $inputTokens);
        $userToken->decrement('output_tokens_available', $outputTokens);
        $userToken->decrement('total_tokens_available', $inputTokens + $outputTokens);

        if($report) {
            $userToken->increment('reports_made', 1);
            $userToken->increment('reports_per_current_session', 1);
        }
    }

    public function resetSessionTokens($userId) {
        // the average of N numbers and the average of a number with the average of n-1 numbers is different.
        // however the difference be calculated like this:
        // $N = $userToken->number_of_sessions;
        // $error = (($N-2)($userToken->average_input_tokens_used_per_session - ($N-1)*$userToken->input_tokens_used_current_session)/(2*$N($N-1)));

        $userToken = self::firstOrCreate(['user_id' => $userId]);

        // only if there were assistant queries in the session...
        if($userToken->input_tokens_used_current_session > 0 && $userToken->output_tokens_used_current_session > 0) {
            $userToken->increment('number_of_sessions', 1);

            // save current as last session
            $userToken->input_tokens_used_last_session  = $userToken->input_tokens_used_current_session;
            $userToken->output_tokens_used_last_session = $userToken->output_tokens_used_current_session;
            $userToken->total_tokens_used_last_session  = $userToken->total_tokens_used_current_session;
            $userToken->queries_per_last_session        = $userToken->queries_per_current_session;
            $userToken->reports_per_last_session        = $userToken->reports_per_current_session;


            // calculate the pseudo average
            $N = ($userToken->average_input_tokens_used_per_session) ? 2 : 1;

            $userToken->average_input_tokens_used_per_session  = floatval(($userToken->average_input_tokens_used_per_session + $userToken->input_tokens_used_current_session)/$N);
            $userToken->average_output_tokens_used_per_session = floatval(($userToken->average_output_tokens_used_per_session + $userToken->output_tokens_used_current_session)/$N);
            $userToken->average_total_tokens_used_per_session  = floatval(($userToken->average_total_tokens_used_per_session + $userToken->total_tokens_used_current_session)/$N);
            $userToken->average_queries_per_session            = floatval(($userToken->average_queries_per_session + $userToken->queries_per_current_session)/$N);
            $userToken->average_reports_per_session            = floatval(($userToken->average_reports_per_session + $userToken->reports_per_current_session)/$N);

            $N = $userToken->number_of_sessions;
            if($N > 1) {
                $userToken->error_average_input_tokens_used_per_session  = $this->error($userToken->average_input_tokens_used_per_session, $userToken->input_tokens_used_current_session, $N);
                $userToken->error_average_output_tokens_used_per_session = $this->error($userToken->average_output_tokens_used_per_session, $userToken->output_tokens_used_current_session, $N);
                $userToken->error_average_total_tokens_used_per_session  = $this->error($userToken->average_total_tokens_used_per_session, $userToken->total_tokens_used_current_session, $N);
                $userToken->error_average_queries_per_session            = $this->error($userToken->average_queries_per_session, $userToken->queries_per_current_session, $N);
                $userToken->error_average_reports_per_session            = $this->error($userToken->average_reports_per_session, $userToken->reports_per_current_session, $N);
            } else {
                $userToken->error_average_input_tokens_used_per_session  = 0;
                $userToken->error_average_output_tokens_used_per_session = 0;
                $userToken->error_average_total_tokens_used_per_session  = 0;
                $userToken->error_average_queries_per_session            = 0;
                $userToken->error_average_reports_per_session            = 0;
            }

            $userToken->input_tokens_used_current_session  = 0;
            $userToken->output_tokens_used_current_session = 0;
            $userToken->total_tokens_used_current_session  = 0;
            $userToken->queries_per_current_session        = 0;
            $userToken->reports_per_current_session        = 0;

            $userToken->save();
        }
    }

    public function error($a, $b, $n) {
        if($n <= 1) {
            return(0);
        }
        $dividendo = (($n-2)*($a - ($n-1)*$b));
        $divisor   = ((2*$n)*($n-1));
        $resultado = $dividendo/$divisor;
        return floatval($resultado);

    }

}
