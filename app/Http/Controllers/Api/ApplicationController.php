<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Application;
use App\Models\ApplicationMeta;
use App\Models\Site;
use App\Http\Resources\ApplicationsResource;
use App\Http\Requests\ApplicationRequest;
use Dcblogdev\Box\Facades\Box;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;

class ApplicationController extends Controller
{
    public function index() {
        return Application::all();
    }

    public function show(Application $application)
    {
        return new ApplicationsResource($application);
    }

    public function create(Request $request)
    {
        $find_site = Site::where( 'site_url', $request->site_url )->first();
        if($find_site){
            $site_id = $find_site->id;
            Log::info('Check exist Site_id:'.$site_id);
        }else{
            $save_site_data = array(
                'site_name' => $request->site_name,
                'site_url' => $request->site_url,
                'trade_name' => $request->trade_name,
                'site_hash' => $request->site_hash
            );
            $this_site = Site::create($save_site_data);
            $site_id = $this_site->id;
            Log::info('Create Site_id:'.$site_id);
        }

        $latest_app = Application::orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->first();
        if($latest_app){
            $set_num = substr($latest_app->application_id, 2);
        }else{
            $set_num = 1;
        }
        $set_num = substr($set_num, 0, -1);
        $set_num = (int)$set_num;
        if(is_numeric($set_num))$set_num++;
        $set_num = sprintf('%08d', $set_num);
        $application_id = 'WC'.$set_num.'1';
        $save_app_data = array(
            'application_id' => $application_id,
            'site_id' => $site_id,
            'email' => $request->email,
            'phone' => $request->phone,
            'ceo' => $request->ceo,
            'ceo_kana' => $request->ceo_kana,
            'ceo_birthday' => $request->ceo_birthday,
            'gmv_flag' => $request->gmv_flag,
            'average_flag' => $request->average_flag,
        );
        $application = Application::create( $save_app_data );
        Log::info('Create application.');

        // Save survey data to application_metas table.
        $survey_meta_keys = [
            'survey01' => 'securitySurvey01Radio',
            'survey02' => 'securitySurvey01Text',
            'survey03' => 'securitySurvey11Check',
            'survey04' => 'securitySurvey12Check',
            'survey05' => 'securitySurvey13Check',
            'survey06' => 'securitySurvey14Check',
            'survey07' => 'securitySurvey10Text',
            'survey08' => 'securitySurvey08Radio',
            'survey09' => 'securitySurvey09Radio',
        ];
        $survey_data = [];
        for ($i = 1; $i <= 9; $i++) {
            $survey_key = sprintf('survey%02d', $i);
            $survey_meta_key = $survey_meta_keys[$survey_key] ?? null;
            if ($request->has($survey_key)) {
                $survey_value = $request->$survey_key;
                $suevey_type = 'string'; // Default type
                if(1 === $survey_value || 0 === $survey_value) {
                    $suevey_type = 'boolean';
                } elseif (is_numeric($survey_value)) {
                    $suevey_type = 'integer';
                }
                $survey_data[] = [
                    'application_id' => $application->id,
                    'key' => $survey_meta_key,
                    'value' => $survey_value,
                ];
            }
        }

        if (!empty($survey_data)) {
            ApplicationMeta::insert($survey_data);
            Log::info('Create application metas: ' . count($survey_data) . ' records');
        }

        // Add CSV file
        $file_url = storage_path('box_data').'/woocommerce_merchant_list.csv';
        $gmv_flag = ($request->gmv_flag == 1) ? '1億円未満' : '1億円以上';
        $average_flag = ($request->average_flag == 1) ? '5万円未満' : '５万円以上';
        if($request->survey01 == 'yes'){
            $survey01 = 'はい';
        }elseif($request->survey01 == 'no'){
            $survey01 = 'いいえ';
        }else{
            $survey01 = 'わからない';
        }
        $survey03 = ($request->survey03 == 1) ? 'はい' : 'いいえ';
        $survey04 = ($request->survey04 == 1) ? 'はい' : 'いいえ';
        $survey05 = ($request->survey05 == 1) ? 'はい' : 'いいえ';
        $survey06 = ($request->survey06 == 1) ? 'はい' : 'いいえ';
        $survey08 = ($request->survey08 == 'yes') ? 'はい' : 'いいえ';
        $survey09 = ($request->survey09 == 'yes') ? 'はい' : 'いいえ';
        $unitime = time();

        $csv_data = array(
            $application_id,// 申込番号
            date('Y/n/j H:i:s', $unitime),// 申込日時
            $request->trade_name,// 商号/屋号
            $request->site_name,// 貴社のECサイト名
            $request->site_url,// 貴社のECサイトURL
            $request->email,// Paidy登録用メールアドレス
            $request->phone,// ご担当窓口電話番号
            $request->ceo,// 代表者（姓名）
            $request->ceo_kana,// 代表者カナ（セイメイ）
            $request->ceo_birthday,// 代表者生年月日（西暦）
            $gmv_flag,// 年間流通総額
            $average_flag,// ご注文あたりの平均購入額
            "",// セキュリティアンケートQ1
            "",// セキュリティアンケートQ2
            "",// セキュリティアンケートQ3
            "",// セキュリティアンケートQ4
            "",// セキュリティアンケートQ5
            "",// セキュリティアンケートQ6
            "",// セキュリティアンケートQ7
            $survey08,// セキュリティアンケートQ8
            $survey09,// セキュリティアンケートQ9
            "",// 法人/個人事業主
            "",// 法人番号
            "",// 商号/屋号（カナ）
            "",// 登記簿住所・郵便番号
            "",// 登記簿住所・住所
            "",// 所在地・郵便番号
            "",// 所在地・住所
            "",// 商材1
            "",// 商材2
            "",// 商材3
            "",// 商材(その他の場合)
            "",// 販売方法
            "",// 特商法URL
            "",// プライバシーポリシーURL
            "",// 担当者名
            "",// 調査結果
            $survey01,// JCAアンケート01
            $request->survey02,// JCAアンケート入力欄01
            "",// JCAアンケート02
            "",// JCAアンケート03
            "",// JCAアンケート04
            "",// JCAアンケート05
            "",// JCAアンケート06
            "",// JCAアンケート07
            "",// JCAアンケート08
            "",// JCAアンケート09
            "",// JCAアンケート10
            $survey03,// JCAアンケート11
            $survey04,// JCAアンケート12
            $survey05,// JCAアンケート13
            $survey06,// JCAアンケート14
            $request->survey07,// JCAアンケート入力欄02
        );
        if(file_exists($file_url)){
            $fp = fopen($file_url, 'a');
        }else{
            $fp = fopen($file_url, 'w');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp,
                array(
                    "申込番号",
                    "申込日時",
                    "商号/屋号",
                    "貴社のECサイト名",
                    "貴社のECサイトURL",
                    "Paidy登録用メールアドレス",
                    "ご担当窓口電話番号","代表者（姓名）",
                    "代表者カナ（セイメイ）",
                    "代表者生年月日（西暦）",
                    "年間流通総額",
                    "ご注文あたりの平均購入額",
                    "セキュリティアンケートQ1",
                    "セキュリティアンケートQ2",
                    "セキュリティアンケートQ3",
                    "セキュリティアンケートQ4",
                    "セキュリティアンケートQ5",
                    "セキュリティアンケートQ6",
                    "セキュリティアンケートQ7",
                    "セキュリティアンケートQ8",
                    "セキュリティアンケートQ9",
                    "法人/個人事業主",
                    "法人番号",
                    "商号/屋号（カナ）",
                    "登記簿住所・郵便番号",
                    "登記簿住所・住所",
                    "所在地・郵便番号",
                    "所在地・住所",
                    "商材1",
                    "商材2",
                    "商材3",
                    "商材(その他の場合)",
                    "販売方法",
                    "特商法URL",
                    "プライバシーポリシーURL",
                    "担当者名",
                    "調査結果",
                    "JCAアンケート01",
                    "JCAアンケート入力欄01",
                    "JCAアンケート02",
                    "JCAアンケート03",
                    "JCAアンケート04",
                    "JCAアンケート05",
                    "JCAアンケート06",
                    "JCAアンケート07",
                    "JCAアンケート08",
                    "JCAアンケート09",
                    "JCAアンケート10",
                    "JCAアンケート11",
                    "JCAアンケート12",
                    "JCAアンケート13",
                    "JCAアンケート14",
                    "JCAアンケート入力欄02"
                )
            );
        }
        fputcsv($fp, $csv_data);
        fclose($fp);

        return response()->json($application);
    }

    public function update(ApplicationRequest $request, Application $application)
    {
        $data = $request->validated();
        $application->update($data);
        return new ApplicationsResource($application);
    }

    public function destroy( Application $application)
    {
      $application->delete();
      return response("", 204);
    }
}
