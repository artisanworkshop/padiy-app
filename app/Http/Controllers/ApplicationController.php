<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Application;
use App\Services\PaidyCallbackSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function __construct(private PaidyCallbackSender $sender)
    {
    }

    /**
     * 審査結果を加盟店サイトへ再送信する（CSV の再アップロード不要）。
     * DB に保存済みの paidy_status とキーを使って PaidyCallbackSender で 1 件だけ送る。
     */
    public function resend(Application $application)
    {
        if (empty($application->paidy_status)) {
            return redirect()->route('application.index')
                ->with('error', $application->application_id . ': 審査結果が未登録のため再送信できません。先に CSV を取り込んでください。');
        }

        $site = DB::table('sites')->where('id', $application->site_id)->select('site_url', 'site_hash')->first();
        if (!$site) {
            return redirect()->route('application.index')
                ->with('error', $application->application_id . ': サイト情報（site_id: ' . $application->site_id . '）が見つかりません。');
        }

        $result = $this->sender->send(
            $application->application_id,
            $application->paidy_status,
            [
                'public_live_key' => $application->public_live_key,
                'secret_live_key' => $application->secret_live_key,
                'public_test_key' => $application->public_test_key,
                'secret_test_key' => $application->secret_test_key,
            ],
            $site,
            $application->state,
            $application->plugin_version
        );

        $application->set_status = $result['success'] ? 1 : 0;
        $application->updated_at = Carbon::now();
        $application->save();

        if ($result['success']) {
            return redirect()->route('application.index')
                ->with('status', $application->application_id . ': 再送信に成功しました（HTTP ' . $result['status'] . '）。');
        }

        return redirect()->route('application.index')
            ->with('error', $application->application_id . ': 再送信に失敗しました。' . $result['error']);
    }

    public function index( Request $request ){
        $application_id = $request->input('application_id');
        $site_name = $request->input('site_name');
        $paidy_status = $request->input('paidy_status');
        $set_status = $request->input('set_status');
        $created_from = $request->input('created_from');
        $created_until = $request->input('created_until');
        $updated_from = $request->input('updated_from');
        $updated_until = $request->input('updated_until');

        $query = Application::query();
        $query->select(
            'applications.*',
            'sites.site_name',
            'sites.site_url',
            'applications.updated_at as application_updated_at'
        );
        $query->leftjoin('sites', function ($query) use ($request) {
            $query->on('applications.site_id', '=', 'sites.id');
            });
        if(!empty($application_id)) {
            $query->where('application_id', 'LIKE', "%{$application_id}%");
        }
        if(!empty($site_name)) {
            $query->where('site_name', 'LIKE', "%{$site_name}%");
        }
        if($paidy_status == 'null') {
            $query->where('paidy_status', '=', null);
        }elseif(!empty($paidy_status)){
            $query->where('paidy_status', 'LIKE', $paidy_status);
        }
        if(!empty($set_status)) {
            $query->where('set_status', 'LIKE', $set_status);
        }
        if (isset($created_from) && isset($created_until)) {
            $query->whereBetween('applications.created_at', [$created_from, $created_until]);
        }
        if (isset($updated_from) && isset($updated_until)) {
            $query->whereBetween('applications.updated_at', [$updated_from, $updated_until]);
        }
        $applications = $query->get();
        return view('application.index', compact('applications', 'application_id', 'site_name', 'paidy_status', 'set_status', 'created_from', 'created_until', 'updated_from', 'updated_until'));
    }
}
