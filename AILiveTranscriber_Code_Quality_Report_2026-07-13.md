# AILiveTranscriber Code Quality Review

**Repository:** `CodeBreaker822/AILiveTranscriber`  
**Branch reviewed:** `main`  
**Commit reviewed:** `8f1b8df8769f4e2d31b9aef1e899579483333ad2`  
**Review date:** July 13, 2026  
**Review scope:** Code Efficiency, Readability, and Re-usability only

## Review Scope and Method

This is a static source-code review of the current Laravel, JavaScript, Tauri/Rust, queue, audio-processing, persistence, configuration, build-script, and test structure.

Generated dependencies, model weights, compiled targets, and bundled runtime binaries were not reviewed as application logic. They were considered only when they affected repository noise, build efficiency, or maintainability.

The application was not executed locally during this review. Findings marked **Confirmed defect** are visible directly in the current source. Findings marked **Structural risk** are patterns that make defects, performance regressions, or inconsistent behavior more likely.

## Current Assessment

| Category | Score | Main reason |
|---|---:|---|
| Code Efficiency | 5.0 / 10 | Several expensive polling, full-scan, process-spawn, and memory-loading paths remain |
| Readability | 4.5 / 10 | The backend has improved, but major orchestration files and frontend state remain difficult to reason about |
| Re-usability | 5.0 / 10 | Useful services exist, but behavior is still coupled through arrays, HTTP responses, filenames, status strings, and duplicated flows |

The project is not beyond repair. The recent split of `AudioChunkController` into dedicated services was a good move. The next improvement should not be another giant rewrite. It should be a sequence of small fixes protected by tests.

# Where to Start

| Order | Priority | Finding | Category |
|---:|---|---|---|
| 1 | P0 | Remove the undefined `$preparedAudioChunkId` reference in the live ingestion path and add a regression test | Readability |
| 2 | P0 | Correct the single-clip batch-duration validation that compares absolute timeline end time against the maximum duration | Code Efficiency |
| 3 | P0 | Stop deleting and scanning no-speech rows inside `GET /audio-chunks` | Code Efficiency |
| 4 | P1 | Split `resources/js/app.js` into shared, live, upload, transcript, progress, storage, and API modules | Readability / Re-usability |
| 5 | P1 | Split `AudioChunkIngestionService` into live, uploaded-section, and uploaded-batch use cases | Readability / Re-usability |
| 6 | P1 | Replace blocking transcription polling and unbounded diarization finalizer polling | Code Efficiency |
| 7 | P1 | Introduce typed request objects, result objects, and status constants/enums | Re-usability |
| 8 | P2 | Add static analysis and JavaScript linting to catch AI-generated impossible branches and undefined variables | Readability |

---

# 1. Code Efficiency

## E-01 — Incorrect duration-limit check can reject valid clips

**Priority:** P0  
**Type:** Confirmed defect  
**File:** `app/Services/HostedTranscriptionApiService.php`  
**Method:** `audioExceedsServerBatchLimit()`

The method currently rejects a clip when:

```php
$clipEndMs > $maxDurationMs
```

`clip_end_ms` is an absolute position on the recording timeline, not the clip duration. A valid one-minute section from `20:00` to `21:00` can be rejected when the server maximum is twenty minutes because its absolute ending position exceeds the limit.

The correct comparison should be based on:

```php
$clipEndMs - $clipStartMs
```

The absolute end position should not be compared to a maximum clip or batch duration.

### Improvement

Keep one duration rule:

```php
$durationMs = max(0, $clipEndMs - $clipStartMs);

return $durationMs > $maxDurationMs;
```

Add tests with:

- Clip `00:00–01:00`
- Clip `20:00–21:00`
- Clip whose actual duration exceeds the limit
- Missing or invalid timing fields

This is high priority because it can produce false “Audio is too big” failures only after users process later sections of a recording.

---

## E-02 — Reading audio chunks performs a destructive full-table cleanup

**Priority:** P0  
**Type:** Confirmed inefficiency and hidden side effect  
**File:** `app/Services/AudioChunk/AudioChunkPersistenceService.php`  
**Methods:** `listRows()`, `deleteNoSpeechRows()`

Every call to `listRows()` first runs `deleteNoSpeechRows()`. That method:

1. Loads every row with non-null transcript text.
2. Hydrates the records as Eloquent models.
3. Filters the text in PHP.
4. Deletes matching database records.
5. Deletes their stored files.

This means a read endpoint is also a cleanup endpoint. The cost grows with transcript history, and frontend polling causes it to run repeatedly.

### Improvement

The preferred fix is to prevent no-speech rows from being stored at all. Your ingestion paths already detect no-speech results, so cleanup should happen before persistence.

For legacy rows, use a one-time migration or an explicit maintenance command. At minimum, use a targeted SQL query rather than loading all transcript rows into PHP.

`GET /audio-chunks` should only read data.

---

## E-03 — Async hosted transcription still blocks the local PHP request

**Priority:** P1  
**Type:** Structural risk  
**File:** `app/Services/HostedTranscriptionApiService.php`  
**Method:** `resolveAsyncTranscriptionPayload()`

The hosted server returns a job ID, but the local request then enters a synchronous loop:

- Request job status
- Sleep for two seconds
- Repeat until completion or timeout
- Potentially remain active for up to the configured transcription timeout

This keeps a Laravel request and PHP worker occupied even though the hosted work is asynchronous.

### Improvement

Use a proper local asynchronous flow:

1. Submit hosted job.
2. Persist the hosted job ID and local chunk/session ID.
3. Return `202 Accepted` to the frontend.
4. Poll through a lightweight status endpoint or use a Laravel queue job.
5. Persist the result when complete.
6. Let the frontend fetch only the affected job/chunk.

This would also make pause, resume, cancellation, and restart recovery easier.

---

## E-04 — Audio files are fully loaded into PHP memory before upload

**Priority:** P1  
**Type:** Structural risk  
**File:** `app/Services/HostedTranscriptionApiService.php`  
**Methods:** `transcribe()`, `transcribeBatch()`

Single and batch transcription use `file_get_contents()` and attach the resulting strings to the HTTP request.

For a batch, all audio contents can remain in memory while the multipart request is assembled. Peak memory rises with the number and size of sections.

### Improvement

Attach stream resources instead:

```php
$stream = fopen($file['path'], 'rb');
```

Use a streaming multipart implementation and close streams in `finally`.

Also enforce the total byte limit before opening all files. Duration limits do not guarantee safe memory usage because audio codec and bitrate affect size.

---

## E-05 — Frontend diarization monitoring repeatedly downloads all stored rows

**Priority:** P1  
**Type:** Structural risk  
**File:** `resources/js/app.js`  
**Function:** `monitorQueuedDiarization()`

The upload page polls every two seconds, potentially for 450 attempts. Each poll calls the general stored-audio endpoint, receives all stored rows, builds a map, and then checks a small set of IDs.

This becomes increasingly inefficient as transcript history grows.

### Improvement

Create a targeted endpoint such as:

```text
GET /audio-chunks/status?ids[]=12&ids[]=13
```

Return only:

```json
[
  {"id": 12, "status": "transcribed"},
  {"id": 13, "status": "diarization_processing"}
]
```

Use increasing backoff after the first few checks. Stop immediately when the session is cancelled or the page changes.

---

## E-06 — One child PHP process is spawned per upload section

**Priority:** P1  
**Type:** Structural risk  
**File:** `app/Services/AudioChunk/UploadedAudioBatchPreparationService.php`

Batch preparation launches one Artisan process for every section and checks running processes with a 40-millisecond polling loop.

The concurrency cap is good, but process startup, Laravel boot, configuration loading, database initialization, temp-directory creation, and JSON encoding are repeated for every clip.

### Improvement

Choose one of these approaches:

- A persistent local worker process that receives multiple section jobs
- Laravel queue jobs with a controlled worker pool
- A single FFmpeg segmentation pass followed by VAD processing
- A reusable process-pool abstraction

Also move process cleanup into `finally` so unexpected non-`RuntimeException` errors cannot leave child processes and temp directories behind.

---

## E-07 — Diarization finalization can self-dispatch indefinitely

**Priority:** P1  
**Type:** Confirmed structural risk  
**File:** `app/Jobs/DiarizeUploadedAudioBatch.php`  
**Method:** `finalizeUploadSessionIfReady()`

When a final upload session is not ready, the job dispatches another empty instance of itself after five seconds. There is no explicit poll count, deadline, or terminal recovery rule.

A stuck status can cause an endless chain of empty queue jobs.

Session completion is also inferred from an `audio_path` prefix instead of a dedicated upload-session relationship.

### Improvement

Store `upload_session_id` directly on the relevant database rows or create an `upload_sessions` table containing:

- `id`
- `status`
- `total_sections`
- `completed_sections`
- `failed_sections`
- `diarization_pending`
- `finalized_at`

Then complete the session when the counter reaches zero. If polling remains necessary, enforce a maximum deadline and mark the session failed or partially complete.

---

## E-08 — Settings repeatedly query both the schema and database

**Priority:** P2  
**Type:** Structural inefficiency  
**File:** `app/Services/AppSettingsService.php`

Each `get()` call checks whether the table exists and then runs a query for one key. Higher-level methods call each other repeatedly, so a single request can perform multiple schema checks, model lookups, JSON decodes, and provider/model normalization passes.

Examples include provider selection, model selection, language selection, batch limits, and resource profiles.

### Improvement

Use request-scoped caching:

```php
private ?bool $tableExists = null;
private array $values = [];
private ?array $decodedLicenseStatus = null;
```

Invalidate the relevant cache entry in `set()`.

For a small settings table, loading all rows once per request may be simpler and faster than querying each key separately.

---

## E-09 — Repository contains generated process artifacts

**Priority:** P2  
**Type:** Repository efficiency issue  
**Paths:** `storage/framework/process-temp/**`, `database/database.sqlite`

The current commit history includes process output, error, and lock files. `.gitignore` does not exclude the full `storage/framework/process-temp` path.

These files make diffs noisy, increase repository size, and make code review harder.

### Improvement

Add:

```gitignore
/storage/framework/process-temp/
/storage/framework/cache/
/storage/framework/sessions/
/storage/framework/testing/
/storage/framework/views/
```

Remove already tracked artifacts with `git rm --cached`.

Keep a prepared SQLite database only when it is intentionally required for installer packaging. If it must remain versioned, document that it is a build input and prevent development data from entering it.

---

# 2. Readability

## R-01 — Undefined variable in the live success path

**Priority:** P0  
**Type:** Confirmed defect  
**File:** `app/Services/AudioChunk/AudioChunkIngestionService.php`  
**Method:** `storeLive()`

After successfully storing live audio, the method contains:

```php
if ($finalizeSession && $preparedAudioChunkId <= 0) {
    $this->speakerDiarization->releaseSession($speakerSessionId);
}
```

`$preparedAudioChunkId` is not defined in `storeLive()`. It belongs to the uploaded-audio path.

This is a classic AI-assisted copy/paste error: the code is syntactically plausible but the variable has no meaning in the current workflow.

### Improvement

The live path likely only needs:

```php
if ($finalizeSession) {
    $this->speakerDiarization->releaseSession($speakerSessionId);
}
```

Confirm the intended worker/session lifecycle, then add a test that executes:

- Speech detected
- Transcription succeeds
- Row is stored
- `finalize_session = true`

Static analysis would also catch this immediately.

---

## R-02 — `resources/js/app.js` is a 4,775-line application in one closure

**Priority:** P1  
**Type:** Major readability risk  
**File:** `resources/js/app.js`

The file handles:

- Export generation
- Tauri integration
- Engine availability
- Offline model events
- Settings controls
- Upload selection
- Upload preparation
- Upload transcription batches
- Pause/resume/cancel/retry
- Live recording
- Live chunk queues
- Local storage
- Playback
- Transcript rendering
- Polishing
- Summary behavior
- VAD log rendering
- Multiple progress systems
- Diarization polling

The upload and live branches each maintain large groups of mutable variables. Understanding one behavior requires tracking state across hundreds or thousands of lines.

### Improvement

Split by responsibility:

```text
resources/js/
  app.js
  shared/
    api-client.js
    audio-player.js
    export-service.js
    formatters.js
    notifications.js
    transcript-normalizer.js
  live/
    live-controller.js
    live-recorder.js
    live-queue.js
    live-state.js
  upload/
    upload-controller.js
    upload-preparation.js
    upload-queue.js
    upload-state.js
  transcript/
    transcript-renderer.js
    polish-controller.js
    diarization-monitor.js
  settings/
    settings-controller.js
```

Each controller should receive dependencies and own one state object.

Do not rewrite everything at once. First extract pure helper functions, then API calls, then stateful workflow sections.

---

## R-03 — `AudioChunkIngestionService` is still a god coordinator

**Priority:** P1  
**Type:** Major readability risk  
**File:** `app/Services/AudioChunk/AudioChunkIngestionService.php`

The service is around 611 lines and receives nine dependencies. It handles:

- Live preparation
- Upload extraction
- VAD
- Transcription
- Diarization
- Persistence
- Cleanup
- Worker release
- Session release
- Batch construction
- HTTP responses
- Logging
- Error translation
- Queue dispatch

The controller became smaller, but much of its complexity moved into this service.

### Improvement

Create three use cases:

```text
LiveAudioIngestion
UploadedSectionIngestion
UploadedBatchIngestion
```

Extract shared lifecycle helpers:

```text
AudioPreparationLifecycle
TranscriptionRequestFactory
SpeakerSessionLifecycle
IngestionErrorMapper
```

Each use case should return a typed result, not a Laravel `JsonResponse`.

---

## R-04 — Array shapes are the main domain model

**Priority:** P1  
**Type:** Structural readability risk

Audio files, prepared clips, sections, transcription results, VAD context, persisted rows, API responses, and diarization data are passed as associative arrays.

The code depends on keys such as:

```text
path
name
size
duration_ms
clip_start_ms
prepared_name
source_name
audio_chunk_id
transcription_timestamps
```

Typos and missing keys are discovered at runtime. IDE navigation is weak, and AI-generated code can invent or reuse keys from another path.

### Improvement

Introduce small immutable objects:

```text
AudioAsset
ClipRange
PreparedAudio
TranscriptionResult
UploadSection
StoredAudioChunkResult
SpeakerSessionId
```

Start with the highest-risk boundaries:

1. Prepared audio returned by `AudioFileChunkerService`
2. Transcription results returned by online/offline engines
3. Upload section payloads
4. Persistence result payloads

Do not create dozens of classes immediately. Four or five high-value DTOs would already remove many array-shape mistakes.

---

## R-05 — Errors are silently swallowed

**Priority:** P1  
**Type:** Debugging/readability risk  
**Files:** `resources/js/app.js`, `UploadedDiarizationService.php`, queue and cleanup code

Examples include empty JavaScript catch blocks and PHP filesystem calls prefixed with `@`.

Silent failure makes the code look successful while state is not actually saved, a sidecar was not written, or a file was not deleted.

### Improvement

Use an intentional policy:

- Expected non-critical failure: log at debug/warning level and continue
- Required operation: throw a domain exception
- Cleanup failure: log the path and operation
- Browser storage failure: expose a non-blocking warning once

Do not leave empty catches. At minimum, name why the failure is safe to ignore.

---

## R-06 — Validation rules are repeated across controller methods

**Priority:** P2  
**Type:** Readability and drift risk  
**File:** `app/Http/Controllers/AudioChunkController.php`

The same fields and regular expressions recur in live, uploaded section, batch, preparation, and diarization requests.

A field can be changed in one endpoint but forgotten in another.

### Improvement

Use Laravel Form Request classes:

```text
StoreLiveAudioChunkRequest
StoreUploadedSectionRequest
PrepareUploadedBatchRequest
StoreUploadedBatchRequest
QueueUploadedDiarizationRequest
```

Share reusable rules through small rule classes or private traits only when the meaning is truly identical.

---

## R-07 — Documentation has drifted behind the implementation

**Priority:** P2  
**Type:** Confirmed readability issue  
**File:** `documentation.md`

The documentation still says there is no dedicated `AudioChunk` model, while the current repository contains `app/Models/AudioChunk.php`.

It also describes assumptions that have changed, including fixed chunk lengths and older controller responsibilities.

### Improvement

Make documentation updates part of the same commit as architecture changes.

A small architecture map should list only:

- Current entry points
- Current use cases/services
- Current queues/workers
- Current persistence model
- Current lifecycle/state transitions

Avoid documenting every private implementation detail because that becomes stale quickly.

---

## R-08 — Tauri `main.rs` has grown into another orchestration hub

**Priority:** P2  
**Type:** Readability risk  
**File:** `src-tauri/src/main.rs`

The file is roughly 1,000 lines and includes:

- Hardware/resource detection
- CLI transcription mode
- Runtime path discovery
- Storage/database bootstrapping
- Environment construction
- Artisan execution
- Port detection and process killing
- Laravel server and queue startup
- HTTP readiness checks
- Error-page rendering
- External URL opening
- Export dialogs
- Tauri setup and shutdown

Three export commands forward the same parameters to the same function.

### Improvement

Split into modules:

```text
runtime_paths.rs
resource_profile.rs
laravel_process.rs
startup_health.rs
exports.rs
external_links.rs
commands.rs
```

Keep `main.rs` limited to application setup and command registration.

Remove duplicate command wrappers unless old command names are intentionally retained for compatibility. If compatibility is required, mark them as aliases in one clear section.

---

## R-09 — Tooling does not currently enforce the intended quality level

**Priority:** P2  
**Type:** Preventive readability issue  
**Files:** `composer.json`, `package.json`

The project includes formatting and PHPUnit, but no visible PHP static-analysis dependency and no JavaScript lint/test script.

Formatting cannot detect:

- Undefined variables
- Impossible branches
- Wrong array keys
- Incorrect return shapes
- Unsafe nullable values
- Duplicate state transitions

### Improvement

Add:

- Larastan/PHPStan
- ESLint
- A small JavaScript test runner for pure modules
- CI commands for formatting, static analysis, frontend linting, and tests

Start PHPStan at a practical level and increase it gradually. Do not block progress by demanding maximum strictness immediately.

---

# 3. Re-usability

## U-01 — Live and upload frontend flows duplicate the same utilities

**Priority:** P1  
**Type:** Confirmed duplication  
**File:** `resources/js/app.js`

Both flows define versions of:

- `formatClock`
- `formatBytes`
- `formatRelativeClock`
- `slugify`
- Transcript usefulness checks
- HTML escaping
- Notification helpers
- Playback state
- Transcript sorting
- Export handling
- Cleaner progress
- API error translation

These implementations can drift. Some already use different names for the same behavior.

### Improvement

Extract pure shared modules first. They are the safest refactor because they do not own workflow state.

Then add shared components:

```text
TranscriptCollection
AudioPlaybackController
ProgressTracker
PollingController
LocalStateStore
```

Live and upload should configure these components rather than copy them.

---

## U-02 — Persistence methods duplicate audio-chunk construction

**Priority:** P1  
**Type:** Confirmed duplication  
**File:** `app/Services/AudioChunk/AudioChunkPersistenceService.php`

`storeTranscribedAudio()` and `storePreparedAudioForDiarization()` build nearly the same `AudioChunk` record. The main differences are transcript fields and status.

### Improvement

Create one private factory method or model factory:

```php
private function createAudioChunk(
    UploadSectionData $section,
    PreparedAudio $audio,
    AudioChunkState $state,
): AudioChunk
```

Then attach stored audio once.

This also gives one location for defaults, casts, filenames, status values, and future columns.

---

## U-03 — Application services return HTTP responses directly

**Priority:** P1  
**Type:** Architectural coupling  
**Files:** `AudioChunkIngestionService.php` and related workflow services

A service that returns `JsonResponse` is difficult to reuse from:

- Queue jobs
- Artisan commands
- Tests
- Desktop commands
- Future API versions
- Scheduled recovery processes

It also mixes domain outcomes with status codes and response formatting.

### Improvement

Return result objects:

```text
SavedAudioChunk
SkippedAudioChunk
FailedAudioChunk
QueuedAudioChunkBatch
```

The controller maps those results to JSON and HTTP status codes.

Exceptions should be domain-specific and mapped centrally through Laravel exception handling.

---

## U-04 — Status values and engine names are repeated strings

**Priority:** P1  
**Type:** Reuse and consistency risk

Examples include:

```text
transcribed
diarization_ready
diarization_queued
diarization_processing
diarization_retrying
diarization_waiting_transcript
diarization_failed
online
offline
Complete
Failed
Cancelled
Polishing
Waiting
```

They are repeated across PHP, JavaScript, database queries, and queue jobs.

### Improvement

Use PHP backed enums for backend state:

```text
AudioChunkStatus
TranscriptionEngine
UploadSessionStatus
```

Expose a stable JSON representation to the frontend.

In JavaScript, use frozen constant maps or a small state-machine module.

Centralized states will reduce spelling drift and make terminal versus retryable states explicit.

---

## U-05 — Hosted API responsibilities should be separated

**Priority:** P1  
**Type:** Reuse limitation  
**File:** `app/Services/HostedTranscriptionApiService.php`

The service handles:

- License status
- Reachability
- Update download
- Single transcription
- Batch transcription
- Async job polling
- Polishing
- URL validation
- Error extraction
- Response normalization

These concerns change for different reasons.

### Improvement

Split into:

```text
HostedApiClient
HostedLicenseClient
HostedTranscriptionClient
HostedTranscriptionJobClient
HostedPolishingClient
HostedUpdateClient
HostedApiErrorMapper
```

They can share one authenticated HTTP transport and one URL builder.

This makes transcription logic reusable without bringing update-download logic into the same class.

---

## U-06 — Persistence receives another service as a method parameter

**Priority:** P2  
**Type:** Coupling smell  
**File:** `AudioChunkPersistenceService.php`  
**Method:** `completePreparedAudioTranscription()`

The method accepts `UploadedDiarizationService` as an argument. This suggests the persistence service needs workflow behavior to decide what to store, while the workflow service already depends on persistence.

### Improvement

Move the merge decision into the ingestion/use-case layer:

1. Ask the diarization service for a merged transcription result.
2. Pass the final result and final status to persistence.
3. Keep persistence unaware of diarization workflow behavior.

Persistence should store state, not coordinate another service.

---

## U-07 — Cleanup and session-release behavior is repeated across branches

**Priority:** P1  
**Type:** Reuse and bug risk  
**File:** `AudioChunkIngestionService.php`

The service repeats:

- Prepared-directory cleanup
- Processed-file cleanup
- Offline-worker release
- Speaker-session release
- Upload-session finalization
- No-speech response construction
- Error response construction

The undefined-variable defect appeared near this lifecycle logic.

### Improvement

Create a scoped lifecycle object or use `try/finally` with an explicit completion state.

Example responsibilities:

```text
AudioWorkScope
  retainForRetry()
  markSuccessful()
  finalizeSession()
  cleanupTemporaryFiles()
  releaseWorkers()
```

This makes cleanup behavior consistent and prevents one branch from forgetting a release or deleting retry data too early.

---

## U-08 — Frontend state should be represented by objects, not dozens of closure variables

**Priority:** P1  
**Type:** Reuse limitation  
**File:** `resources/js/app.js`

The upload and live flows each declare many mutable variables. Functions implicitly read and modify them, making the functions difficult to reuse or test.

### Improvement

Use explicit state:

```js
const uploadState = {
    sessionId: '',
    sections: [],
    status: 'idle',
    activeRequests: new Map(),
    cancelRequested: false,
    pauseRequested: false,
};
```

Prefer reducer-style transitions:

```text
SELECT_FILE
SESSION_CREATED
PREPARATION_STARTED
SECTION_PREPARED
BATCH_STARTED
SECTION_COMPLETED
PAUSED
CANCELLED
FAILED
COMPLETED
```

Rendering should read the state. Network functions should return data. State transitions should be the only code that mutates workflow state.

---

## U-09 — Build/runtime logic needs common abstractions

**Priority:** P2  
**Type:** Reuse limitation  
**Files:** `src-tauri/src/main.rs`, `scripts/*.mjs`

Environment variables, runtime paths, process creation, and packaged versus development path resolution appear across Rust, Node scripts, Laravel configuration, and services.

### Improvement

Define one documented runtime contract:

```text
Project directory
Writable storage directory
SQLite path
Process temp directory
Bundled PHP path
FFmpeg/FFprobe paths
Model directories
CA bundle path
Resource profile
```

Generate or pass this contract consistently rather than recreating path rules independently in each language.

---

# Recommended Implementation Sequence

## Step 1 — Fix confirmed defects before refactoring

1. Remove or correct the undefined `$preparedAudioChunkId` live-path condition.
2. Fix duration validation to compare clip duration, not absolute end time.
3. Remove destructive cleanup from `listRows()`.
4. Add regression tests for all three.
5. Add a test for a successful final live chunk.

Do not begin the large JavaScript split until these are protected.

## Step 2 — Add guardrails for AI-assisted coding

Add Larastan/PHPStan and run it against `app/`.

Initial rules should catch:

- Undefined variables
- Incorrect argument types
- Missing array keys where detectable
- Wrong return types
- Dead or impossible branches

Add ESLint before splitting `app.js`. The refactor will be safer when extracted modules are checked automatically.

## Step 3 — Extract pure reusable code

Start with code that has no side effects:

- Time and byte formatters
- Transcript normalization
- Status predicates
- Export builders
- Batch clip mapping
- API error-message normalization

Pure functions are easy to test and unlikely to break app behavior.

## Step 4 — Separate workflows

Backend:

```text
LiveAudioIngestion
UploadedSectionIngestion
UploadedBatchIngestion
```

Frontend:

```text
live-controller
upload-controller
transcript-controller
```

Keep the old endpoints and UI behavior while moving code.

## Step 5 — Replace polling and hidden coupling

1. Add explicit upload-session persistence.
2. Add targeted chunk/job status endpoints.
3. Replace empty self-dispatched finalizer jobs.
4. Return hosted transcription job IDs immediately.
5. Let a queue worker own long-running status polling.

## Step 6 — Introduce typed boundaries

Introduce DTOs and enums only after the workflows are separated. Otherwise, adding types inside the current giant files can increase code volume without reducing complexity.

# Minimum Regression Tests to Add First

These tests directly protect the highest-risk code:

1. Successful live transcription with `finalize_session = true`
2. Valid late-timeline clip does not fail duration validation
3. Over-limit clip duration is rejected
4. Listing chunks does not delete or mutate records
5. No-speech ingestion does not create an `AudioChunk`
6. Failed live transcription cleans temporary files
7. Failed upload transcription preserves retryable session files
8. Diarization finalization stops after a terminal state
9. Batch response maps results to the correct clip ID
10. Settings are not repeatedly queried within one request

# Final Assessment

Your repository’s biggest problem is not that AI wrote the code. The problem is that several workflows were allowed to grow without strict boundaries or automated checks.

The backend is already moving in a healthier direction. `AudioChunkController` becoming a thin controller is evidence of that. The next target should be the new 611-line ingestion coordinator and the 4,775-line frontend file.

The correct order is:

```text
Confirmed bugs
→ regression tests
→ static analysis
→ pure helper extraction
→ workflow separation
→ polling and persistence redesign
```

Avoid a complete rewrite. The app already contains useful working behavior, and a rewrite would make it harder to distinguish old defects from newly introduced ones.
