<?php

use Illuminate\Support\Facades\Route;

use MultiTenantSaas\Modules\Course\Http\Controllers\CourseController;

// ========== 课程学习模块 ==========

Route::prefix(config('course.route_prefix', ''))->group(function () {

// C 端（具体路由前置，避免被 courses/{id} 拦截）
Route::get('courses/published', [CourseController::class, 'published']);
Route::get('my/courses', [CourseController::class, 'myCourses']);
Route::post('learning-records', [CourseController::class, 'reportProgress']);
Route::get('courses/{id}/detail', [CourseController::class, 'detail']);
Route::post('courses/{id}/purchase', [CourseController::class, 'purchase']);

// Console：课程 CRUD / 上下架
Route::get('courses', [CourseController::class, 'index']);
Route::post('courses', [CourseController::class, 'store']);
Route::get('courses/{id}', [CourseController::class, 'show']);
Route::put('courses/{id}', [CourseController::class, 'update']);
Route::delete('courses/{id}', [CourseController::class, 'destroy']);
Route::patch('courses/{id}/publish', [CourseController::class, 'publish']);
Route::patch('courses/{id}/offline', [CourseController::class, 'offline']);

// Console：章节管理
Route::get('courses/{id}/chapters', [CourseController::class, 'chapters']);
Route::post('courses/{id}/chapters', [CourseController::class, 'storeChapter']);
Route::put('courses/{id}/chapters/{chapterId}', [CourseController::class, 'updateChapter']);
Route::delete('courses/{id}/chapters/{chapterId}', [CourseController::class, 'destroyChapter']);

});
