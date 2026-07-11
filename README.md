# ASTRA — Adaptive Speech Transcription and Recording Assistant

ASTRA is a Windows desktop app for turning live meetings and uploaded recordings into organized transcripts. It supports live recording, long audio uploads, online transcription, offline transcription, local speaker diarization, section playback, logs, and export tools.

**Adaptive Speech Transcription and Recording Assistant.**

## Summary

ASTRA is built for real documentation work, not just quick speech-to-text. It helps users record or upload audio, process long recordings in manageable sections, identify speakers, review the source audio, and export a transcript that can be used for reports, minutes, archives, or follow-up work.

The app has two user workflows:

- **Live** for recording while a meeting or session is happening.
- **Upload** for processing existing audio files such as MP3, AAC, WAV, M4A, OGG, or FLAC.

Each workflow can run in two processing modes:

- **Online** uses the configured transcription server and provider fallback.
- **Offline** uses local Whisper and local diarization models on the desktop machine.

## Why ASTRA Helps

Generic transcription tools usually return one large block of text. ASTRA is designed around review, recovery, and documentation.

- Long recordings are split into time sections, so users can review and retry smaller parts.
- Speaker diarization helps show who spoke during a section.
- Playback stays connected to transcript sections, making verification faster.
- Online mode can use stronger hosted providers when internet is available.
- Offline mode keeps transcription local when privacy or poor connectivity matters.
- Export tools prepare results for office documentation instead of leaving users with a raw text dump.

ASTRA does not remove the need for human review, but it reduces the time spent typing, searching audio, and organizing notes from scratch.

## How The App Is Structured

```text
Desktop UI
    |
    v
Local Laravel backend
    |
    v
Audio preparation
    |-- FFmpeg conversion and section extraction
    `-- Silero VAD speech detection
    |
    v
Transcription
    |-- Online: Transcription Server -> provider fallback
    `-- Offline: local Whisper model
    |
    v
Speaker diarization
    `-- local Sherpa-ONNX worker
    |
    v
Saved transcript sections
    |
    v
Review, playback, polish, summarize, export
```

The important design idea is that audio preparation and speaker diarization are local. Online mode only sends prepared speech audio for transcription. Offline mode keeps the transcription step local too.

## Main Workflows

| Workflow | Best for | What happens |
| --- | --- | --- |
| Live + Online | Meetings with internet access | Records microphone audio, prepares speech locally, sends transcript work to the server, then saves section results. |
| Live + Offline | Live sessions with poor internet or privacy needs | Records microphone audio and transcribes locally with an installed Whisper model. |
| Upload + Online | Long recordings that benefit from hosted models | Splits and prepares audio locally, sends prepared speech sections to the server, then stores results by time range. |
| Upload + Offline | Sensitive or low-connectivity recordings | Splits, prepares, transcribes, and diarizes locally. |

## Live Mode

Live mode is for recording while the session is happening. The app captures microphone audio, detects speech, processes each saved section, and shows transcript entries as they become available.

Users can:

- Watch progress while recording is processed.
- Review transcript sections by time range.
- Replay the original audio for a section.
- See speaker labels when diarization data is available.
- Export saved live transcript entries.

## Upload Mode

Upload mode is for existing recordings. The app divides the file into sections, prepares each section with FFmpeg, uses Silero VAD to focus on speech, then sends the prepared speech to the selected transcription path.

Users can:

- Process long recordings without treating the whole file as one fragile job.
- Continue, retry, cancel, and inspect logs.
- Review raw transcript sections as they finish.
- Replay section audio for verification.
- Export the result after review.

## Online Mode

Online mode uses the transcription server. It is useful when stronger hosted models, provider fallback, or online polish and summary tools are needed.

The server can try configured providers in priority order. If one provider fails, the server can fall back to another provider instead of immediately failing the whole transcript.

## Offline Mode

Offline mode uses installed local models. It is useful when internet access is unreliable, recordings are sensitive, or the user wants local-only transcription.

Offline mode currently focuses on transcription and local speaker diarization. Polish and summarize controls are hidden in offline mode because offline support for those features has not been added yet.

## Processing Components

| Component | Purpose | Runs locally |
| --- | --- | --- |
| Tauri | Windows desktop shell | Yes |
| Laravel | Local backend, routes, state, settings, and processing coordination | Yes |
| FFmpeg | Converts and extracts audio sections | Yes |
| Silero VAD | Detects speech and avoids wasting work on silence | Yes |
| Whisper | Converts speech to text | Online or offline |
| Sherpa-ONNX | Adds speaker diarization labels | Yes |
| Transcription Server | Connects online mode to hosted providers and fallback rules | No |

## Transcript Data

ASTRA stores transcript entries with review-friendly metadata:

- Project or category name
- Time range
- Raw transcript text
- Optional cleaned transcript text
- Timestamps
- Speaker labels when available
- Audio playback reference
- Processing status

This structure is what makes section review, retry, playback, and export possible.

## Output And Export

Users can export transcripts for documentation and archiving. Raw transcript export is available from completed entries. Cleaned export is available after polishing.

Supported export formats include:

- TXT for simple speaker-separated text.
- Excel-compatible export for spreadsheet review.
- Microsoft Word-compatible export for report-style documents.

## Typical User Flow

1. Open ASTRA.
2. Choose Live or Upload.
3. Enter a project name.
4. Choose Online or Offline mode.
5. Record audio or select an existing file.
6. Wait as sections are prepared and transcribed.
7. Review text and replay audio where needed.
8. Polish or summarize online transcripts when needed.
9. Export the final transcript.

## Requirements And Notes

- Offline transcription requires a supported installed Whisper model.
- Speaker diarization requires the Sherpa diarization model.
- Online transcription requires the configured transcription server and provider access.
- Polish and summarize are currently online-only.
- The app is Windows-first and packages its required local runtime pieces with the desktop build.

## Related Repositories

- ASTRA Desktop Application: https://github.com/CodeBreaker822/AILiveTranscriber
- ASTRA Transcription Server: `<server repository URL>`
- Serverless Transcription Worker: https://github.com/CodeBreaker822/ServerlessRunpodTranscript

---

ASTRA helps turn spoken work into searchable, reviewable, and exportable documentation.
