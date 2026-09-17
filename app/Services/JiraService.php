<?php

namespace App\Services;

use App\Models\ITSMProvider;
use App\Providers\VaultTools;
use Exception;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JiraService
{
    private $err_mesg;

    public function __construct() {}

    public function getAttachemnets($user, $issueid)
    {

        if (! isset($user)) {
            return null;
        }
        if (! isset($issueid)) {
            return null;
        }

        $provider = ITSMProvider::where('provider', 'JSM')
            ->where('uid', $user->id)
            ->first();

        if (! isset($provider) || $provider->provider != 'JSM') {
            return null;
        }

        $URI = preg_match('/https:..*/', $provider->url) ? $provider->url : "https://{$provider->url}";

        // SSRF guard: reject private/loopback addresses
        $host = parse_url($URI, PHP_URL_HOST);
        if ($host) {
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                Log::error('JiraService: blocked SSRF attempt to '.$host);

                return null;
            }
        }

        if ($provider->provider == 'JSM') {
            $endp = "/rest/api/2/issue/{$issueid}";
            $uri = "{$URI}{$endp}";
            $usr = $provider->user;

            $encrypter = new Encrypter(
                key: getSvaultKey('svault0'),
                cipher: config('app.cipher'),
            );
            $pas = $encrypter->decrypt($provider->password);
        }

        $vtools = new VaultTools($user);

        $cid = 0;
        $vid = $vtools->getVaultId();
        $uid = $user->id;
        $gid = $user->id;

        $wkng = $vtools->getMountPoint().'/.wkng';

        /*
        // get the cached file if not too old (24 hours)
        if(is_dir($wkng)) {
            $issue_filename = "{$wkng}/{$issueid}.json";
            if(is_file($issue_filename)) {
                $mtime = filemtime($issue_filename);
                $now   = time();
                if($now - $mtime  < 24*3600) {
                    $data = json_decode(file_get_contents($issue_filename));
                    return $data;
                }
            }
        }
        */

        /*
        // test
        $file = storage_path("jsd.json");
        $data = json_decode(file_get_contents($file));
        return $data;
        */

        static $lastCallTime = null;

        // Add a delay of 1 second between requests
        if ($lastCallTime && microtime(true) - $lastCallTime < 1) {
            usleep(1000000 - (microtime(true) - $lastCallTime) * 1000000);
        }

        $lastCallTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->withBasicAuth($usr, $pas)
                ->timeout(30)
                ->connectTimeout(30)
                ->get($uri);

            $response->throw();

            $json = $response->json();

            return (object) [
                'attachments' => data_get($json, 'fields.attachment', []),
                'description' => data_get($json, 'fields.description'),
                'customer' => data_get($json, "fields.{$provider->customer_field}"),
                'link' => rtrim($URI, '/').'/browse/'.$issueid,
                'raw' => $json,
            ];
        } catch (ConnectionException $e) {
            $message = 'connection: '.$e->getMessage();
            Log::error($message);
        } catch (RequestException $e) {
            $message = 'request: '.$e->getMessage();
            Log::error($message);
        } catch (Exception $e) {
            $message = 'error: '.$e->getMessage();
            Log::error($message);
        } finally {
            if (isset($pas)) {
                sodium_memzero($pas);
            }
        }
    }

    public function testConnection($user): bool
    {
        if (! isset($user)) {
            return false;
        }

        $provider = ITSMProvider::where('provider', 'JSM')
            ->where('uid', $user->id)
            ->first();

        if (! isset($provider) || $provider->provider != 'JSM') {
            return false;
        }

        $URI = preg_match('/https:..*/', $provider->url) ? $provider->url : "https://{$provider->url}";

        // SSRF guard: reject private/loopback addresses
        $host = parse_url($URI, PHP_URL_HOST);
        if ($host) {
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                Log::error('JiraService: blocked SSRF attempt to '.$host);

                return false;
            }
        }

        $uri = "{$URI}/rest/api/2/myself";
        $usr = $provider->user;

        $encrypter = new Encrypter(
            key: getSvaultKey('svault0'),
            cipher: config('app.cipher'),
        );
        $pas = $encrypter->decrypt($provider->password);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->withBasicAuth($usr, $pas)
                ->timeout(15)
                ->connectTimeout(15)
                ->get($uri);

            return $response->successful();
        } catch (ConnectionException $e) {
            Log::error('JiraService testConnection: '.$e->getMessage());
        } catch (RequestException $e) {
            Log::error('JiraService testConnection: '.$e->getMessage());
        } catch (Exception $e) {
            Log::error('JiraService testConnection: '.$e->getMessage());
        } finally {
            if (isset($pas)) {
                sodium_memzero($pas);
            }
        }

        return false;
    }

    public function attachFileToIssue($user, string $issueid, string $filePath, string $filename): bool
    {
        if (! isset($user)) {
            return false;
        }

        $provider = ITSMProvider::where('provider', 'JSM')
            ->where('uid', $user->id)
            ->first();

        if (! isset($provider) || $provider->provider != 'JSM') {
            return false;
        }

        $URI = preg_match('/https:..*/', $provider->url) ? $provider->url : "https://{$provider->url}";

        // SSRF guard: reject private/loopback addresses
        $host = parse_url($URI, PHP_URL_HOST);
        if ($host) {
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                Log::error('JiraService: blocked SSRF attempt to '.$host);

                return false;
            }
        }

        $uri = "{$URI}/rest/api/2/issue/{$issueid}/attachments";
        $usr = $provider->user;

        $encrypter = new Encrypter(
            key: getSvaultKey('svault0'),
            cipher: config('app.cipher'),
        );
        $pas = $encrypter->decrypt($provider->password);

        static $lastCallTime = null;

        // Add a delay of 1 second between requests
        if ($lastCallTime && microtime(true) - $lastCallTime < 1) {
            usleep(1000000 - (microtime(true) - $lastCallTime) * 1000000);
        }

        $lastCallTime = microtime(true);

        try {
            if (! is_file($filePath) || ! is_readable($filePath)) {
                Log::error("JiraService attachFileToIssue: file not found or not readable: {$filePath}");

                return false;
            }

            $fp = fopen($filePath, 'r');

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Atlassian-Token' => 'no-check',
            ])
                ->withBasicAuth($usr, $pas)
                ->timeout(60)
                ->connectTimeout(30)
                ->attach('file', $fp, $filename)
                ->post($uri);

            $response->throw();

            return $response->successful();
        } catch (ConnectionException $e) {
            Log::error('JiraService attachFileToIssue connection: '.$e->getMessage());
        } catch (RequestException $e) {
            Log::error('JiraService attachFileToIssue request: '.$e->getMessage());
        } catch (Exception $e) {
            Log::error('JiraService attachFileToIssue error: '.$e->getMessage());
        } finally {
            if (isset($pas)) {
                sodium_memzero($pas);
            }
        }

        return false;
    }

    public function downloadFile($user, $issueid, $file, $reportname)
    {
        if (! isset($user)) {
            return null;
        }
        if (! isset($issueid)) {
            return null;
        }

        if (! isset($file)) {
            return null;
        }

        $provider = ITSMProvider::where('provider', 'JSM')
            ->where('uid', $user->id)
            ->first();

        if (! isset($provider) || $provider->provider != 'JSM') {
            return null;
        }

        if ($provider->provider == 'JSM') {
            $usr = $provider->user;

            $encrypter = new Encrypter(
                key: getSvaultKey('svault0'),
                cipher: config('app.cipher'),
            );
            $pas = $encrypter->decrypt($provider->password);
        }

        $url = $file->content;

        // SSRF guard: reject private/loopback addresses
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                Log::error('JiraService: blocked SSRF attempt to '.$host);
                if (isset($pas)) {
                    sodium_memzero($pas);
                }

                return;
            }
        }

        static $lastCallTime = null;

        // Add a delay of 1 second between requests
        if ($lastCallTime && microtime(true) - $lastCallTime < 1) {
            usleep(1000000 - (microtime(true) - $lastCallTime) * 1000000);
        }

        $lastCallTime = microtime(true);

        try {
            if (! $this->remoteRequest($url, $reportname, $usr, $pas)) {
                Log::error($this->err_mesg);
            }
        } finally {
            if (isset($pas)) {
                sodium_memzero($pas);
            }
        }
    }

    public function remoteRequest($url, $file, $usr, $pas)
    {
        // $chunksize = 10 * (1024 * 1024); // 10 Megs

        // Initiate cURL
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_USERAGENT, 'sos-vault');
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMEOUT, 600);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);

        $fp = fopen($file, 'w+');
        curl_setopt($curl, CURLOPT_FILE, $fp);

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

        $cookiejar = tempnam('/var/tmp', 'jar-');
        if ($cookiejar) {
            curl_setopt($curl, CURLOPT_COOKIEJAR, $cookiejar);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $cookiejar);
            curl_setopt($curl, CURLOPT_COOKIESESSION, true);
        }

        /*
        if($DEBUG){
            $snarelog=fopen("/var/log/snare.log", "a");
            curl_setopt($curl, CURLOPT_VERBOSE, TRUE);
            curl_setopt($curl, CURLOPT_STDERR, $snarelog);
        }
        */

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERPWD, "{$usr}:{$pas}");
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');

        $response = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        $code = $info['http_code'];

        $this->err_mesg = null;
        if (! $response) {
            $this->err_mesg = 'remote request error: invalid response';
        }
        if ($code > 206) {
            $this->err_mesg = $this->http_status_codes[$code] ?? "HTTP error {$code}";
        }
        if ($error) {
            $this->err_mesg = "internal request error: {$error}";
        }

        curl_close($curl);
        fclose($fp);
        if ($cookiejar && is_file($cookiejar)) {
            unlink($cookiejar);
        }

        if ($this->err_mesg) {
            return false;
        }

        return true;
    }

    private $http_status_codes = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => '(Unused)',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized user',
        402 => 'Payment Required',
        403 => 'Forbidden. Wrong password',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        419 => 'Authentication Timeout',
        420 => 'Enhance Your Calm',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        424 => 'Method Failure',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        444 => 'No Response',
        449 => 'Retry With',
        450 => 'Blocked by Windows Parental Controls',
        451 => 'Unavailable For Legal Reasons',
        494 => 'Request Header Too Large',
        495 => 'Cert Error',
        496 => 'No Cert',
        497 => 'HTTP to HTTPS',
        499 => 'Client Closed Request',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        509 => 'Bandwidth Limit Exceeded',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
        598 => 'Network read timeout error',
        599 => 'Network connect timeout error',
    ];
}
