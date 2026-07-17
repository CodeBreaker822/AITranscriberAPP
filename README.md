# ASTRA

## Meeting Transcription App For Windows

ASTRA helps you turn live sessions and saved recordings into transcripts you can review, clean up, and export.

Use it for meetings, interviews, lectures, calls, reports, minutes, and any recording where you need more than one large block of text.

Current version: `1.0.10`

---

## Download ASTRA

Follow these steps on GitHub:

1. Click the `updates` folder.
2. Open the newest version folder, for example `app-v1.0.10`.
3. Click this installer:

```text
AITranscriber APP_1.0.10_x64-setup.exe
```

4. Click **Download raw file**.
5. Run the downloaded installer.

Do not use GitHub's **Code > Download ZIP** button. That is not the app installer, and Windows will not run it as ASTRA.

After ASTRA is installed, future updates are checked automatically by the app.

## First Setup

1. Open ASTRA.
2. Go to Settings.
3. Enter your transcription server URL and license key.
4. Choose **Live** if you want to record a session.
5. Choose **Upload** if you already have an audio file.
6. Choose **Online** or **Offline** mode.

## What You Can Do

- Record a live session.
- Upload an existing audio file.
- Process long recordings in smaller sections.
- Review transcript sections with audio playback.
- Retry or continue processing when a long job needs attention.
- Use online transcription when you have a server/license available.
- Use offline transcription when you have a local Whisper model installed.
- Add speaker diarization when the local speaker model is available.
- Export the transcript for documentation and review.

## Online Or Offline

**Online mode** uses your configured transcription server. This is the best option when you want hosted transcription, polish, summaries, and provider fallback.

**Offline mode** runs transcription on your computer with a local Whisper model. This is useful for sensitive recordings, poor internet, or local-only work. It can be slower on weaker machines.

## Recommended PC

- Windows 10 or Windows 11, 64-bit.
- 4 logical CPU processors or more.
- 8 GB RAM for online transcription.
- 16 GB RAM recommended for offline transcription, long recordings, or speaker diarization.
- Enough free disk space for recordings, temporary audio files, transcripts, logs, and optional local models.
- Internet access for online transcription, polish, summaries, license checks, and app updates.

The installer includes the app runtime. You do not need to install developer tools just to use ASTRA.

## Notes

ASTRA is a new Windows app and is currently maintained by one developer. If something goes wrong, the most helpful report includes:

- The ASTRA version.
- Your Windows version.
- Whether you used Live or Upload.
- Whether you used Online or Offline mode.
- A screenshot, log, or short description of what happened.

---

ASTRA is built for the practical work after someone says, "Can we get this meeting written down?"

