<?php

use App\Livewire\InvigilatorLookup;
use App\Livewire\StudentExamLookup;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.home')->name('home');

Route::get('/students', StudentExamLookup::class)
    ->name('students.lookup')
    ->middleware(['web', 'throttle:30,1']);

Route::get('/invigilators', InvigilatorLookup::class)
    ->name('invigilators.lookup')
    ->middleware(['web', 'throttle:30,1']);

Route::redirect('/student-exam-lookup', '/students');
