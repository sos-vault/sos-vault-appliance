<?php

namespace Wave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\User;
use App\Providers\VaultTools;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class sosContentsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('vault');
    }

    /*
    public function sosContents(Request $request, $vid, $did, $fid, $cid) {
        return view('theme::sos.filebrowser', [
            'vid' => $vid,
            'did' => $did,
            'fid' => $fid,
            'cid' => $cid,
            'sme' => 0
        ]);
    }
    */

    /*
    public function sosTool(Request $request, $vid, $did, $tool, $cid) {
        return view('theme::tools.toolContainer', [
            'tool'  => $tool,
            'vid'   => $vid,
            'did'   => $did,
            'cid'   => $cid,
            'sme'   => 0
        ]);
    }
    */

    public function sosShared(Request $request, $hash)
    {
        /*
            1) build the url
            2) find the ContentsRequest record with the url to get vid, did, fid and cid
            3) is the share expired?
            4) is the document still shared? (annotations)
            5) if is not shared: abort(403, 'Permision Denied');
            6) if it is shared and the vault is not open, open the vault
        */
        $url = url("sosShared/{$hash}");
        $meta = ContentsRequest::where('url', $url)->first();

        if (! $meta) {
            $type = 'danger';
            $message = 'Shared link not found.';
            alertBadge($message, $type);

            return redirect('/dashboard');
        }

        if ($meta->status === 'EXPIRED') {
            $meta->delete();

            $type = 'danger';
            $message = 'Shared link has expired.';
            alertBadge($message, $type);

            return redirect('/dashboard');
        }

        // Enforce the expiry timestamp at read time too — PurgeExpiredRecords
        // only flips status to EXPIRED once a day, so a link could otherwise work
        // for hours past its expire date.
        if ($meta->expire && Carbon::parse($meta->expire)->isPast()) {
            $type = 'danger';
            $message = 'Shared link has expired.';
            alertBadge($message, $type);

            return redirect('/dashboard');
        }

        $vid = $meta->vault_id;
        $did = $meta->dir_id;
        $fid = $meta->file_id;
        $cid = $meta->case_id;

        $annot = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();

        if (isset($annot) && $annot) {
            if ($annot->status == 'PRIVATE') {
                $type = 'danger';
                $message = 'Shared document is private.';
                alertBadge($message, $type);

                return redirect('/dashboard');
            }
        }

        // open someone else's vault
        $user = User::where('id', $meta->owner)->first();
        $vtools = new VaultTools($user);
        $sme = $vtools->isOpen() ? 2 : 1; // 2 don't close it later
        if ($sme == 1) {
            if (! $vtools->OpenVault()) {
                $type = 'danger';
                $message = 'Shared vault is private.';
                alertBadge($message, $type);

                return redirect('/dashboard');
            }
        }

        return redirect("/filebrowser/{$cid}/{$fid}?sme={$sme}&vid={$vid}&did={$did}");
    }

    public function sosSharedDir(Request $request, $hash)
    {
        $url = url("sosSharedDir/{$hash}");
        $meta = ContentsRequest::where('url', $url)->first();

        if (! $meta) {
            $type = 'danger';
            $message = 'Shared link not found.';
            alertBadge($message, $type);

            return redirect('/dashboard');
        }

        if ($meta->status === 'EXPIRED') {
            $meta->delete();

            $type = 'danger';
            $message = 'Shared link has expired.';
            alertBadge($message, $type);

            return redirect('/dashboard');
        }

        // Enforce the expiry timestamp at read time too — PurgeExpiredRecords
        // only flips status to EXPIRED once a day, so a link could otherwise work
        // for hours past its expire date.
        if ($meta->expire && Carbon::parse($meta->expire)->isPast()) {
            $type = 'danger';
            $message = 'Shared link has expired.';
            alertBadge($message, $type);

            return redirect('/dashboard');
        }

        $vid = $meta->vault_id;
        $did = $meta->dir_id;
        $cid = $meta->case_id;

        $annot = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', 0)->first();

        if (isset($annot) && $annot) {
            if ($annot->status == 'PRIVATE') {
                $type = 'danger';
                $message = 'Shared directory is private.';
                alertBadge($message, $type);

                return redirect('/dashboard');
            }
        }

        // open someone else's vault
        $user = User::where('id', $meta->owner)->first();
        $vtools = new VaultTools($user);
        $sme = $vtools->isOpen() ? 2 : 1; // 2 = don't close it later
        if ($sme == 1) {
            if (! $vtools->OpenVault()) {
                $type = 'danger';
                $message = 'Shared vault is private.';
                alertBadge($message, $type);

                return redirect('/dashboard');
            }
        }

        return redirect("/sosbrowser/{$cid}?sme={$sme}&vid={$vid}&did={$did}");
    }
}
