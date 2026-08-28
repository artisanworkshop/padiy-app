<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\BoxController;
use App\Http\Controllers\PaidyConnectController;
use Illuminate\Support\Facades\Route;
use Dcblogdev\Box\Facades\Box;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::group(['middleware' => 'basicauth'], function() {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->middleware(['auth', 'verified'])->name('dashboard');
});

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('import-csv', [CsvImportController::class,'show'])->name('import-csv.show');
    Route::post('import-csv',  [CsvImportController::class,'import']);
    Route::get('application', [ApplicationController::class, 'index'])->name('application.index');
    Route::post('application/{application}/resend', [ApplicationController::class, 'resend'])->name('application.resend');
    Route::get('sftp-upload', [PaidyConnectController::class, 'index'])->name('sftp-upload.index');
});

Route::get( 'box', function() {

    //if no box token exists then redirect
//    Box::getAccessToken();

    //box authenticated now box:: can be used freely.
    $file_id = '1496375257863';
    $filepath = storage_path('box_data').'/woocommerce_merchant_list.xlsx';
    $name = 'woocommerce_merchant_list.xlsx';
    $result = Box::files()->uploadRevision( $file_id, $filepath, $name );

    //example of getting the authenticated users details
//    $entries = json_decode($result['entries'], true, 0 );
    if(isset($result['entries'])){
        $result_msg = 'BOXへの転送に成功しました。';
    }else{
        $result_msg = 'BOXへの転送に失敗しました。';
    }
    $error_msg = '';
    return view('box.index', compact( 'result_msg', 'error_msg' ));
//    return $result['entries'];
});
Route::get('box/oauth', function() {
    return Box::connect();
});


