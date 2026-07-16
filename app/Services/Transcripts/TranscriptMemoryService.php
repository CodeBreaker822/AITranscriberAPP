<?php

namespace App\Services\Transcripts;

use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;
use App\Models\TranscriptSummary;
use Illuminate\Support\Facades\Schema;

class TranscriptMemoryService
{
    public function snapshot(): array
    {
        $raw = $this->rawTranscriptSnapshot();
        $cleaned = $this->cleanedTranscriptSnapshot();
        $totalBytes = $raw['bytes'] + $cleaned['bytes'];

        return [
            'total' => [
                'bytes' => $totalBytes,
                'formatted_size' => $this->formatBytes($totalBytes),
            ],
            'raw' => array_merge($raw, [
                'formatted_size' => $this->formatBytes($raw['bytes']),
            ]),
            'cleaned' => array_merge($cleaned, [
                'formatted_size' => $this->formatBytes($cleaned['bytes']),
            ]),
        ];
    }

    public function purgeTranscriptText(): array
    {
        $before = $this->snapshot();

        CleanTranscriptChunk::query()->getConnection()->transaction(function (): void {
            if (Schema::hasTable('transcript_summaries')) {
                TranscriptSummary::query()->delete();
            }

            if (Schema::hasTable('clean_transcript_chunks')) {
                CleanTranscriptChunk::query()->delete();
            }

            if (Schema::hasTable('audio_chunks')) {
                AudioChunk::query()->update([
                    'translated_text' => null,
                    'transcription_timestamps' => null,
                    'updated_at' => now(),
                ]);
            }
        });

        return $before['total'];
    }

    private function rawTranscriptSnapshot(): array
    {
        if (! Schema::hasTable('audio_chunks')) {
            return [
                'bytes' => 0,
                'records' => 0,
            ];
        }

        $rows = AudioChunk::query()->get(['translated_text', 'transcription_timestamps']);
        $rowsWithTranscript = $rows->filter(fn (AudioChunk $row): bool => $row->translated_text !== null
            || $row->transcription_timestamps !== null);

        return [
            'bytes' => $rowsWithTranscript->sum(function (AudioChunk $row): int {
                $timestamps = $row->transcription_timestamps;

                return strlen((string) ($row->translated_text ?? ''))
                    + strlen($timestamps === null ? '' : json_encode($timestamps));
            }),
            'records' => $rowsWithTranscript->count(),
        ];
    }

    private function cleanedTranscriptSnapshot(): array
    {
        if (! Schema::hasTable('clean_transcript_chunks')) {
            return [
                'bytes' => 0,
                'records' => 0,
            ];
        }

        $rows = CleanTranscriptChunk::query()->get(['raw_text', 'clean_text', 'clean_timestamps']);

        return [
            'bytes' => $rows->sum(function (CleanTranscriptChunk $row): int {
                $timestamps = $row->clean_timestamps;

                return strlen((string) ($row->raw_text ?? ''))
                    + strlen((string) ($row->clean_text ?? ''))
                    + strlen($timestamps === null ? '' : json_encode($timestamps));
            }),
            'records' => $rows->count(),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes).' B';
    }
}
