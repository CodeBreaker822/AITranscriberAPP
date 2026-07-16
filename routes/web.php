<?php

use App\Http\Controllers\AppUpdateController;
use App\Http\Controllers\AudioChunkController;
use App\Http\Controllers\AudioMemoryController;
use App\Http\Controllers\AudioVadLogController;
use App\Http\Controllers\OfflineWhisperModelController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TranscriptFurnishController;
use App\Http\Controllers\TranscriptMemoryController;
use App\Http\Controllers\TranscriptSummaryController;
use App\Http\Controllers\TranscriptionPageController;
use App\Http\Controllers\UploadBackgroundJobController;
use App\Http\Controllers\UploadedAudioTranscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TranscriptionPageController::class, 'live'])->name('transcription.live');
Route::get('/desktop-loading', [TranscriptionPageController::class, 'desktopLoading'])->name('desktop.loading');
Route::get('/desktop-assets-ready', [TranscriptionPageController::class, 'desktopAssetsReady'])->name('desktop.assets-ready');

Route::get('/upload', [TranscriptionPageController::class, 'upload'])->name('transcription.upload');

Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::get('/settings/api-key-help', [SettingsController::class, 'help'])->name('settings.api-key-help');
Route::get('/app-update/connectivity', [AppUpdateController::class, 'connectivity'])->name('app-update.connectivity');
Route::get('/app-update/status', [AppUpdateController::class, 'status'])->name('app-update.status');
Route::get('/app-update/download', [AppUpdateController::class, 'download'])->name('app-update.download');
Route::get('/offline-model/status', [OfflineWhisperModelController::class, 'status'])->name('offline-model.status');
Route::post('/offline-model/download', [OfflineWhisperModelController::class, 'download'])->name('offline-model.download');
Route::post('/settings/audio-memory/temporary', [AudioMemoryController::class, 'clearTemporary'])->name('settings.audio-memory.temporary.clear');
Route::post('/settings/audio-memory/stored', [AudioMemoryController::class, 'clearStored'])->name('settings.audio-memory.stored.clear');
Route::post('/settings/audio-memory/all', [AudioMemoryController::class, 'clearAll'])->name('settings.audio-memory.all.clear');
Route::post('/settings/transcript-memory', [TranscriptMemoryController::class, 'clear'])->name('settings.transcript-memory.clear');
Route::get('/audio-chunks', [AudioChunkController::class, 'index'])->name('audio-chunks.index');
Route::get('/audio-chunks/status', [AudioChunkController::class, 'status'])->name('audio-chunks.status');
Route::post('/audio-chunks', [AudioChunkController::class, 'store'])->name('audio-chunks.store');
Route::post('/audio-chunks/batch', [AudioChunkController::class, 'storeBatch'])->name('audio-chunks.store-batch');
Route::post('/speaker-sessions/release', [AudioChunkController::class, 'releaseSpeakerSession'])->name('speaker-sessions.release');
Route::get('/audio-chunks/{audioChunk}/audio', [AudioChunkController::class, 'audio'])->name('audio-chunks.audio');
Route::delete('/audio-chunks/{audioChunk}', [AudioChunkController::class, 'destroy'])->name('audio-chunks.destroy');
Route::get('/audio-vad-logs', [AudioVadLogController::class, 'index'])->name('audio-vad-logs.index');
Route::post('/audio-uploads', [UploadedAudioTranscriptionController::class, 'store'])->name('audio-uploads.store');
Route::post('/audio-uploads/sections/prepare', [AudioChunkController::class, 'prepareUploadedSection'])->name('audio-uploads.sections.prepare');
Route::post('/audio-uploads/sections/prepare-batch', [AudioChunkController::class, 'prepareUploadedSectionsBatch'])->name('audio-uploads.sections.prepare-batch');
Route::post('/audio-uploads/sections/diarize', [AudioChunkController::class, 'queueUploadedDiarization'])->name('audio-uploads.sections.diarize');
Route::get('/audio-uploads/sessions/status', [AudioChunkController::class, 'uploadSessionStatus'])->name('audio-uploads.sessions.status');
Route::get('/audio-uploads/background-jobs/{job}', [UploadBackgroundJobController::class, 'show'])->name('background-jobs.show');
Route::post('/audio-uploads/background-jobs/{job}/cancel', [UploadBackgroundJobController::class, 'cancel'])->name('background-jobs.cancel');
Route::post('/transcripts/furnish', [TranscriptFurnishController::class, 'store'])->name('transcripts.furnish');
Route::get('/transcripts/summary', [TranscriptSummaryController::class, 'show'])->name('transcripts.summary.show');
Route::post('/transcripts/summary', [TranscriptSummaryController::class, 'store'])->name('transcripts.summary.store');
