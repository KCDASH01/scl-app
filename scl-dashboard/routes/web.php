<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AiConfigController;
use App\Http\Controllers\WhatsAppTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('login',  [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('login', [AdminAuthController::class, 'login'])->name('admin.login.post');

Route::middleware('auth:admin')->group(function () {

    Route::post('logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('company',          [OrganizationController::class, 'show'])->name('company.show');
    Route::post('company/toggle',  [OrganizationController::class, 'toggleActive'])->name('company.toggle');

    Route::get('users',                  [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}',           [UserController::class, 'show'])->name('users.show');
    Route::post('users/{user}/toggle',   [UserController::class, 'toggleActive'])->name('users.toggle');

    Route::get('branches',               [BranchController::class, 'index'])->name('branches.index');
    Route::get('branches/{branch}',      [BranchController::class, 'show'])->name('branches.show');

    Route::get('courses',                [CourseController::class, 'index'])->name('courses.index');
    Route::get('courses/{course}',       [CourseController::class, 'show'])->name('courses.show');

    Route::get('ai-config',                        [AiConfigController::class, 'index'])->name('ai-config.index');
    Route::post('ai-config',                       [AiConfigController::class, 'store'])->name('ai-config.store');
    Route::put('ai-config/{aiConfig}',             [AiConfigController::class, 'update'])->name('ai-config.update');
    Route::delete('ai-config/{aiConfig}',          [AiConfigController::class, 'destroy'])->name('ai-config.destroy');
    Route::post('ai-config/{aiConfig}/activate',   [AiConfigController::class, 'toggleActive'])->name('ai-config.activate');

    Route::get('whatsapp-templates',                                         [WhatsAppTemplateController::class, 'index'])->name('whatsapp-templates.index');
    Route::post('whatsapp-templates',                                        [WhatsAppTemplateController::class, 'store'])->name('whatsapp-templates.store');
    Route::put('whatsapp-templates/{whatsappTemplate}',                      [WhatsAppTemplateController::class, 'update'])->name('whatsapp-templates.update');
    Route::delete('whatsapp-templates/{whatsappTemplate}',                   [WhatsAppTemplateController::class, 'destroy'])->name('whatsapp-templates.destroy');
    Route::post('whatsapp-templates/{whatsappTemplate}/toggle',              [WhatsAppTemplateController::class, 'toggleActive'])->name('whatsapp-templates.toggle');
});
