<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CsvImportController extends Controller
{
    public function import(Request $request)
    {
        // バリデーション
        $request->validate([
//            'file' => 'required|file|mimes:csv,xlsx,xls|max:2048', // 最大2MB
        ]);

        // ファイルのアップロードと処理
        return $this->processFile($request);
    }

    private function processFile(Request $request)
    {
        $file = $request->file('file');
       // 現在の日時を使ってファイル名を生成
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $originalExtension = $file->getClientOriginalExtension();
        $filename = $timestamp . '.' . $originalExtension;

        // csvフォルダに保存
        $storedPath = $file->storeAs('csv', $filename);

        // 保存されたファイルのフルパスを取得
        $fullPath = Storage::path($storedPath);
        $spreadsheet = IOFactory::load($fullPath);
        $sheetData = $spreadsheet->getActiveSheet()->toArray();

        $error_msg = '';
        $error_msgs = array();
        if(empty($sheetData)){
            $error_msgs[0]['file'] = 'CSVファイルが空か、ヘッダーのみです。';
            return view('import-csv.show', compact( 'error_msgs' ));
        }
        foreach ($sheetData as $key => $row) {
            if ($row !== reset($sheetData)) {
                //Check application_id
                if( substr( $row[0], 0, 2 ) != 'WC'){
                    $error_msgs[$key]['id'] = '接頭辞が異なります。';
                }
                // Check status string
                $status_array = array('approved', 'rejected', 'canceled');
                if(!in_array($row[1], $status_array)){
                    $error_msgs[$key]['status'] = 'ステータスは approved,rejected,canceled でなければいけません。';
                }
                if( $row[1] == 'approved' ){
                    if(empty($row[2])){
                        $paidy_key[$key][] = 'public live key';
                    }
                    if(empty($row[3])){
                        $paidy_key[$key][] = 'public secret key';
                    }
                    if(empty($row[4])){
                        $paidy_key[$key][] = 'test live key';
                    }
                    if(empty($row[5])){
                        $paidy_key[$key][] = 'test secret key';
                    }
                }
                if((!empty($paidy_key[$key]))){
                    $error_msgs[$key]['key'] = '以下の key が抜けています。'.implode(', ', $paidy_key[$key]);
                }
            }
        }
        $result_msg = '';
        $api_success_list = array(); // API送信成功リスト
        $api_error_list = array();   // API送信失敗リスト

        if( empty( $error_msgs ) ){
            foreach ($sheetData as $row) {
                if ($row !== reset($sheetData)) {
                    $result = DB::table('applications')->where('application_id', $row[0])->update([
                        'paidy_status' => $row[1],
                        'public_live_key' => $row[2],
                        'secret_live_key' => $row[3],
                        'public_test_key' => $row[4],
                        'secret_test_key' => $row[5],
                        'updated_at' => Carbon::now()
                    ]);
                    if( !$result ){
                        $error_msg .= 'データベースに登録が失敗しました。ID: ' . $row[0] . "\n";
                    }
                }
            }

            if(empty($error_msg)){
                foreach ($sheetData as $row) {
                    if ($row !== reset($sheetData)) {
                        try {
                            // applicationsテーブルからsite_idとstateを取得
                            $application = DB::table('applications')
                                ->where('application_id', $row[0])
                                ->select('site_id', 'state')
                                ->first();

                            if (!$application) {
                                $api_error_list[] = [
                                    'application_id' => $row[0],
                                    'error' => 'アプリケーションIDが見つかりません。'
                                ];
                                continue;
                            }

                            // sitesテーブルからsite_urlとsite_hashを取得
                            $site = DB::table('sites')
                                ->where('id', $application->site_id)
                                ->select('site_url', 'site_hash')
                                ->first();

                            if (!$site) {
                                $api_error_list[] = [
                                    'application_id' => $row[0],
                                    'error' => 'サイトID: ' . $application->site_id . ' が見つかりません。'
                                ];
                                continue;
                            }
                            // site_hashを使って暗号化キーとIVを生成
                            $method = 'AES-256-CBC';
                            $key = substr(hash('sha256', $site->site_hash), 0, 32);
                            $iv = substr(hash('sha256', $site->site_hash . 'iv'), 0, 16);

                            // 各キーを暗号化
                            $encryptedPublicLiveKey = base64_encode(openssl_encrypt($row[2], $method, $key, OPENSSL_RAW_DATA, $iv));
                            $encryptedSecretLiveKey = base64_encode(openssl_encrypt($row[3], $method, $key, OPENSSL_RAW_DATA, $iv));
                            $encryptedPublicTestKey = base64_encode(openssl_encrypt($row[4], $method, $key, OPENSSL_RAW_DATA, $iv));
                            $encryptedSecretTestKey = base64_encode(openssl_encrypt($row[5], $method, $key, OPENSSL_RAW_DATA, $iv));

                            if( "/" === substr($site->site_url, -1)){
                                $site_url = $site->site_url;
                            }else{
                                $site_url = $site->site_url . '/';
                            }
                            $response = Http::post( $site_url.'wp-json/paidy-receiver/v1/receive/', [
                                'application_id' => $row[0],
                                'paidy_status' => $row[1],
                                'public_live_key' => $encryptedPublicLiveKey,
                                'secret_live_key' => $encryptedSecretLiveKey,
                                'public_test_key' => $encryptedPublicTestKey,
                                'secret_test_key' => $encryptedSecretTestKey,
                                'state' => $application->state,
                                'updated_at' => Carbon::now()->toDateTimeString()
                            ]);

                            if ($response->successful()) {
                                $set_result = DB::table('applications')->where('application_id', $row[0])->update([
                                    'set_status' => 1,
                                    'updated_at' => Carbon::now()
                                ]);

                                $api_success_list[] = [
                                    'application_id' => $row[0],
                                    'site_url' => $site_url,
                                    'status' => $response->status()
                                ];
                            } else {
                                $set_result = DB::table('applications')->where('application_id', $row[0])->update([
                                    'set_status' => 0,
                                    'updated_at' => Carbon::now()
                                ]);

                                $api_error_list[] = [
                                    'application_id' => $row[0],
                                    'site_url' => $site_url,
                                    'error' => 'HTTPステータス: ' . $response->status() . ' - ' . $response->body()
                                ];
                            }
                        } catch (\Exception $e) {
                            $api_error_list[] = [
                                'application_id' => $row[0],
                                'error' => $e->getMessage()
                            ];
                        }
                    }
                }
                // 結果メッセージの生成
                $total_records = count($sheetData) - 1; // ヘッダー行を除く
                $success_count = count($api_success_list);
                $error_count = count($api_error_list);

                if ($success_count > 0 && $error_count == 0) {
                    $result_msg = "データベースに登録し、全てのAPI送信が成功しました。（{$success_count}件）";
                } elseif ($success_count > 0 && $error_count > 0) {
                    $result_msg = "データベースに登録しましたが、一部のAPI送信に失敗しました。（成功: {$success_count}件、失敗: {$error_count}件）";
                } elseif ($success_count == 0 && $error_count > 0) {
                    $result_msg = "データベースに登録しましたが、全てのAPI送信に失敗しました。（{$error_count}件）";
                }

            } else {
                $result_msg = 'データベースへの登録でエラーが発生しました。';
            }
        }else{
            foreach($error_msgs as $key => $msgs){
                $error_msg .= $key.'行目に問題があります。'."\n";
                foreach($msgs as $msg){
                    $error_msg .= '--- '.$msg."\n";
                }
            }
        }
        return view('import-csv.show', compact( 'result_msg', 'error_msg', 'api_success_list', 'api_error_list' ));
    }

    public function show(){
        $result_msg = '';
        $error_msg = '';
        $api_success_list = array();
        $api_error_list = array();
        return view('import-csv.show', compact( 'result_msg', 'error_msg', 'api_success_list', 'api_error_list' ));
    }
}
