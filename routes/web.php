<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('crm', ['page' => 'intake']);
});

Route::get('/lead-intake', function () {
    return view('crm', ['page' => 'intake']);
});

Route::get('/workspace', function () {
    return view('crm', ['page' => 'workspace']);
});

Route::get('/workspace/leads/{lead}', function () {
    return view('crm', ['page' => 'workspace']);
})->whereNumber('lead');
